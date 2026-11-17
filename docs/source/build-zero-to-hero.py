#!/usr/bin/env python3
"""
Build docs/3-Zero-To-Hero-Learning-Guide.pdf from docs/source/zero-to-hero.html.

What it does:
  1. Code blocks written as  <pre data-file="app/X.php" data-start="text" data-end="line"></pre>
     are filled with the real lines from that file, so the guide never drifts from the code.
     (data-start = first line containing this text; data-end = last line, matched exactly
      after removing the newline; or data-count = number of lines; no start = whole file.)
  2. Code blocks written as  <pre data-lang="bash">raw code</pre>  are syntax-highlighted.
  3. <!--APPENDIX--> is replaced by the complete source of every project file.
  4. Headless Chrome prints the result to PDF.

Run from the project folder:   python3 docs/source/build-zero-to-hero.py
Needs: python3 with pygments (pip install pygments), google-chrome or chromium.
"""
import html
import os
import re
import shutil
import subprocess
import sys
import tempfile

from pygments import highlight
from pygments.formatters import HtmlFormatter
from pygments.lexers import get_lexer_by_name

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
SOURCE = os.path.join(ROOT, 'docs', 'source', 'zero-to-hero.html')
OUTPUT = os.path.join(ROOT, 'docs', '3-Zero-To-Hero-Learning-Guide.pdf')

