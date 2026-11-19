# WebQA Chrome extension

The analyser from the web app, as a Chrome side panel. Open any website, click **Analyse**, and WebQA lists its
SEO, accessibility, HTML, content, code and security issues, scores them the same way as the app, and explains each
one with a suggested fix. If Ollama is running, **Ask AI** gets a plain-words explanation and corrected code from your
local model. Nothing leaves your computer, and the extension never changes the page.

No build step and no dependencies: plain HTML, CSS and JavaScript (Manifest V3).

## Install

1. Open `chrome://extensions` and turn on **Developer mode** (top right).
2. Click **Load unpacked** and choose this `chrome-extension` folder.
3. Pin WebQA from the puzzle-piece menu, then click its icon to open the side panel.

To analyse local `file://` pages, open the extension's **Details** and turn on **Allow access to file URLs**.
After editing the code, click ↻ on the extension card in `chrome://extensions`.

## Use

| Step | What happens |
|---|---|
| **Analyse** | `analyzer.js` runs in the open tab and checks the live page, its inline and same-origin scripts, `/robots.txt` and `/sitemap.xml`. |
| **Score** | 0–100 per category and overall, with the same weights as `app/Analyzer/Scoring.php`. Click a category or severity to filter. |
| **Open an issue** | Why it matters, how to fix it, the code before and a rule-based fix where one exists. |
| **Show on page** | Scrolls to the element and outlines it in teal for 2.5 s. |
| **Ask AI** | Sends that one issue to Ollama and shows the model's explanation and corrected code (with Copy). |
| **Download report** | A Markdown file with the scores, every issue and any AI answers. |

Results are kept per tab and cleared when that tab navigates.

## Local AI (optional)

```bash
ollama pull qwen2.5-coder:3b
ollama serve
```

The pill at the top shows **Local AI · Ready** once Ollama answers; click it to check again. Pick the model in the
**Ollama** box, and change the address under **Ollama address** (default `http://127.0.0.1:11434`).

Ollama refuses requests from `chrome-extension://` origins (HTTP 403). The panel handles this itself with a session
`declarativeNetRequest` rule that gives **only this extension's** requests to the Ollama address a local `Origin`, so
you don't need to set `OLLAMA_ORIGINS`. The AI answer is checked like in the app: corrected code that is unchanged or
far larger than the original is dropped.

## Files

| File | Purpose |
|---|---|
| `manifest.json` | Manifest V3: side panel, `scripting`, `storage`, `declarativeNetRequestWithHostAccess`, access to all sites |
| `background.js` | Opens the side panel when the toolbar icon is clicked |
| `analyzer.js` | Injected into the tab: 41 rules on the live DOM, returns the issues |
| `catalog.js` | Rule titles, severities and explanations (generated from `app/Analyzer/Rules/Catalog.php`) plus scoring |
| `panel.html` / `panel.css` / `panel.js` | Side panel UI, Show on page, Ollama calls, report download |
| `icons/` | Toolbar and store icons |

## Differences from the web app

- It checks the page **as the browser rendered it**, so it also works on pages behind a login and on content added by
  JavaScript. It analyses one page at a time, not a folder or a single file on disk.
- It suggests fixes but doesn't write them to files. Copy the corrected code into your project.
- It doesn't run checks that need the raw source or server tools: unclosed or stray tags, JS/PHP/JSON syntax errors,
  CSS rules, `var` and `==`, or the SSL certificate chain. Use the web app for those.
- A rule that matches more than 25 times lists the first 25; the count shows how many repeats were left out.
