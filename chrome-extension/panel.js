// Side panel: runs analyzer.js in the active tab, scores and lists the issues, highlights them on the
// page and asks the local Ollama model to explain each one and write corrected code.
import { CATEGORIES, RULES, SEVERITIES, score } from './catalog.js';

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
const RANK = Object.fromEntries(SEVERITIES.map((s, i) => [s, i]));

// Ollama settings from chrome.storage; results are kept per tab until that tab navigates.
const state = { tabId: null, url: '', busy: false, results: new Map(), filter: { cat: null, sev: null }, open: new Set(), host: 'http://127.0.0.1:11434', model: '' };
const INTRO = $('status').innerHTML;

function toast(text) {
    const t = Object.assign(document.createElement('div'), { className: 'toast', role: 'status', textContent: text });
    document.body.append(t);
    setTimeout(() => t.remove(), 3500);
}

const SYSTEM_PROMPT = `You are a senior web engineer and an expert in technical SEO, WCAG 2.2 accessibility,
HTML, CSS, JavaScript, PHP and web security. You review one detected issue at a time and answer ONLY
with a JSON object. Be concrete and practical, never generic. Never invent facts about the business;
when real content is needed (e.g. a page title or alt text), infer it from the surrounding code and
keep it neutral.`;

/* ---------------- the tab being analysed ---------------- */

async function currentTab() {
    const forced = Number(new URLSearchParams(location.search).get('tab'));   // panel.html?tab=ID, for testing in a normal tab
    if (forced) return chrome.tabs.get(forced);
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    return tab;
}

async function syncTab() {
    const tab = await currentTab();
    if (!tab) return;
    state.tabId = tab.id;
    state.url = tab.url ?? '';
    $('url').textContent = state.url || '(no address)';
    $('url').title = state.url;
    render();
}

function analysable(url) {
    return /^(https?|file):/.test(url) && !/^https:\/\/(chromewebstore\.google\.com|chrome\.google\.com\/webstore)/.test(url);
}

async function analyse() {
    if (!analysable(state.url)) return showStatus('This page can’t be analysed', 'Chrome doesn’t let extensions read its own pages (chrome://, the Web Store, new tabs). Open a website and try again.', true);
    const tabId = state.tabId;
    state.busy = $('analyse').disabled = true;
    showStatus('Analysing…', `Reading ${state.url}`, false, true);
    try {
        const [{ result }] = await chrome.scripting.executeScript({ target: { tabId }, files: ['analyzer.js'] });
        if (!result) throw new Error('The page returned no result. Reload it and try again.');
        result.issues = result.issues
            .map((i, n) => ({ ...RULES[i.rule], ...i, severity: i.severity ?? RULES[i.rule].severity, id: n }))
            .sort((a, b) => RANK[a.severity] - RANK[b.severity] || CATEGORIES.indexOf(a.category) - CATEGORIES.indexOf(b.category));
        result.score = score(result.issues);
        result.ai = {};
        state.results.set(tabId, result);
        state.filter = { cat: null, sev: null };
        state.open.clear();
    } catch (e) {
        const msg = /file:/.test(state.url) && /permission|Cannot access/i.test(e.message)
            ? 'Turn on “Allow access to file URLs” for WebQA in chrome://extensions, then try again.'
            : e.message;
        showStatus('Analysis failed', msg, true);
        return;
    } finally {
        state.busy = $('analyse').disabled = false;
    }
    render();
}

/* ---------------- rendering ---------------- */

function showStatus(title, text, error = false, busy = false) {
    $('results').hidden = true;
    $('status').hidden = false;
    $('status').className = `status${error ? ' error' : ''}`;
    $('status').innerHTML = `${busy ? '<span class="spinner" aria-hidden="true"></span>' : ''}<h2>${esc(title)}</h2><p>${esc(text)}</p>`;
}

