# AI-Web-Quality-Analyzer

A local AI-powered tool that analyses websites, projects and code for SEO, HTML, accessibility, content, syntax
and code issues. It combines static analysis with Ollama/local LLMs to explain issues, suggest fixes and generate
corrected code. Developers review Before & After changes and approve or reject each fix before it is applied.

```
Provide target → Analyse → Report → Detect → Explain why → Show before → Suggest fix
               → Your approval → Apply → Show after (verified by re-scan) → Final report
```

**Stack:** PHP 8.3+ / Laravel 13 (analysis engine + JSON API) · React 19 + Tailwind CSS 4 (Vite) · Ollama (local LLM).
Runs with PHP's built-in server (`php artisan serve`). No database is needed; analyses are stored as JSON files.

## Documentation (PDF)

- [docs/1-Developer-Guide-How-To-Run-And-How-It-Works.pdf](docs/1-Developer-Guide-How-To-Run-And-How-It-Works.pdf): 31 slides covering how to run the tool, how it works (including the AI model and writing single fixes), and what every file does
- [docs/2-Build-Log-Step-By-Step.pdf](docs/2-Build-Log-Step-By-Step.pdf): every step used to build the project, each with its one-line command
- [docs/3-Zero-To-Hero-Learning-Guide.pdf](docs/3-Zero-To-Hero-Learning-Guide.pdf): learning guide from zero: concepts,
  install and run, how to check the analysis, how it works inside, building it yourself, exercises, and every source file
- The PDFs are printed from editable HTML in [docs/source/](docs/source/README.md)

## Setup

You need PHP 8.3+ (with curl, dom, mbstring), Composer and Node.js on your computer.

```bash
git clone https://github.com/Krunal-gif/AI-Web-Quality-Analyzer.git
cd AI-Web-Quality-Analyzer
composer install
cp .env.example .env && php artisan key:generate
npm install && npm run build          # or `npm run dev` in a second terminal while working on the UI
```

## Run it

```bash
php artisan serve                     # → http://127.0.0.1:8000
```

Open **http://127.0.0.1:8000** and click **Analyse the demo site**. Stop the server with `Ctrl+C`.
Use `php artisan serve --port=8080` if port 8000 is taken.
To analyse the app's own address (`http://127.0.0.1:8000`), start it with `php artisan serve --no-reload`:
`.env` sets `PHP_CLI_SERVER_WORKERS=4`, but Laravel only uses it with `--no-reload`. With a single worker the server
can't answer a request to itself while it runs the analysis.

## Landing page

`website/index.html` is a one-page introduction to the tool in an Apple-style scroll format: the product rises out
of a black hero, a statement lights up word by word, a pinned screen zooms into an issue, cards travel sideways,
and the scroll wipes from light to dark mode. Images are real screenshots of the app (`website/images/`).
Open it straight in a browser — no build step:

```bash
xdg-open website/index.html          # macOS: open website/index.html
```

It is plain HTML with Tailwind CSS, GSAP (scroll animations) and Lenis (smooth scrolling), all loaded from a CDN,
so it needs an internet connection. With reduced motion turned on in your system settings, it shows everything
without animation.

## Chrome extension

[chrome-extension/](chrome-extension/README.md) runs the same checks on the page open in Chrome, from a side panel,
with the same scoring, explanations, suggested fixes and optional Ollama review. Load it from `chrome://extensions` →
**Load unpacked**. No build step.

## Connecting Ollama (optional, recommended)

Without Ollama the tool still works: every issue gets a built-in explanation, and many get a rule-based fix.
With Ollama, each issue also gets an AI explanation and AI-corrected code.

```bash
curl -fsSL https://ollama.com/install.sh | sh    # install (Linux; macOS/Windows: download from ollama.com)
ollama pull qwen2.5-coder:3b                     # ~1.9 GB, a good fit for computers without a graphics card
ollama serve                                     # only if it isn't already running as a service
```

