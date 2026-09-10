#!/usr/bin/env python3
"""
Renders the documentation into the landing page's own styling, so the site
carries the guides rather than linking into GitHub's markdown view.

The Markdown files under Documentation/ stay the single source: they are
still what GitHub shows, and this only produces a second, nicer rendering
of the same text. Run it with an output directory:

    python3 docs/build.py _site

Needs the `markdown` package. The Pages workflow installs it; locally, a
virtualenv with `pip install markdown` is enough.
"""
import re
import shutil
import sys
from pathlib import Path

import markdown

try:
    from PIL import Image
except ImportError:  # pragma: no cover
    sys.exit("This needs Pillow as well: pip install markdown pillow")

ROOT = Path(__file__).resolve().parent.parent

# The screenshots are 3500 px wide, which no layout here uses. Downscaled once
# and written as lossless WebP: 213 kB instead of 629 kB for the largest, with
# the text still pixel-exact, since the resize is the only step that touches it.
IMAGE_MAX_WIDTH = 1600

# Order matters: it is the sidebar order, and the first entry is the entry point.
PAGES = [
    ('introduction.html', 'Documentation/Introduction.md', 'Introduction'),
    ('provider-configuration.html', 'Documentation/ProviderConfiguration.md', 'Configuring a provider'),
    ('usage.html', 'Documentation/Usage.md', 'Using AiM'),
    ('governance.html', 'Documentation/Governance.md', 'Governance'),
    ('tone-of-voice.html', 'Documentation/ToneOfVoice.md', 'Tone of voice'),
    ('pipeline.html', 'Documentation/Pipeline.md', 'The request pipeline'),
    ('backend-modules.html', 'Documentation/BackendModules.md', 'Backend modules'),
    ('development.html', 'Documentation/Development.md', 'Development'),
    ('changelog.html', 'CHANGELOG.md', 'Release notes'),
]

# Markdown link target -> generated page. Everything else stays untouched.
LINK_MAP = {src.split('/')[-1]: out for out, src, _ in PAGES}
LINK_MAP['README.md'] = '../index.html'

# Placeholders are substituted rather than str.format()ed: the shell carries
# JavaScript, whose braces format() would read as field names.
SHELL = """<!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title} &mdash; AiM</title>
<meta name="description" content="{description}">
<link rel="icon" href="../icon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400..800&family=Manrope:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/site.css">
<script>
(function(){
  try {
    var stored = localStorage.getItem('aim-theme');
    if (stored === 'light' || stored === 'dark') {
      document.documentElement.dataset.theme = stored;
    }
  } catch (e) {}
})();
</script>

<nav class="nav">
  <div class="wrap">
    <a class="brand" href="../index.html"><img src="../icon.svg" alt="">AiM</a>
    <div class="nav-links">
      <a href="../index.html#why">Why</a>
      <a href="../index.html#features">Features</a>
      <a href="../index.html#backend">Backend</a>
      <a href="introduction.html">Docs</a>
    </div>
    <div class="nav-right">
      <button class="theme-toggle" type="button" id="theme" aria-label="Switch to light mode">
        <svg class="sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.6v2.2M12 19.2v2.2M2.6 12h2.2M19.2 12h2.2M5.4 5.4l1.6 1.6M17 17l1.6 1.6M18.6 5.4L17 7M7 17l-1.6 1.6"/></svg>
        <svg class="moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14.3A8.6 8.6 0 1 1 9.7 3.5a6.9 6.9 0 0 0 10.8 10.8z"/></svg>
      </button>
      <a class="nav-cta" href="https://github.com/b13/aim">GitHub</a>
    </div>
  </div>
</nav>

<div class="wrap">
  <div class="doc">
    <aside class="toc">
      <p>Documentation</p>
{nav}
      <hr>
      <a href="https://github.com/b13/aim">Source on GitHub</a>
      <a href="https://github.com/b13/aim/issues">Issues</a>
    </aside>
    <main class="prose">
{content}
      <p class="doc-foot">
        Part of the <a href="../index.html">AiM</a> documentation, generated from
        <a href="https://github.com/b13/aim/blob/main/{source}">{source}</a>.
        Found something wrong? <a href="https://github.com/b13/aim/issues">Open an issue</a>.
      </p>
    </main>
  </div>
</div>

<script>
(function(){

  // Dark is the default and the system setting is not consulted; light is a
  // choice, kept in localStorage. The screenshots exist per scheme and are
  // swapped by name, so only one of the two is ever fetched.
  var root = document.documentElement;
  var toggle = document.getElementById('theme');

  function applyShots(){
    var from = root.dataset.theme === 'light' ? '-dark.webp' : '-light.webp';
    var to = root.dataset.theme === 'light' ? '-light.webp' : '-dark.webp';
    document.querySelectorAll('img[src*="' + from + '"]').forEach(function(img){
      img.src = img.src.replace(from, to);
    });
  }

  if (toggle) {
    toggle.addEventListener('click', function(){
      var next = root.dataset.theme === 'light' ? 'dark' : 'light';
      root.dataset.theme = next;
      try { localStorage.setItem('aim-theme', next); } catch (e) {}
      applyShots();
      label();
    });
  }
  function label(){
    if (!toggle) { return; }
    var next = root.dataset.theme === 'light' ? 'dark' : 'light';
    toggle.setAttribute('aria-label', 'Switch to ' + next + ' mode');
    toggle.title = 'Switch to ' + next + ' mode';
  }

  if (root.dataset.theme === 'light') { applyShots(); }
  label();
})();
</script>
"""