function render() {
    const r = state.results.get(state.tabId);
    if (!r) {
        if (!state.busy) {
            $('results').hidden = true;
            $('status').hidden = false;
            $('status').className = 'status';
            $('status').innerHTML = INTRO;
        }
        return;
    }
    $('status').hidden = true;
    $('results').hidden = false;

    const s = r.score;
    $('score').textContent = s.overall;
    $('score').className = tone(s.overall);
    const arc = $('ring-arc');
    arc.style.strokeDashoffset = 264 * (1 - s.overall / 100);
    arc.setAttribute('class', tone(s.overall));

    const byCat = (c) => r.issues.filter((i) => i.category === c).length;
    $('cats').innerHTML = CATEGORIES.map((c) => `
        <button class="cat${state.filter.cat === c ? ' on' : ''}" data-cat="${c}" aria-pressed="${state.filter.cat === c}">
            <span>${c} <small>${byCat(c)}</small></span><b>${s.categories[c]}</b>
            <i class="bar"><i class="${tone(s.categories[c])}" style="width:${s.categories[c]}%"></i></i>
        </button>`).join('');

    $('sevs').innerHTML = SEVERITIES.map((v) => {
        const n = r.issues.filter((i) => i.severity === v).length;
        return n ? `<button class="chip sev-${v}${state.filter.sev === v ? ' on' : ''}" data-sev="${v}" aria-pressed="${state.filter.sev === v}"><i></i>${v} <small>${n}</small></button>` : '';
    }).join('');

    const list = r.issues.filter((i) => (!state.filter.cat || i.category === state.filter.cat) && (!state.filter.sev || i.severity === state.filter.sev));
    const more = Object.values(r.hidden).reduce((a, b) => a + b, 0);
    $('count').textContent = `${list.length} of ${r.issues.length} issues${more ? ` (+${more} repeats not listed)` : ''}`;
    $('issues').innerHTML = list.length ? list.map((i) => issueHtml(i, r.ai[i.id])).join('') : '<li class="empty">No issues match this filter.</li>';
}

const tone = (v) => (v >= 85 ? 'good' : v >= 60 ? 'warn' : 'bad');

function codeBlock(label, code, cls = '') {
    return code ? `<div class="code ${cls}"><span>${label}<button class="link" data-copy>Copy</button></span><pre>${esc(code)}</pre></div>` : '';
}

function issueHtml(i, ai) {
    const open = state.open.has(i.id);
    return `<li class="issue sev-${i.severity}${open ? ' open' : ''}">
        <button class="issue-head" data-toggle="${i.id}" aria-expanded="${open}">
            <span class="badge">${i.severity}</span>
            <span class="t"><b>${esc(i.title)}</b><small>${esc(i.message)}</small></span>
        </button>
        ${open ? `<div class="issue-body">
            <p class="meta">${esc(i.category)}${i.where ? ` · ${esc(i.where)}` : ''}</p>
            <h3>Why it matters</h3><p>${esc(i.why)}</p>
            <h3>How to fix</h3><p>${esc(i.how)}</p>
            ${codeBlock('Before', i.code, 'before')}
            ${codeBlock(i.code ? 'Suggested fix' : 'Add this', i.fix, 'after')}
            ${aiHtml(ai)}
            <div class="actions">
                ${i.selector ? `<button data-locate="${i.id}">Show on page</button>` : ''}
                <button class="ai-btn" data-ai="${i.id}" ${ai?.busy ? 'disabled' : ''}>${ai?.busy ? 'Thinking…' : ai && !ai.error ? 'Ask AI again' : 'Ask AI'}</button>
            </div>
        </div>` : ''}
    </li>`;
}

function aiHtml(ai) {
    if (!ai || ai.busy) return ai?.busy ? '<div class="ai"><p class="muted"><span class="spinner small"></span> The model is reading the issue…</p></div>' : '';
    if (ai.error) return `<div class="ai error"><p>${esc(ai.error)}</p></div>`;
    return `<div class="ai">
        <p class="ai-title">AI review <small>${esc(ai.model)} · ${ai.seconds}s · confidence ${esc(ai.confidence || '?')}</small></p>
        ${ai.why ? `<p><b>Why:</b> ${esc(ai.why)}</p>` : ''}
        ${ai.fix ? `<p><b>Fix:</b> ${esc(ai.fix)}</p>` : ''}
        ${codeBlock('AI-corrected code', ai.corrected, 'after')}
        ${ai.warning ? `<p class="muted">${esc(ai.warning)}</p>` : ''}
    </div>`;
}

/* ---------------- actions on an issue ---------------- */

async function locate(issue) {
    const [{ result }] = await chrome.scripting.executeScript({
        target: { tabId: state.tabId },
        args: [issue.selector],
        func: (sel) => {
            const el = document.querySelector(sel);
            if (!el) return 'The element is no longer on the page.';
            const r = el.getBoundingClientRect();
            if (!r.width && !r.height) return 'This element is not visible on the page (it may be in <head> or hidden).';
            el.scrollIntoView({ block: 'center', behavior: 'smooth' });
            const { outline, outlineOffset } = el.style;
            el.style.outline = '3px solid #009b84';
            el.style.outlineOffset = '3px';
            setTimeout(() => Object.assign(el.style, { outline, outlineOffset }), 2500);
            return '';
        },
    });
    if (result) toast(result);
}