The app and Ollama run on the same computer, so the default address just works. Click **↻** in the top-right
pill; the orb turns colourful when the AI is ready, and the dropdown lets you pick any downloaded model.

The defaults are in `.env`:

```dotenv
OLLAMA_HOST=http://127.0.0.1:11434
OLLAMA_MODEL=qwen2.5-coder:7b      # larger and slower on a CPU; set OLLAMA_TIMEOUT=300 if you use it there
OLLAMA_TIMEOUT=180
```

## Using the app

1. Enter a **URL**, a **folder path** or a **file path**, then click **Analyse**. For a URL, choose
   **Whole site (sitemap)** (default, up to 50 pages, max 500) or **This page only**. A whole-site scan reads
   `robots.txt` and the sitemap first (`Sitemap:` line, `/sitemap.xml`, `/sitemap_index.xml` or `/wp-sitemap.xml`,
   following sitemap index files), analyses every listed page one by one, then follows links if the limit allows.
   You can also enter a sitemap directly (`https://example.com/sitemap.xml`) to analyse exactly its pages.
   The **Pages** panel lists every page with its issue count; click one to see only that page's issues.
   The **Site profile** panel shows what the site runs on (platform such as WordPress, Shopify or Laravel; server
   language; JavaScript/CSS libraries; web server; CDN) and where it is hosted (country, city and hosting company,
   from the server's IP address via ipwho.is; only the IP is sent; set `WEBQA_GEO_LOOKUP=false` to turn it off).
   Behind a CDN such as Cloudflare, the location is the CDN's server, so the real hosting country is hidden.
2. Issues are sorted by severity. Click one to see the **Problem**, **Why it matters**, **Suggested fix**, and
   the **Before / After** source with line numbers.
3. **✨ Explain & fix with AI** asks Ollama about that issue. The sidebar's **Explain & fix N with AI** runs a
   batch in the background (you can stop it).
4. For each fix, choose **✓ Approve**, **✕ Reject**, **✎ Edit fix** (write your own version), or switch between
   **Use AI version** and **Use rule version**. The bulk actions apply to whatever the filters currently show.
5. **Write fix to file** (on any issue with a fix, local folders/files only) changes that exact spot in the file
   straight away, backs up the original first and re-scans to confirm. You can keep writing fixes one by one.
6. Or **Apply N approved fixes** in one go:
   - **Prepare patch + fixed copies** (default): your files stay untouched; you get `fixed/…` and `changes.patch`.
   - **Write to project files**: edits in place, with the originals backed up first. Live websites always use
     patch mode.
7. The fixed files are **re-analysed**. Each fix is marked *✓ verified* or *still detected*, and the score
   shows before → after. **Open final report** gives you the self-contained Before & After report, and
   **Share report** sends it as a single .html file (share sheet where supported, otherwise a download).

Reviews are saved automatically. Each analysis has its own URL (`/a/{id}`), so you can reload or come back
later, and recent analyses are listed on the start page. The **×** next to one removes it: its saved report,
patch, fixed copies and backups are deleted (you're warned if it holds backups); your project files are never touched.

## Command line / CI

```bash
php artisan webqa:analyze samples/demo-site
php artisan webqa:analyze https://example.com --pages=10 --ai --min-severity=medium
php artisan webqa:analyze ./theme --approve-rule-fixes --apply=patch --fail-on=high   # exit 1 if high+ remain
```

## What it checks (49 rules)

| Category | Examples | Auto-fix |
|---|---|---|
| SEO | title missing/length, meta description, viewport, canonical, Open Graph, h1 missing/multiple, noindex, robots.txt, sitemap (incl. `wp-sitemap.xml`), HTTP errors, slow response | most |
| Accessibility | img alt, link/button names, form labels, `lang`, iframe title, generic link text, positive tabindex | most (AI improves wording) |
| HTML structure | doctype, charset, duplicate ids, deprecated tags (→ CSS), unclosed/stray tags, heading skips, inline styles, empty links | many |
| Content | lorem ipsum, repeated words, very long sentences, thin content | some (AI rewrites) |
| Code | syntax (JSON with exact position, CSS, JS via `node --check`, PHP via `php -l`), console.log, debugger, `==`, `var`, TODO, empty CSS rules, `!important` overuse | many |
| Security | hard-coded secrets (AWS, Stripe, Google, GitHub, Slack, generic), `eval`, `target=_blank` without noopener, http resources / mixed content, no HTTPS, incomplete certificate chain | some |

Supported files: `.html .htm .css .js .mjs .cjs .json .php .py`. Folder scans skip `node_modules`, `vendor`,
`dist`, `build`, `storage`, `.git` and minified files.

## How AI fixes are kept safe

- The model sees **one issue** with a few lines of context, and may only rewrite the **exact flagged region**.
- Its answer must be JSON and is validated: empty, unchanged, oversized or structurally different output is
  discarded with a warning. Inserted lines are re-indented to match your file.
- AI output is only ever a **proposal**. Nothing is written without your approval.
- Several approved fixes on the same tag (e.g. `aria-label` + `rel`) are merged. Genuinely overlapping edits
  are reported as conflicts and are never applied blindly.
- In-place mode refuses to write a file that changed on disk after the analysis.
- After applying, a **re-scan** confirms each fix really resolved its issue.
- Everything runs locally; code and content never leave your machine.

Local models can take minutes. AI requests therefore return immediately: the work runs in a separate background
process (`php artisan webqa:ai-batch`, started by `AiBatch.php`), and the UI polls for results.

## Project layout

```
app/Analyzer/
  Html/HtmlParser.php   position-aware HTML tokenizer (exact byte offsets for every tag and text run)
  Html/HtmlEdit.php     setAttr / renameElement / humanize / truncate helpers for rule fixes
  Rules/Catalog.php     49 rules: category, severity, why, how (the fallback when AI is off)
  Rules/HtmlRules.php   SEO, accessibility, structure, content, HTML security
  Rules/CodeRules.php   syntax checks, JS/CSS quality, secret detection
  Rules/SiteRules.php   HTTP status, speed, robots.txt, sitemap, HTTPS
  SourceLoader.php      URL (+ sitemap-first site crawl) / folder / file
  Ollama.php            prompts, JSON parsing, output validation
  AiBatch.php           runs AI reviews in the background, one issue at a time
  Fixer.php             apply approved fixes, merge/conflicts, patch, backups, verification
  Diff.php              Myers diff (edit merging + unified patches)
  Analysis.php          analysis state, persisted in storage/app/webqa/analyses/{id}/
app/Http/Controllers/   AnalysisController (analyse, review, apply, reports), AiController
app/Console/Commands/   AnalyzeCommand (php artisan webqa:analyze), AiBatchCommand (background AI worker)
resources/js/           React app: App.jsx + components (TopBar, Sidebar, IssueCard, CodePane, …)
resources/views/        app.blade.php (SPA shell), report.blade.php (Before & After report)
website/                landing page (index.html + images/), independent of the app
docs/                   developer guide + build log (PDF), printed from docs/source/*.html
samples/demo-site/      a small site with ~50 deliberate issues
tests/                  PHPUnit: parser, rules, merging, AI validation, full workflow, HTTP API
```

Each analysis folder holds `report.html`, `report.json`, `changes.patch`, `fixed/` (patch mode) and `backup/`
(in-place mode).

## Tests

```bash
php artisan test
```

## Security note

This is a local developer tool. It can read any folder under your home directory and, when you choose
in-place mode, write to it. Every POST is protected by Laravel's CSRF token, but keep `php artisan serve` on
its default `127.0.0.1` address — don't use `--host=0.0.0.0`. Only crawl sites you own or are authorised to test.