# ----------------------------------------------------------------------------- appendix contents
# (section title, [(path, one-line purpose), ...]) in the order a learner should read them.
APPENDIX = [
    ('Project setup and Laravel wiring', [
        ('composer.json', 'PHP packages (Laravel 13, PHPUnit) and the PSR-4 autoload rule App\\ → app/.'),
        ('package.json', 'JavaScript packages (React 19, Vite 8, Tailwind 4) and the npm scripts dev / build.'),
        ('vite.config.js', 'Tells Vite to compile app.css + app.jsx with the Laravel, React and Tailwind plugins.'),
        ('.env.example', 'Template for .env: app settings, no database, Ollama address/model, geo lookup.'),
        ('phpunit.xml', 'Test runner settings: the Unit and Feature test folders and the testing environment.'),
        ('.editorconfig', 'Editor formatting rules (indentation, line endings).'),
        ('.gitattributes', 'How git treats line endings and diffs per file type.'),
        ('.gitignore', 'Files git must not track (vendor, node_modules, .env, build output…).'),
        ('.npmrc', 'npm settings.'),
        ('.php-version', 'The PHP version this project expects.'),
        ('artisan', 'The Laravel command-line entry point (php artisan …).'),
        ('public/index.php', 'The web entry point: every browser request starts here.'),
        ('public/.htaccess', 'Apache rewrite rules (send every URL to index.php). Not used by php artisan serve.'),
        ('public/robots.txt', 'Tells search engine crawlers what they may visit.'),
        ('bootstrap/app.php', 'Builds the Laravel application: routes, middleware, and how errors become JSON.'),
        ('bootstrap/providers.php', 'List of service providers (empty here).'),
        ('config/webqa.php', 'This tool\'s own settings, read from .env (Ollama, storage folder, demo path, geo lookup).'),
        ('routes/web.php', 'Every URL of the app: the JSON API, the report downloads and the React pages.'),
    ]),
    ('The analysis engine (app/Analyzer)', [
        ('app/Analyzer/LineIndex.php', 'Converts a byte offset into a line number and back (binary search).'),
        ('app/Analyzer/Document.php', 'One analysed file or fetched page: its text, type, path/URL and encoding.'),
        ('app/Analyzer/Issue.php', 'One detected problem: where it is, how bad, the fix, and its review status.'),
        ('app/Analyzer/Html/Node.php', 'One HTML element with the exact byte positions of its tags.'),
        ('app/Analyzer/Html/TextSeg.php', 'One run of text between tags, with its position and parent element.'),
        ('app/Analyzer/Html/Tree.php', 'The parsed page: all nodes, text segments, doctype and stray tags.'),
        ('app/Analyzer/Html/HtmlParser.php', 'Own, error-tolerant HTML parser that remembers every position.'),
        ('app/Analyzer/Html/HtmlEdit.php', 'Helpers that build fixed HTML (set an attribute, rename a tag, escape…).'),
        ('app/Analyzer/Rules/Catalog.php', 'All 49 rules: category, severity, title, and the built-in why / how text.'),
        ('app/Analyzer/Rules/Make.php', 'Factory that turns a rule id + position into a complete Issue.'),
        ('app/Analyzer/Rules/HtmlRules.php', 'SEO, accessibility, structure, content and HTML security checks.'),
        ('app/Analyzer/Rules/CodeRules.php', 'Syntax checks (JSON, JS, PHP, Python, CSS), code smells and secret detection.'),
        ('app/Analyzer/Rules/SiteRules.php', 'Website-level checks: HTTP errors, speed, HTTPS, robots.txt, sitemap.'),
        ('app/Analyzer/Rules/RuleEngine.php', 'Runs every rule over every document, caps noise, sorts and numbers issues.'),
        ('app/Analyzer/JsonValidator.php', 'Finds the exact line and column of a JSON syntax error.'),
        ('app/Analyzer/SourceLoader.php', 'Reads a folder or file, or downloads a website (robots, sitemap, links).'),
        ('app/Analyzer/SiteProfile.php', 'Detects platform, libraries, server, CDN and hosting location of a website.'),
        ('app/Analyzer/Scoring.php', 'Turns issues into 0–100 scores per category and overall.'),
        ('app/Analyzer/Diff.php', 'Myers diff: merges overlapping fixes and writes unified .patch files.'),
        ('app/Analyzer/Analysis.php', 'One analysis run: create, save/load as JSON with a file lock, review decisions.'),
        ('app/Analyzer/Fixer.php', 'Writes approved fixes (one at a time or all at once), backs up, re-scans to verify.'),
        ('app/Analyzer/Ollama.php', 'Talks to the local AI: builds the prompt, calls the model, validates its answer.'),
        ('app/Analyzer/AiBatch.php', 'Runs the AI over many issues in the background, one by one.'),
        ('app/Analyzer/Settings.php', 'Saves the Ollama host/model chosen in the UI (storage/app/webqa/settings.json).'),
        ('app/Analyzer/ReportView.php', 'Small HTML helpers used by the Before & After report template.'),
    ]),
    ('Laravel layer: controllers and terminal commands', [
        ('app/Http/Controllers/Controller.php', 'Empty base controller (Laravel convention).'),
        ('app/Http/Controllers/AnalysisController.php', 'API: create / list / show / delete analyses, review issues, write and apply fixes, reports.'),
        ('app/Http/Controllers/AiController.php', 'API: AI status, AI settings, start and cancel an AI review.'),
        ('app/Console/Commands/AnalyzeCommand.php', 'php artisan webqa:analyze — the whole workflow from the terminal / CI.'),
        ('app/Console/Commands/AiBatchCommand.php', 'php artisan webqa:ai-batch — background AI worker started by AiBatch.'),
    ]),
    ('Blade views (HTML from the server)', [
        ('resources/views/app.blade.php', 'The one HTML page that loads the React app (with the CSRF token).'),
        ('resources/views/report.blade.php', 'The standalone Before & After report (works offline, can be shared).'),
    ]),
    ('React front end (resources/js, resources/css)', [
        ('resources/js/app.jsx', 'Entry point: mounts <App /> into <div id="app">.'),
        ('resources/js/App.jsx', 'Main component: all app state, every API call, which screen to show.'),
        ('resources/js/lib/api.js', 'api() helper for JSON requests with the CSRF token, shared constants and colours.'),
        ('resources/js/lib/motion.js', 'Animation helpers: View Transitions and a count-up hook.'),
        ('resources/js/components/TopBar.jsx', 'Top bar: target box, AI status / model picker, theme toggle.'),
        ('resources/js/components/Home.jsx', 'Start screen: analyse box, what is checked, recent analyses, how it works.'),
        ('resources/js/components/ScanDemo.jsx', 'Animated demo of a scan on the home page (CSS only).'),
        ('resources/js/components/RecentList.jsx', 'List of recent analyses with remove buttons.'),
        ('resources/js/components/History.jsx', 'The /history page: all saved analyses.'),
        ('resources/js/components/Sidebar.jsx', 'Left column: scores, filters, bulk actions, pages list, site profile.'),
        ('resources/js/components/ScoreRing.jsx', 'Animated circular score.'),
        ('resources/js/components/IssueCard.jsx', 'One issue: explanation, Before / After code, Approve / Reject / Edit / AI.'),
        ('resources/js/components/CodePane.jsx', 'Source code with line numbers and the issue region highlighted.'),
        ('resources/js/components/AiOrb.jsx', 'The small AI status orb.'),
        ('resources/js/components/ApplyBar.jsx', 'Bottom bar: approved / rejected counts, apply mode and Apply button.'),
        ('resources/js/components/Results.jsx', 'After applying: score before → after, verified count, downloads.'),
        ('resources/js/components/Footer.jsx', 'Site footer.'),
        ('resources/css/app.css', 'Tailwind 4 setup, colour tokens, dark mode and animations.'),
    ]),
    ('Automated tests (tests/)', [
        ('tests/TestCase.php', 'Base class for all tests.'),
        ('tests/Unit/AnalyzerTestCase.php', 'Helper base class for engine tests (make a Document, run rules).'),
        ('tests/Unit/HtmlParserTest.php', 'The parser keeps exact offsets, handles broken HTML, never hangs.'),
        ('tests/Unit/RulesTest.php', 'Rules find what they should and ignore what they should.'),
        ('tests/Unit/FixTest.php', 'Merging fixes, conflicts, diff format and AI answer validation.'),
        ('tests/Feature/WorkflowTest.php', 'Full analyse → approve → apply → verify flow on a copy of the demo site.'),
        ('tests/Feature/ApiTest.php', 'Every API endpoint, as the browser would call it.'),
        ('tests/Feature/SitemapTest.php', 'Whole-site scans with a fake website (no internet needed).'),
        ('tests/Feature/SiteProfileTest.php', 'Platform, CDN and hosting detection.'),
    ]),
    ('Demo site (samples/demo-site) — current files', [
        ('samples/demo-site/index.html', 'Demo home page.'),
        ('samples/demo-site/about.html', 'Demo about page.'),
        ('samples/demo-site/css/style.css', 'Demo stylesheet.'),
        ('samples/demo-site/js/main.js', 'Demo script.'),
        ('samples/demo-site/config.json', 'Demo JSON config.'),
    ]),
    ('Original demo site with its deliberate problems (storage/app/demo-site-original)', [
        ('storage/app/demo-site-original/index.html', 'Original home page: missing viewport, alt, labels, duplicate id, lorem ipsum…'),
        ('storage/app/demo-site-original/about.html', 'Original about page.'),
        ('storage/app/demo-site-original/css/style.css', 'Original stylesheet: empty rule, !important overuse…'),
        ('storage/app/demo-site-original/js/main.js', 'Original script: hard-coded API key, eval(), var, ==, console.log…'),
        ('storage/app/demo-site-original/config.json', 'Original JSON with a trailing comma (syntax error).'),
    ]),
    ('Chrome extension (chrome-extension/)', [
        ('chrome-extension/manifest.json', 'Extension manifest (Manifest V3): permissions and side panel.'),
        ('chrome-extension/background.js', 'Opens the side panel when the toolbar icon is clicked.'),
        ('chrome-extension/panel.html', 'Side panel HTML.'),
        ('chrome-extension/panel.css', 'Side panel styles.'),
        ('chrome-extension/panel.js', 'Side panel logic: run the analyser, score, list, highlight, ask Ollama.'),
        ('chrome-extension/analyzer.js', 'Runs inside the page and returns the list of issues (JavaScript port of the rules).'),
        ('chrome-extension/catalog.js', 'Rule catalogue copied from Catalog.php, plus the same scoring.'),
        ('chrome-extension/README.md', 'How to install and use the extension.'),
    ]),
    ('Landing page (website/)', [
        ('website/index.html', 'One-page marketing site with scroll animations (Tailwind, GSAP, Lenis from a CDN).'),
    ]),
]