/** Ollama answers 403 to chrome-extension:// origins, so this extension's own requests to it get a local Origin. */
async function allowOllama() {
    const origin = new URL(state.host).origin;
    await chrome.declarativeNetRequest.updateSessionRules({
        removeRuleIds: [1],
        addRules: [{
            id: 1,
            action: { type: 'modifyHeaders', requestHeaders: [{ header: 'Origin', operation: 'set', value: origin }] },
            condition: { urlFilter: `|${origin}/`, initiatorDomains: [chrome.runtime.id], resourceTypes: ['xmlhttprequest'] },
        }],
    });
}

async function aiStatus() {
    const pill = $('ai-pill');
    const select = $('model');
    pill.className = 'pill';
    pill.querySelector('span').textContent = 'Checking…';
    try {
        await allowOllama();
        const res = await fetch(`${state.host}/api/tags`, { signal: AbortSignal.timeout(4000) });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const models = (await res.json()).models.map((m) => m.name).sort();
        if (!models.includes(state.model)) state.model = models.find((m) => m.startsWith('qwen2.5-coder')) ?? models[0] ?? '';
        select.innerHTML = models.length ? models.map((m) => `<option${m === state.model ? ' selected' : ''}>${esc(m)}</option>`).join('') : '<option>no models pulled</option>';
        select.disabled = !models.length;
        pill.className = `pill ${models.length ? 'ready' : 'warn'}`;
        pill.querySelector('span').textContent = models.length ? 'Local AI · Ready' : 'No model pulled';
        pill.title = models.length ? `${state.host} · click to re-check` : 'Run: ollama pull qwen2.5-coder:3b';
    } catch (e) {
        select.innerHTML = '<option>offline</option>';
        select.disabled = true;
        pill.className = 'pill off';
        pill.querySelector('span').textContent = 'Local AI · Offline';
        pill.title = `Cannot reach Ollama at ${state.host} (${e.message}). Start it with: ollama serve`;
    }
}

function buildPrompt(i, url) {
    return [
        `Issue: ${i.title} (rule ${i.rule}, category ${i.category}, severity ${i.severity})`,
        `Details: ${i.message}`,
        `Page: ${url}`,
        i.where && `Location: ${i.where}`,
        `Built-in explanation: ${i.why}`,
        i.code && `CODE — the exact original code:\n<<<\n${i.code}\n>>>`,
        i.fix && `Rule-based suggestion:\n${i.fix}`,
        '',
        'Answer with JSON only: {"why": "1–2 sentences on why this matters for this page", "fix": "concrete steps to fix it",',
        ' "corrected": "the full corrected replacement for CODE, keeping its formatting; or the exact code to add if something is missing; or \\"\\" if no code change applies",',
        ' "confidence": "high | medium | low"}',
    ].filter((l) => l !== undefined && l !== null && l !== false).join('\n');
}

async function askAi(issue) {
    const r = state.results.get(state.tabId);
    if (!state.model) {
        r.ai[issue.id] = { error: 'Ollama is not running or has no model. Start it with `ollama serve`, run `ollama pull qwen2.5-coder:3b`, then click the status pill.' };
        return render();
    }
    r.ai[issue.id] = { busy: true };
    render();
    const started = performance.now();
    try {
        await allowOllama();
        const res = await fetch(`${state.host}/api/chat`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                model: state.model, stream: false, format: 'json',
                options: { temperature: 0.15, num_ctx: 8192 },
                messages: [{ role: 'system', content: SYSTEM_PROMPT }, { role: 'user', content: buildPrompt(issue, r.url) }],
            }),
            signal: AbortSignal.timeout(180_000),
        });
        const body = await res.json().catch(() => ({}));
        if (!res.ok || body.error) throw new Error(body.error || `Ollama returned HTTP ${res.status}`);
        const text = body.message?.content ?? '';
        const answer = JSON.parse(text.match(/\{[\s\S]*\}/)?.[0] ?? 'null');
        if (!answer || typeof answer !== 'object') throw new Error('The model did not return valid JSON. Try again or pick a larger model.');

        // guard rails, as in the web app: drop corrected code that is empty, unchanged or far too large
        let corrected = typeof answer.corrected === 'string' ? answer.corrected.trim() : '';
        let warning = '';
        if (corrected && issue.code && corrected === issue.code.trim()) [corrected, warning] = ['', 'The AI returned the code unchanged, so it was dropped.'];
        if (corrected && issue.code && corrected.length > issue.code.length * 4 + 400) [corrected, warning] = ['', 'The AI answer was far larger than the original code, so it was dropped.'];
        r.ai[issue.id] = {
            why: String(answer.why ?? '').trim(), fix: String(answer.fix ?? '').trim(), corrected, warning,
            confidence: String(answer.confidence ?? '').toLowerCase().slice(0, 10), model: state.model,
            seconds: ((performance.now() - started) / 1000).toFixed(1),
        };
    } catch (e) {
        r.ai[issue.id] = { error: e.name === 'TimeoutError' ? 'Ollama took longer than 3 minutes. Try a smaller model.' : e.message };
    }
    render();
}

