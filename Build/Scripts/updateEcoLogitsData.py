#!/usr/bin/env python3
"""
Rebuild Resources/Private/Data/EcoLogits/data.json from an EcoLogits checkout.

    git clone https://github.com/genai-impact/ecologits.git /tmp/ecologits
    git -C /tmp/ecologits checkout <commit>
    python3 -I Build/Scripts/updateEcoLogitsData.py /tmp/ecologits

Standard library only. Reads the upstream files, never imports them.
Afterwards check Classes/Footprint/Estimator.php against
ecologits/impacts/llm.py at the same commit; constants there are ported by hand.
"""

import ast
import json
import subprocess
import sys
from pathlib import Path

# EcoLogits providers that Estimator::PROVIDER_MAP maps to, in that order
PROVIDERS = ['openai', 'anthropic', 'google_genai', 'mistralai']
# Besides the provider locations: WOR, the default for local models, and DEU
EXTRA_ZONES = ['WOR', 'DEU']

TARGET = Path(__file__).resolve().parents[2] / 'Resources/Private/Data/EcoLogits/data.json'


def provider_settings(utils_py: Path) -> dict:
    """PROVIDER_CONFIG_MAP from ecologits/tracers/utils.py, parsed with ast."""
    tree = ast.parse(utils_py.read_text())
    config_map = next(
        node.value for node in tree.body
        if isinstance(node, (ast.Assign, ast.AnnAssign))
        and any(isinstance(t, ast.Name) and t.id == 'PROVIDER_CONFIG_MAP'
                for t in (node.targets if isinstance(node, ast.Assign) else [node.target]))
    )

    def value(node):
        if isinstance(node, ast.Call):  # RangeValue(min=…, max=…)
            return {kw.arg: ast.literal_eval(kw.value) for kw in node.keywords}
        return ast.literal_eval(node)

    settings = {}
    for key, call in zip(config_map.keys, config_map.values):
        fields = {kw.arg: value(kw.value) for kw in call.keywords}
        settings[ast.literal_eval(key)] = {
            'location': fields['datacenter_location'],
            'pue': fields['datacenter_pue'],
        }
    return settings


def main() -> int:
    if len(sys.argv) != 2:
        print(__doc__.strip(), file=sys.stderr)
        return 2
    source = Path(sys.argv[1])
    commit, date = subprocess.run(
        ['git', '-C', str(source), 'log', '-1', '--format=%H %cs'],
        check=True, capture_output=True, text=True,
    ).stdout.split()

    upstream = json.loads((source / 'ecologits/data/models.json').read_text())
    mixes = json.loads((source / 'ecologits/data/electricity_mixes.json').read_text())['electricity_mixes']
    all_settings = provider_settings(source / 'ecologits/tracers/utils.py')

    missing = [p for p in PROVIDERS if p not in all_settings]
    if missing:
        print('Providers missing upstream: ' + ', '.join(missing), file=sys.stderr)
        return 1
    providers = {p: all_settings[p] for p in PROVIDERS}

    zones = sorted({s['location'] for s in providers.values()} | set(EXTRA_ZONES))
    gwp = {m['name']: m['gwp'] for m in mixes}
    missing = [z for z in zones if z not in gwp]
    if missing:
        print('Electricity mixes missing upstream: ' + ', '.join(missing), file=sys.stderr)
        return 1

    data = {
        '_source': {
            'project': 'EcoLogits (GenAI Impact)',
            'repository': 'https://github.com/genai-impact/ecologits',
            'commit': commit,
            'date': date,
            'files': [
                'ecologits/data/models.json',
                'ecologits/data/electricity_mixes.json',
                'ecologits/tracers/utils.py',
            ],
            'license': 'MPL-2.0, see LICENSE in this directory',
            'note': 'Subset for the providers AiM maps; values unchanged except unit conversion of electricity mixes to gCO2eq/kWh.'
                    ' Built by Build/Scripts/updateEcoLogitsData.py.',
        },
        'providers': providers,
        # kgCO2eq/kWh upstream, gCO2eq/kWh here
        'electricityMixes': {z: round(gwp[z] * 1000, 10) for z in zones},
        'aliases': [a for a in upstream['aliases'] if a['provider'] in PROVIDERS],
        'models': [m for m in upstream['models'] if m['provider'] in PROVIDERS],
    }
    TARGET.write_text(json.dumps(data, indent=1, ensure_ascii=False))
    print(f'{TARGET}: {len(data["models"])} models, {len(data["aliases"])} aliases, zones {", ".join(zones)}, commit {commit[:7]}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