LEXERS = {
    '.php': 'php', '.js': 'javascript', '.mjs': 'javascript', '.jsx': 'jsx', '.json': 'json', '.css': 'css',
    '.html': 'html', '.xml': 'xml', '.md': 'markdown', '.example': 'bash', '.py': 'python',
}


def lexer_for(path, lang=None):
    name = lang
    if not name:
        base = os.path.basename(path)
        if base.endswith('.blade.php'):
            name = 'html'
        elif base == 'artisan':
            name = 'php'
        elif base == 'phpunit.xml':
            name = 'xml'
        else:
            name = LEXERS.get(os.path.splitext(base)[1], 'text')
    if name == 'php':
        return get_lexer_by_name('php', startinline=True)
    try:
        return get_lexer_by_name(name)
    except Exception:
        return get_lexer_by_name('javascript' if name == 'jsx' else 'text')


def code_html(code, lexer, first_line=1, numbered=True, caption=None):
    """Highlighted code; each line its own block so long lines wrap under their own number."""
    code = code.expandtabs(4)
    body = highlight(code, lexer, HtmlFormatter(nowrap=True))
    lines = body.rstrip('\n').split('\n')
    cls = 'code numbered' if numbered else 'code'
    rows = ''.join(f'<span class="ln">{line or " "}</span>' for line in lines)
    cap = f'<div class="cap">{html.escape(caption)}</div>' if caption else ''
    style = f' style="counter-reset: ln {first_line - 1}"' if numbered else ''
    return f'<figure class="snippet">{cap}<pre class="{cls}"{style}>{rows}</pre></figure>'


