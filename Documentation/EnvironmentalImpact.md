# Environmental footprint

How AiM estimates the energy use and CO₂e of each AI request, where the numbers come from, and what they leave out.

[Back to the README](../README.md)

Every text-generating request in the request log gets an estimated energy use (Wh) and CO₂e footprint (grams), or a reason why it has none. The values are shown on the request detail, next to the cost in the Request Log list, in a card in the Request Log statistics, per configuration in the Providers overview, and in the *AI Footprint* dashboard widget.

**These are estimates, not measurements.** None of the hosted providers reports the energy a request used. AiM computes a plausible range from what is known: the number of generated tokens, the size of the model, and the data center the provider runs in. Where that knowledge is missing, AiM shows *No data*, never zero.

## Two methods

| Method | Used for | Input | Result |
|---|---|---|---|
| EcoLogits model (`model_estimate`) | OpenAI, Anthropic, Gemini, Mistral | Output tokens, model size from the EcoLogits data, request duration | A min–max range |
| Configured power draw (`duration_calibrated`) | Any configuration with a power draw set, e.g. Ollama, LM Studio, or an OpenAI-compatible endpoint on your own hardware | Inference time × the power draw set on the configuration | A single value |

A configuration with a power draw always uses that, also for a provider with model data, since it says the model runs on known hardware. Without one, OpenAI, Anthropic, Gemini and Mistral use the EcoLogits method and any other provider has no data.

### EcoLogits model