function downloadReport() {
    const r = state.results.get(state.tabId);
    const lines = [
        `# WebQA report: ${r.url}`, '', `Analysed ${new Date().toLocaleString()} · score **${r.score.overall}/100** · ${r.issues.length} issues`, '',
        '| Category | Score | Issues |', '|---|---|---|',
        ...CATEGORIES.map((c) => `| ${c} | ${r.score.categories[c]} | ${r.issues.filter((i) => i.category === c).length} |`), '',
    ];
    for (const i of r.issues) {
        const ai = r.ai[i.id];
        lines.push(`## [${i.severity}] ${i.title}`, '', `${i.category}${i.where ? ` · ${i.where}` : ''} — ${i.message}`, '', `**Why:** ${i.why}`, '', `**How to fix:** ${i.how}`, '');
        if (i.code) lines.push('Before:', '```html', i.code, '```', '');
        if (i.fix) lines.push('Suggested fix:', '```html', i.fix, '```', '');
        if (ai && !ai.error && !ai.busy) lines.push(`**AI (${ai.model}):** ${ai.why} ${ai.fix}`, '', ...(ai.corrected ? ['```html', ai.corrected, '```', ''] : []));
    }
    const a = Object.assign(document.createElement('a'), {
        href: URL.createObjectURL(new Blob([lines.join('\n')], { type: 'text/markdown' })),
        download: `webqa-${new URL(r.url).hostname || 'page'}.md`,
    });
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

/* ---------------- wiring ---------------- */

document.addEventListener('click', (e) => {
    const t = e.target.closest('button');
    if (!t) return;
    const r = state.results.get(state.tabId);
    const issue = (id) => r.issues.find((i) => i.id === Number(id));
    if (t.dataset.cat) state.filter.cat = state.filter.cat === t.dataset.cat ? null : t.dataset.cat;
    else if (t.dataset.sev) state.filter.sev = state.filter.sev === t.dataset.sev ? null : t.dataset.sev;
    else if (t.dataset.toggle) state.open.has(+t.dataset.toggle) ? state.open.delete(+t.dataset.toggle) : state.open.add(+t.dataset.toggle);
    else if (t.dataset.locate) return void locate(issue(t.dataset.locate)).catch((err) => toast(err.message));
    else if (t.dataset.ai) return void askAi(issue(t.dataset.ai));
    else if ('copy' in t.dataset) {
        navigator.clipboard.writeText(t.closest('.code').querySelector('pre').textContent).then(() => toast('Copied to the clipboard'), () => toast('Copy failed'));
        return;
    } else return;
    render();
});

$('analyse').addEventListener('click', analyse);
$('report').addEventListener('click', downloadReport);
$('ai-pill').addEventListener('click', aiStatus);
$('model').addEventListener('change', (e) => chrome.storage.local.set({ model: (state.model = e.target.value) }));
$('host-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
        state.host = new URL($('host').value.trim()).origin;
    } catch {
        return toast('Enter a full address, e.g. http://127.0.0.1:11434');
    }
    $('host').value = state.host;
    await chrome.storage.local.set({ host: state.host });
    aiStatus();
});

chrome.tabs.onActivated.addListener(syncTab);
chrome.tabs.onUpdated.addListener((id, change) => {
    if (id !== state.tabId) return;
    if (change.url || change.status === 'loading') state.results.delete(id);   // the page changed: old results no longer apply
    if (change.url || change.status === 'complete') syncTab();
});

const saved = await chrome.storage.local.get(['host', 'model']);
Object.assign(state, Object.fromEntries(Object.entries(saved).filter(([, v]) => v)));
$('host').value = state.host;
syncTab();
aiStatus();