def read(rel):
    with open(os.path.join(ROOT, rel), encoding='utf-8', errors='replace') as f:
        return f.read()


def attr(tag, name):
    m = re.search(rf'{name}="([^"]*)"', tag)
    return html.unescape(m.group(1)) if m else None


def file_snippet(match):
    tag = match.group(1)
    rel = attr(tag, 'data-file')
    lines = read(rel).split('\n')
    start_text, end_text, count = attr(tag, 'data-start'), attr(tag, 'data-end'), attr(tag, 'data-count')
    first = 0
    if start_text:
        hits = [i for i, l in enumerate(lines) if start_text in l]
        if not hits:
            sys.exit(f'ERROR: "{start_text}" not found in {rel}')
        first = hits[0]
    last = len(lines) - 1
    if end_text is not None:
        hits = [i for i in range(first, len(lines)) if lines[i] == end_text]
        if not hits:
            sys.exit(f'ERROR: end line "{end_text}" not found in {rel} after line {first + 1}')
        last = hits[0]
    elif count:
        last = min(first + int(count) - 1, len(lines) - 1)
    while last > first and lines[last].strip() == '':
        last -= 1
    code = '\n'.join(lines[first:last + 1])
    caption = attr(tag, 'data-caption') or f'{rel}  ·  lines {first + 1}–{last + 1}'
    return code_html(code, lexer_for(rel, attr(tag, 'data-lang')), first + 1, True, caption)


def raw_snippet(match):
    tag, code = match.group(1), html.unescape(match.group(2))
    code = code.strip('\n')
    lang = attr(tag, 'data-lang') or 'text'
    caption = attr(tag, 'data-caption')
    return code_html(code, lexer_for('', lang), 1, False, caption)