AiM ports the LLM inference model of [EcoLogits](https://ecologits.ai/latest/methodology/llm_inference/) (GenAI Impact, MPL-2.0) to PHP, and ships a subset of its model and electricity data in `Resources/Private/Data/EcoLogits/`. The data file names the upstream commit it was taken from.

To update the data, check out EcoLogits at the new commit and run `python3 -I Build/Scripts/updateEcoLogitsData.py <checkout>` (standard library only). Then compare `ecologits/impacts/llm.py` at that commit with `Classes/Footprint/Estimator.php`, whose constants are ported by hand.

Per request, with `T` output tokens, `P` active and `P_total` total parameters (billions), and a batch size of 64:

1. Energy of one GPU: `T × (α·e^(β·64)·P + γ)` Wh, with the EcoLogits regression constants.
2. GPUs needed to hold the model: `1.2 × P_total × 2` GB on 80 GB GPUs, rounded up to a power of two.
3. Generation time: `T / tokens-per-second + time to first token` from the EcoLogits data, or a regression on the model size where the data has none; at most the measured request duration.
4. The rest of the server: 1.2 kW for 8 GPUs, for the generation time, shared by the batch.
5. Data center overhead: multiplied by the provider's PUE (OpenAI 1.20, Anthropic 1.09–1.14, Google 1.09, Mistral 1.16).
6. CO₂e: energy × the carbon intensity of the grid in the provider's data center country (USA 384.4 g/kWh, Sweden 35.26 g/kWh for Mistral).

Closed models do not publish their size. EcoLogits assumes a range, for example 8–28 billion parameters for `gpt-4o-mini`, and AiM computes both ends, together with the PUE range where a provider has one. The range is that assumption only, not a confidence interval: the regression, the assumed hardware and batch size, and the uncounted input tokens add uncertainty that no range shows. A model with a published size therefore shows a single value without being more certain. The detail view lists every input and the EcoLogits warnings for the model.

The model is looked up by provider and model name, the way EcoLogits does it. A name with a date suffix (`gpt-4.1-mini-2025-04-14`) falls back to the undated name when it has no entry of its own. A model name on a provider other than its own (an OpenAI model name behind an OpenAI-compatible gateway) does not borrow that provider's data. The other way round, every configuration of the `openai` provider is estimated as OpenAI in a US data center, also when its endpoint is a proxy or a different host; set the grid intensity on the configuration if you know better.

Output tokens are counted the way each provider reports them: OpenAI and Anthropic include reasoning tokens in the output tokens, Gemini reports them separately and AiM adds them, and Mistral reports a single count that AiM takes as it is.

### Configured power draw

For a model running on your own hardware, AiM multiplies the inference time by the power draw you configure:

- **Inference time**: with AiM's native Ollama provider, Ollama's own `total_duration` minus `load_duration`, so loading the model into memory after idling is left out. Through an OpenAI-compatible endpoint (Ollama's `/v1` API, LM Studio) there are no timings, so it is the measured request duration, model loading included.
- **Power draw**: the extra power the machine draws while generating, idle draw subtracted. Set it in the configuration's *Environmental Footprint* section.
- **Grid**: the world average (458.29 g/kWh) unless the configuration sets one. 0 is taken as set, for example for a machine on its own solar power; leave the field empty for the default.

The value is used as entered, without range, so the estimate is exactly as good as the measurement behind it. Two requests running at the same time on one machine are each charged the full power draw, which counts the overlap twice. Without provider timings, the measured request duration includes loading the model after idling, so the first request after a pause is overestimated.

Streamed requests to local models are not estimated yet: their measured duration covers the whole PHP request, not only inference. Streamed requests to hosted providers are estimated when the stream reports its token usage, and show *No data* when it does not.

#### Measuring the power draw on macOS

`powermetrics` ships with macOS and reads the power sensors of Apple silicon. It needs root.

1. Send one request to load the model.
2. Measure idle draw for 30 seconds with the model loaded:
   `sudo powermetrics --samplers cpu_power,gpu_power -i 1000 -n 30`
3. Measure again while requests run back to back, and keep them running for longer than the 30 seconds.
4. Use the average *Combined Power* under load minus the idle average, in watts.

The value covers the chip (CPU, GPU, Neural Engine), not the display, storage or power supply, so it is a lower bound for the whole machine. It also belongs to the workload it was measured with: image descriptions and short text completions draw differently.

On Linux with an NVIDIA GPU, `nvidia-smi --query-gpu=power.draw --format=csv -l 1` gives the GPU share.

## Smart routing

When [smart routing](Pipeline.md) answers a simple prompt with a cheaper model than the one requested, AiM also estimates what the requested model would have used for the same answer, with the EcoLogits method and the output tokens of the answer that was given. The difference is what smart routing avoided.

- The request detail lists the requested model's estimate as *Requested model, without smart routing*, and the difference.
- The Request Log statistics card and the dashboard widget add up what smart routing avoided, as a range, with the number of routed requests it covers: those where both models have an estimate (see below), so it can be lower than the rerouted requests in the log. Where routing added CO₂e, they say so instead.
- Both models have to be known to the EcoLogits data, and neither configuration may set a power draw. A request routed to or away from a local model has no such estimate.
- The requested model would not have written exactly the same answer; taking the given answer's length is the assumption.
- Both sides take their generation time from the EcoLogits data, since the measured one belongs to the model that answered, so the answer's side can differ slightly from the estimate stored for the request. Each side uses the grid of its own configuration.
- The range runs from the requested model's low end minus the answer's high end to the requested model's high end minus the answer's low end. For two models with wide parameter ranges it can span zero.

Smart routing picks the cheaper model by cost, success rate and speed, not by energy. A cheaper model is usually a smaller one, which is why both tend to go down together.

## What is not included

- **Input tokens** (hosted providers): the energy of reading the prompt, and of encoding images in vision requests. EcoLogits counts generated tokens only. For prompt-heavy requests such as image descriptions, translations and page-content prompts this may well be the larger part; we have no data to say how large. The local method does include it, since Ollama's timings cover reading the prompt.
- **Hardware manufacturing** (embodied emissions): not part of the CO₂e figures and totals, so that all of them share the usage-phase boundary. For hosted providers the detail view lists the EcoLogits manufacturing share separately. On US grids it was 2–14 % of the usage-phase CO₂e in our reference cases; on Mistral's Swedish grid it is as large as the usage phase or larger, so Mistral figures understate the total by half or more. For local models it is not estimated.
- **Water use and resource depletion.**
- **Failed requests**, which still use energy.
- **Embeddings, image generation and other request types** without generated text.
- **Requests logged before this feature**, which show *No data*.

## Reading the numbers

- Ranges span a factor of about 1.2 to 2.6 between the models we tested. The upper end is the safer number to quote, and still not an upper bound.
- Totals only add up the estimated requests, so they are a lower bound. Every total says how many requests it covers, for example *132 of 140 requests estimated*; the requests left out (failed, streamed local, embeddings) can be the heavier ones.
- The dashboard widget compares the total with page views of 0.36 g CO₂e each (Sustainable Web Design Model v4), as a sense of scale only: the page view figure includes devices, network and hardware manufacturing, the AI figure does not.
- The estimates of the two methods are not directly comparable, and totals add them up anyway: one models a share of a shared data center server including its idle time, the other is the extra draw of your own chip, idle subtracted, on a different grid.

## Stored values

`tx_aim_request_log` holds `energy_wh_min`, `energy_wh_max`, `co2_g_min`, `co2_g_max` (all `NULL` without an estimate), `impact_method`, and `impact_details`: a JSON record of the inputs (model, parameter range, GPUs, PUE, output tokens, grid intensity, data version), or `{"unavailable": "<reason>"}`. The inputs are kept so an estimate stays explainable after the bundled data changes. Under the *reduced* privacy level the estimate is still computed and stored; it contains no content.

`tx_aim_configuration` holds `power_draw_watts` and `grid_intensity`, both empty by default.

## License of the EcoLogits parts

`Classes/Footprint/Estimator.php` ports `ecologits/impacts/llm.py`, and `Resources/Private/Data/EcoLogits/` holds a subset of the EcoLogits data, both by GenAI Impact under the Mozilla Public License 2.0 (the license text is next to the data). Both are available under MPL-2.0 as well as under the GPL-2.0-or-later of the extension.

## Sources

- EcoLogits methodology: https://ecologits.ai/latest/methodology/llm_inference/
- EcoLogits code and data: https://github.com/genai-impact/ecologits
- Sustainable Web Design Model v4: https://sustainablewebdesign.org/estimating-digital-emissions/
- The approach was prompted by Wagtail's "The carbon footprint of Wagtail AI": https://wagtail.org/blog/the-carbon-footprint-of-wagtail-ai/