def rewrite_links(html: str) -> str:
    """Point .md links at the generated pages and images at the copied ones."""
    def link(match):
        target = match.group(2)
        anchor = ''
        if '#' in target:
            target, anchor = target.split('#', 1)
            anchor = '#' + anchor
        name = target.split('/')[-1]
        if name in LINK_MAP:
            return f'{match.group(1)}"{LINK_MAP[name]}{anchor}"'
        return match.group(0)

    html = re.sub(r'(href=)"([^"]+\.md(?:#[^"]*)?)"', link, html)
    # Documentation/Images/x.png and Images/x.png both resolve to img/x.png
    html = re.sub(r'(src=)"(?:\.\./)?(?:Documentation/)?Images/([^"]+)\.png"', r'\1"img/\2.webp"', html)
    # Every screenshot exists as a -light/-dark pair, and the site follows the
    # reader's colour scheme, so hand the browser both and let it pick one.
    html = re.sub(
        r'<img([^>]*?)src="img/([^"]+?)-(?:light|dark)\.webp"([^>]*?)/?>',
        lambda m: (f'<img{m.group(1)}src="img/{m.group(2)}-dark.webp" '
                   f'loading="lazy"{m.group(3)}>'),
        html,
    )
    return html


def strip_backlink(html: str) -> str:
    """The rendered pages have a sidebar and a footer; the in-document
    "Back to the README" line is only useful on GitHub."""
    return re.sub(r'<p><a href="[^"]*">Back to the README</a></p>\s*', '', html, count=1)


def lede(html: str) -> str:
    """The first paragraph after the title is the page's standfirst."""
    return re.sub(r'(</h1>\s*)<p>', r'\1<p class="lede-line">', html, count=1)


def prepare_image(name: str, out_dir: Path) -> tuple[str, int, int]:
    """Downscale one screenshot into out_dir and return its name and size."""
    source = ROOT / 'Documentation/Images' / name
    im = Image.open(source).convert('RGB')
    if im.width > IMAGE_MAX_WIDTH:
        height = round(im.height * IMAGE_MAX_WIDTH / im.width)
        im = im.resize((IMAGE_MAX_WIDTH, height), Image.LANCZOS)
    target = Path(name).with_suffix('.webp').name
    im.save(out_dir / target, 'WEBP', lossless=True, method=6)
    return target, im.width, im.height


def build(out_dir: Path) -> None:
    docs_out = out_dir / 'docs'
    (docs_out / 'img').mkdir(parents=True, exist_ok=True)

    # the landing page itself, plus its own assets
    shutil.copy(ROOT / 'docs/index.html', out_dir / 'index.html')
    shutil.copy(ROOT / 'docs/icon.svg', out_dir / 'icon.svg')
    (out_dir / 'assets').mkdir(exist_ok=True)
    shutil.copy(ROOT / 'docs/assets/site.css', out_dir / 'assets/site.css')
    (out_dir / '.nojekyll').touch()

    # the landing page's own four screenshots, in both colour schemes
    (out_dir / 'img').mkdir(exist_ok=True)
    for name in ('request-log', 'request-log-detail', 'providers', 'prompt-preview'):
        for variant in ('light', 'dark'):
            prepare_image(f'{name}-{variant}.png', out_dir / 'img')

    md = markdown.Markdown(extensions=['tables', 'fenced_code', 'sane_lists', 'attr_list'])
    wanted = set()

    for out_name, source, label in PAGES:
        text = (ROOT / source).read_text()
        html = rewrite_links(lede(strip_backlink(md.convert(text))))
        md.reset()
        for name in re.findall(r'src="img/([^"]+?)-dark\.webp"', html):
            wanted.update({f'{name}-dark.webp', f'{name}-light.webp'})

        nav = '\n'.join(
            f'      <a href="{n}"{" aria-current=\"page\"" if n == out_name else ""}>{l}</a>'
            for n, _, l in PAGES
        )
        title = re.search(r'<h1[^>]*>(.*?)</h1>', html, re.S)
        title = re.sub(r'<[^>]+>', '', title.group(1)).strip() if title else label
        first_p = re.search(r'<p class="lede-line">(.*?)</p>', html, re.S)
        description = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', '', first_p.group(1))).strip()[:180] if first_p else title

        page = SHELL
        for key, value in (
            ('{title}', title),
            ('{description}', description.replace('"', '&quot;')),
            ('{nav}', nav),
            ('{content}', html),
            ('{source}', source),
        ):
            page = page.replace(key, value)
        (docs_out / out_name).write_text(page)
        print(f'  {out_name:30s} <- {source}')

    # only what the guides reference, not all twenty screenshots
    for name in sorted(wanted):
        prepare_image(Path(name).with_suffix('.png').name, docs_out / 'img')
    print(f'  {len(wanted)} screenshots downscaled to {IMAGE_MAX_WIDTH}px')


if __name__ == '__main__':
    target = Path(sys.argv[1] if len(sys.argv) > 1 else '_site').resolve()
    print(f'building into {target}')
    build(target)