def appendix():
    parts = ['<section class="part appendix" id="appendix-b"><div class="part-label">Appendix B</div>'
             '<h1>Every source file, complete</h1>'
             '<p class="lead">All code of the project, in the order you should read it. Line numbers match the real files, '
             'so <code>Issue.php:42</code> in an error message is line 42 here. Long lines wrap under their own number. '
             'Not included: <code>vendor/</code> and <code>node_modules/</code> (downloaded packages), lock files, '
             'images, built files in <code>public/build/</code>, <code>bootstrap/cache/</code>, and the HTML sources of the PDF guides.</p>']
    toc = ['<ol class="appendix-toc">']
    body = []
    total_files = total_lines = 0
    for s, (title, files) in enumerate(APPENDIX, 1):
        letter = f'B{s}'
        toc.append(f'<li><b>{letter}</b> {html.escape(title)} <span>({len(files)} files)</span></li>')
        body.append(f'<h2 class="app-section">{letter} · {html.escape(title)}</h2>')
        for n, (rel, purpose) in enumerate(files, 1):
            path = os.path.join(ROOT, rel)
            if not os.path.isfile(path):
                print(f'warning: {rel} not found, skipped', file=sys.stderr)
                continue
            code = read(rel).rstrip('\n')
            count = code.count('\n') + 1
            total_files += 1
            total_lines += count
            body.append(f'<div class="file-head"><h3>{letter}.{n} &nbsp;{html.escape(rel)}</h3>'
                        f'<span class="meta">{count} lines</span><p>{html.escape(purpose)}</p></div>')
            body.append(code_html(code, lexer_for(rel), 1, True))
    toc.append('</ol>')
    parts.append(f'<p>{total_files} files · {total_lines:,} lines.</p>')
    return '\n'.join(parts + toc + body) + '</section>'


def filemap():
    out = []
    for s, (title, files) in enumerate(APPENDIX, 1):
        out.append(f'<h3>B{s} · {html.escape(title)}</h3><table class="filemap"><tr><th>File</th><th>Lines</th><th>What it does</th></tr>')
        for rel, purpose in files:
            path = os.path.join(ROOT, rel)
            n = read(rel).rstrip('\n').count('\n') + 1 if os.path.isfile(path) else 0
            out.append(f'<tr><td><code>{html.escape(rel)}</code></td><td>{n}</td><td>{html.escape(purpose)}</td></tr>')
        out.append('</table>')
    return '\n'.join(out)


def find_chrome():
    for name in ('google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'):
        if shutil.which(name):
            return shutil.which(name)
    sys.exit('ERROR: Chrome or Chromium is needed to print the PDF.')


def main():
    with open(SOURCE, encoding='utf-8') as f:
        doc = f.read()
    doc = re.sub(r'<pre(\s[^>]*data-file="[^"]*"[^>]*)>\s*</pre>', file_snippet, doc)
    doc = re.sub(r'<pre(\s[^>]*data-lang="[^"]*"[^>]*)>(.*?)</pre>', raw_snippet, doc, flags=re.S)
    doc = doc.replace('<!--PYGMENTS-->', HtmlFormatter(style='friendly').get_style_defs('.code'))
    doc = doc.replace('<!--FILEMAP-->', filemap())
    doc = doc.replace('<!--APPENDIX-->', appendix())

    tmp = tempfile.mkdtemp(prefix='webqa-guide-')
    full = os.path.join(tmp, 'zero-to-hero.full.html')
    with open(full, 'w', encoding='utf-8') as f:
        f.write(doc)
    subprocess.run([find_chrome(), '--headless=new', '--no-pdf-header-footer', '--disable-gpu',
                    '--virtual-time-budget=5000', f'--print-to-pdf={OUTPUT}', f'file://{full}'],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    shutil.rmtree(tmp, ignore_errors=True)
    print(f'Written: {OUTPUT}')


if __name__ == '__main__':
    main()
