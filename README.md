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

Follow these steps in order. Run each command on its own and wait for it to finish before the next one.

### 1. Install the tools

You need **PHP 8.3 or newer** (with the curl, dom and mbstring extensions), **Composer** and **Node.js 20 or newer**.
Check what you have:

```bash
php -v                                # PHP 8.3 or newer
php -m | grep -iE '^(curl|dom|mbstring)$'   # should list all three
composer --version
node -v                               # v20 or newer
```

On Ubuntu, if PHP is missing: `sudo apt install php8.4-cli php8.4-curl php8.4-xml php8.4-mbstring`.

> **Using lerd?** If `which php` prints a path inside `~/.local/share/lerd/`, `php` runs inside a container and
> `php artisan serve` won't be reachable from your browser. Use the system PHP instead (for example
> `/usr/bin/php8.4`) wherever this guide says `php artisan serve`. See [Run it](#run-it).

### 2. Get access to the repository

The repository is **private**, so GitHub asks for a username and password when you clone it.

1. Ask the repository owner (Krunal-gif) to add your GitHub account: on GitHub, **Settings → Collaborators → Add people**.
2. Accept the invite. It arrives by email and at <https://github.com/notifications>.
3. Create a token. GitHub doesn't accept your account password for `git clone`; it needs a token instead:
   1. Log in to <https://github.com> with **your own** account.
   2. Click your profile picture (top right) → **Settings**.
   3. In the left menu, at the bottom: **Developer settings** → **Personal access tokens** → **Tokens (classic)**.
   4. **Generate new token** → **Generate new token (classic)**.
   5. Note: `WebQA clone`. Expiration: 30 or 90 days. Scopes: tick **`repo`** only.
   6. Click **Generate token** and copy it straight away. It starts with `ghp_` and is shown only once.
      Keep it private, like a password.

Use a **classic** token. A fine-grained token can't open a private repository owned by someone else's personal account.

### 3. Clone the project

```bash
git clone https://github.com/Krunal-gif/AI-Web-Quality-Analyzer.git
```

- **Username:** your own GitHub username
- **Password:** paste the token (`Ctrl+Shift+V` in the terminal). Nothing appears while you paste; press Enter.

Run the next steps only if the clone finished without an error.

### 4. Install and configure

```bash
cd AI-Web-Quality-Analyzer
composer install                      # PHP packages → vendor/
npm install                           # JavaScript packages → node_modules/
cp .env.example .env                  # your local settings
php artisan key:generate              # writes APP_KEY into .env
npm run build                         # builds the React UI → public/build/
```

While you work on the UI, run `npm run dev` in a second terminal instead of `npm run build`.

## Run it

```bash
php artisan serve --no-reload         # → http://127.0.0.1:8000
```

On a machine with lerd (see step 1), use the system PHP:

```bash
/usr/bin/php8.4 artisan serve --no-reload
```

Open **http://127.0.0.1:8000** and click **Analyse the demo site**. Stop the server with `Ctrl+C`.

- Port 8000 taken? Add `--port=8080` and open http://127.0.0.1:8080.
- Why `--no-reload`: `.env` sets `PHP_CLI_SERVER_WORKERS=4`, but Laravel only uses it with `--no-reload`. With a single
  worker the server can't answer a request to itself, so analysing the app's own address would hang.
- The server only runs while that terminal is open. Start it again with the same command next time.

### Open it from another computer on your network

Start it on this computer's network address instead of `127.0.0.1`. Find the address with `hostname -I` (the first
one, for example `192.168.1.51`):

```bash
/usr/bin/php8.4 artisan serve --host=192.168.1.51 --port=8000 --no-reload
```

Other computers then open **http://192.168.1.51:8000**. They must be on the same network.

> **Warning:** the app has no login. Anyone who can open that address can analyse folders on this computer and write
> fixes into them. Only do this on a network you trust, never use `--host=0.0.0.0`, and stop the server when you're done.

You can run this next to the normal `127.0.0.1` server; they use different addresses.

## Troubleshooting

| Problem | Cause and fix |
|---|---|
| `git clone` asks for a password, then fails with **403** or "Write access to repository not granted" | The token can't read this repo. Make a new **classic** token with **`repo`** ticked (step 2), and make sure your account was added as a collaborator and you accepted the invite. |
| `git clone` says **Authentication failed** | The token was mistyped or has expired. Copy it again or create a new one. |
| `cd: AI-Web-Quality-Analyzer: No such file or directory`, `composer.json` not found | The clone failed, so the folder doesn't exist. Fix the clone first. |
| `Failed to listen on …:8000 (reason: Address already in use)` | A server is already running on that address. Use it, stop it with `Ctrl+C` in its terminal, or pick another port with `--port=8080`. |
| Browser says the page can't be reached, but the terminal says "Server running" | On a lerd machine, `php` runs in a container. Start the server with `/usr/bin/php8.4` instead. |
| `http://<name>.test` shows **404 page not found** | lerd's web server can't start because another tool (for example Lando or Docker) is using ports 80/443. Use `php artisan serve` as above. |
| Another computer can't open `http://<your-ip>:8000` | Check it's on the same network (its IP should start the same way, e.g. `192.168.1.`) and that `ping <your-ip>` works from it. If the ping fails, the network is blocking it (separate Wi-Fi or VLAN, or client isolation); ask your network admin. |
| The AI pill says **Ollama offline** | Ollama isn't running or no model is installed. See [Connecting Ollama](#connecting-ollama-optional-recommended). The app still works without it. |

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
   **This page only** (default) or **Whole site (sitemap)** (up to 50 pages, max 500). A whole-site scan reads
   `robots.txt` and the sitemap first (`Sitemap:` line, `/sitemap.xml`, `/sitemap_index.xml` or `/wp-sitemap.xml`,
   following sitemap index files), analyses every listed page one by one, then follows links if the limit allows.
   You can also enter a sitemap directly (`https://example.com/sitemap.xml`) to analyse exactly its pages.
   The **Pages** panel lists every page with its issue count; click one to see only that page's issues.
   The **Site profile** panel shows what the site runs on (platform such as WordPress, Shopify or Laravel; server
   language; JavaScript/CSS libraries; web server; CDN) and where it is hosted (country, city and hosting company,
   from the server's IP address via ipwho.is; only the IP is sent; set `WEBQA_GEO_LOOKUP=false` to turn it off).
   Behind a CDN such as Cloudflare, the location is the CDN's server, so the real hosting country is hidden.
1. For websites, the site's own JavaScript and CSS files are downloaded and analysed too (syntax checked with Node
   where installed; minified files only for serious problems such as syntax errors, `debugger`, `eval()` and leaked
   keys), and every script, stylesheet and image the pages use is requested so broken references are reported.
   They are listed under **Site code files** in the Pages panel.
1. **Page previews** above the issue list show a picture of each analysed page's first screen (what a visitor sees
   before scrolling), taken in the background with headless Google Chrome or Chromium (up to 30 pages, about 7 s
   each). Click a picture to see only that page's issues. Set `WEBQA_SCREENSHOTS=false` to turn it off, or
   `WEBQA_CHROME=/path/to/chrome` if the browser isn't found.
1. **Security report ↗** (bottom bar) opens a report with only the Security findings: leaked secrets and `eval()` in
   code, and for websites the security headers (HSTS, Content-Security-Policy, clickjacking protection, nosniff,
   Referrer-Policy, Permissions-Policy), server version disclosure and cookie flags (Secure, HttpOnly, SameSite).
   These are read from the site's normal response; nothing is probed. Cookie values are never shown.
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
in-place mode, write to it. Every POST is protected by Laravel's CSRF token, but the app has no login: keep
`php artisan serve` on its default `127.0.0.1` address unless you need to
[open it from another computer](#open-it-from-another-computer-on-your-network) on a network you trust, and never use
`--host=0.0.0.0`. Only crawl sites you own or are authorised to test.
