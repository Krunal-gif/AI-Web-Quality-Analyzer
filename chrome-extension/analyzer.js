// Runs inside the analysed tab (injected by panel.js). Reads the live DOM, its inline and same-origin
// scripts, robots.txt and sitemap.xml, and returns a plain list of issues.
// It is one async arrow function: its promise is the injection result, and injecting it again
// declares nothing global. Rule ids, wording and thresholds follow app/Analyzer/Rules.
(async () => {
    const MAX_PER_RULE = 25;
    const issues = [];
    const hidden = {};                       // rule → issues not listed because of MAX_PER_RULE
    const seen = {};

    const short = (el) => el.outerHTML.length <= 240;
    const openTag = (html) => html.match(/^<[^>]*>/)?.[0] ?? html.slice(0, 200);
    const snippet = (el) => (short(el) ? el.outerHTML : `${openTag(el.outerHTML)}…`);
    const cut = (s, n = 60) => { s = String(s ?? '').replace(/\s+/g, ' ').trim(); return s.length > n ? `${s.slice(0, n - 1)}…` : s; };
    const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');

    /** Copy of the element with attributes set: the "after" side of a rule-based fix. */
    const withAttrs = (el, attrs) => {
        const c = el.cloneNode(short(el));
        for (const [k, v] of Object.entries(attrs)) c.setAttribute(k, v);
        return short(el) ? c.outerHTML : `${openTag(c.outerHTML)}…`;
    };

    /** Unique CSS path so the panel can scroll to and outline the element. */
    const cssPath = (el) => {
        const parts = [];
        for (; el && el.nodeType === 1 && el !== document.documentElement; el = el.parentElement) {
            if (el.id && document.querySelectorAll(`#${CSS.escape(el.id)}`).length === 1) {
                parts.unshift(`#${CSS.escape(el.id)}`);
                break;
            }
            const same = [...(el.parentElement?.children ?? [])].filter((c) => c.tagName === el.tagName);
            parts.unshift(el.tagName.toLowerCase() + (same.length > 1 ? `:nth-of-type(${same.indexOf(el) + 1})` : ''));
        }
        return parts.join(' > ') || 'html';
    };

    const add = (rule, message, { el = null, code = null, fix = null, severity = null, where = null } = {}) => {
        seen[rule] = (seen[rule] ?? 0) + 1;
        if (seen[rule] > MAX_PER_RULE) {
            hidden[rule] = (hidden[rule] ?? 0) + 1;
            return;
        }
        issues.push({ rule, message, severity, where, code: code ?? (el ? snippet(el) : null), fix, selector: el ? cssPath(el) : null });
    };

    const $ = (s) => document.querySelector(s);
    const $$ = (s) => [...document.querySelectorAll(s)];
    const attr = (s, a) => $(s)?.getAttribute(a)?.trim() ?? '';
    const labelledBy = (el) => (el.getAttribute('aria-labelledby') ?? '').split(/\s+/).map((id) => id && document.getElementById(id)?.textContent).join(' ').trim();
    const accName = (el) => (el.getAttribute('aria-label') || labelledBy(el) || el.getAttribute('title') || el.textContent
        || [...el.querySelectorAll('img[alt]')].map((i) => i.alt).join(' ') || el.querySelector('svg title')?.textContent || '').trim();
    const humanise = (src) => cut(decodeURIComponent(src.split(/[?#]/)[0].split('/').pop() || '').replace(/\.\w+$/, '').replace(/[-_]+/g, ' ').replace(/^\w/, (c) => c.toUpperCase()), 80);

    const head = document.head;
    const h1s = $$('h1');
    const firstP = $$('main p, article p, p').map((p) => p.textContent.replace(/\s+/g, ' ').trim()).find((t) => t.length >= 50) ?? '';

    /* ---------------- SEO ---------------- */
    const title = document.title.trim();
    if (!title) add('seo-title-missing', 'The page has no <title>.', { fix: `<title>${esc(cut(h1s[0]?.textContent || location.hostname, 60))}</title>` });
    else if (title.length < 10 || title.length > 60) add('seo-title-length', `The title is ${title.length} characters (recommended 30–60): “${cut(title, 80)}”.`, { code: `<title>${esc(title)}</title>` });

    const desc = attr('meta[name="description" i]', 'content');
    if (!$('meta[name="description" i]')) add('seo-meta-description-missing', 'No <meta name="description"> in <head>.', { fix: firstP ? `<meta name="description" content="${esc(cut(firstP, 155))}">` : null });
    else if (desc.length < 50 || desc.length > 160) add('seo-meta-description-length', `The meta description is ${desc.length} characters (recommended 120–160).`, { el: $('meta[name="description" i]') });

    if (!h1s.length) add('seo-h1-missing', 'The page has no <h1> heading.');
    h1s.slice(1).forEach((h) => add('seo-h1-multiple', `Additional <h1>: “${cut(h.textContent)}”.`, { el: h, fix: short(h) ? h.outerHTML.replace(/^<h1/i, '<h2').replace(/h1>$/i, 'h2>') : null }));

    if (!$('link[rel="canonical" i]')) add('seo-canonical-missing', 'No <link rel="canonical"> in <head>.', { fix: `<link rel="canonical" href="${esc(location.origin + location.pathname)}">` });
    if (!$('meta[name="viewport" i]')) add('seo-viewport-missing', 'No <meta name="viewport"> in <head>.', { fix: '<meta name="viewport" content="width=device-width, initial-scale=1">' });
    if (!$('meta[property="og:title"]') || !$('meta[property="og:description"]')) {
        add('seo-og-missing', 'og:title or og:description is missing.', { fix: `<meta property="og:title" content="${esc(cut(title || location.hostname, 60))}">\n<meta property="og:description" content="${esc(cut(desc || firstP, 155))}">` });
    }
    const robotsMeta = $('meta[name="robots" i], meta[name="googlebot" i]');
    if (/noindex/i.test(robotsMeta?.content ?? '')) add('seo-noindex', `<meta name="${robotsMeta.name}"> contains noindex.`, { el: robotsMeta });

    const nav = performance.getEntriesByType('navigation')[0];
    if (nav?.responseStatus >= 400) add('seo-http-error', `The page returned HTTP ${nav.responseStatus}.`);
    const ttfb = nav ? Math.round(nav.responseStart - nav.requestStart) : 0;
    if (ttfb > 1500) add('seo-slow-response', `The server took ${ttfb} ms to respond (aim for under 800 ms).`, { severity: ttfb > 4000 ? 'high' : null });

    // robots.txt and sitemap: same-origin requests from the page, so no extra permission is needed
    if (/^https?:$/.test(location.protocol)) {
        const get = async (path) => {
            try {
                const r = await fetch(location.origin + path, { cache: 'no-store', redirect: 'follow' });
                const text = r.ok ? await r.text() : '';
                return /^\s*<(!doctype|html)/i.test(text) ? '' : text;   // an HTML "not found" page is not a file
            } catch {
                return '';
            }
        };
        const robots = await get('/robots.txt');
        if (!robots) add('seo-robots-missing', `${location.origin}/robots.txt was not found.`);
        if (!/^\s*sitemap:/im.test(robots) && !/<(urlset|sitemapindex)/i.test(await get('/sitemap.xml'))) {
            add('seo-sitemap-missing', 'No sitemap at /sitemap.xml and none listed in robots.txt.');
        }
    }

    /* ---------------- Accessibility ---------------- */
    const html = document.documentElement;
    if (!html.getAttribute('lang')?.trim()) add('a11y-lang-missing', 'The <html> element has no lang attribute.', { el: html, fix: withAttrs(html, { lang: (navigator.language || 'en').split('-')[0] }) });

    $$('img:not([alt])').forEach((img) => {
        const src = img.getAttribute('src') ?? '';
        add('a11y-img-alt-missing', `Image “${cut(src || '(no src)')}” has no alt attribute.`, { el: img, fix: withAttrs(img, { alt: humanise(src) || 'Describe this image' }) });
    });
    $$('a[href]').filter((a) => !accName(a)).forEach((a) => add('a11y-link-no-text', `Link to “${cut(a.getAttribute('href'))}” has no accessible text.`, { el: a }));
    $$('button, [role="button"]').filter((b) => !accName(b)).forEach((b) => add('a11y-button-no-name', 'A <button> has no text or aria-label.', { el: b }));
    $$('input:not([type="hidden" i]):not([type="submit" i]):not([type="button" i]):not([type="reset" i]):not([type="image" i]), select, textarea')
        .filter((f) => !f.labels?.length && !f.getAttribute('aria-label')?.trim() && !labelledBy(f) && !f.getAttribute('title')?.trim())
        .forEach((f) => add('a11y-input-no-label', `<${f.tagName.toLowerCase()} name="${f.name}"> has no associated label.`, {
            el: f, fix: f.placeholder ? withAttrs(f, { 'aria-label': f.placeholder }) : null,
        }));
    $$('iframe:not([title])').forEach((f) => {
        const src = f.getAttribute('src') ?? '';
        let host = '';
        try { host = new URL(src, location.href).hostname.replace(/^www\./, ''); } catch { /* keep empty */ }
        add('a11y-iframe-title', `<iframe src="${cut(src)}"> has no title.`, { el: f, fix: withAttrs(f, { title: host ? `Embedded content from ${host}` : 'Embedded content' }) });
    });
    const GENERIC = ['click here', 'here', 'read more', 'more', 'learn more', 'click', 'link', 'this link', 'more info', 'details', 'go', 'continue', 'view more'];
    $$('a').forEach((a) => {
        const text = a.textContent.toLowerCase().replace(/^[\s.!:→»>]+|[\s.!:→»>]+$/g, '').replace(/\s+/g, ' ');
        if (GENERIC.includes(text)) add('a11y-generic-link-text', `Link text “${cut(a.textContent, 40)}” doesn't describe the destination (${cut(a.getAttribute('href'), 50)}).`, { el: a });
    });
    $$('[tabindex]').filter((el) => /^\+?\d+$/.test(el.getAttribute('tabindex').trim()) && +el.getAttribute('tabindex') > 0)
        .forEach((el) => add('a11y-positive-tabindex', `<${el.tagName.toLowerCase()}> has tabindex="${el.getAttribute('tabindex')}".`, { el, fix: withAttrs(el, { tabindex: '0' }) }));

    /* ---------------- HTML structure ---------------- */
    if (document.doctype?.name.toLowerCase() !== 'html') add('html-doctype-missing', 'The document has no <!DOCTYPE html>.', { fix: '<!DOCTYPE html>' });
    if (!$('meta[charset], meta[http-equiv="content-type" i]')) add('html-charset-missing', 'No <meta charset> in <head>.', { fix: '<meta charset="utf-8">' });

    const ids = {};
    $$('[id]').forEach((el) => (ids[el.id] ??= []).push(el));
    Object.entries(ids).filter(([id, els]) => id && els.length > 1)
        .forEach(([id, els]) => els.slice(1).forEach((el) => add('html-duplicate-id', `id="${cut(id, 40)}" is used ${els.length} times on this page.`, { el })));

    $$('center, font, marquee, big, strike, tt, acronym, blink, frame, frameset, applet, basefont, dir')
        .forEach((el) => add('html-deprecated-tag', `<${el.tagName.toLowerCase()}> is obsolete in HTML5.`, { el }));

    let prev = 0;
    $$('h1, h2, h3, h4, h5, h6').forEach((h) => {
        const level = +h.tagName[1];
        if (prev && level > prev + 1) add('html-heading-skip', `<h${level}> “${cut(h.textContent, 50)}” follows <h${prev}> — level h${prev + 1} is skipped.`, { el: h });
        prev = level;
    });

    const styled = $$('body [style]');
    if (styled.length) add('html-inline-styles', `${styled.length} element(s) use inline style attributes.`, { el: styled[0] });

    $$('a[href]').filter((a) => ['', '#'].includes(a.getAttribute('href').trim()) && !a.getAttribute('role') && !a.hasAttribute('onclick'))
        .forEach((a) => add('html-empty-link', `Link “${cut(a.textContent, 40) || '(no text)'}” has href="${a.getAttribute('href')}".`, { el: a }));

    /* ---------------- Content ---------------- */
    const text = document.body?.innerText ?? '';
    const words = text.match(/\b\w+\b/g)?.length ?? 0;
    if (words < 250) add('content-thin', `The page has only ${words} words of visible text.`);
    const lorem = text.match(/[^\n]*(lorem ipsum|dolor sit amet)[^\n]*/i);
    if (lorem) add('content-placeholder', `Placeholder text: “${cut(lorem[0], 70)}”.`);
    for (const line of text.split('\n')) {
        for (const m of line.matchAll(/\b([A-Za-z]{2,})[ \t]+\1\b/gi)) add('content-repeated-word', `“${m[0]}” in “${cut(line, 70)}”.`, { code: m[0], fix: m[1] });
        for (const s of line.match(/[^.!?]+[.!?]?/g) ?? []) {
            const n = s.trim().split(/\s+/).length;
            if (n > 40) add('content-long-sentence', `A ${n}-word sentence: “${cut(s, 70)}”.`);
        }
    }

    /* ---------------- Code + security in scripts ---------------- */
    const SECRETS = [
        ['AWS access key', /\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/],
        ['private key', /-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/],
        ['Stripe secret key', /\bsk_(?:live|test)_[0-9a-zA-Z]{16,}/],
        ['Google API key', /\bAIza[0-9A-Za-z_-]{35}\b/],
        ['GitHub token', /\bgh[pousr]_[A-Za-z0-9]{36,}\b/],
        ['Slack token', /\bxox[baprs]-[A-Za-z0-9-]{10,}/],
        ['credential', /\b[a-z0-9_]*(?:api[_-]?key|secret|passw(?:or)?d|token|auth[_-]?key)[a-z0-9_]*["']?\s*(?:=>|[:=])\s*(["'])([^"'\s]{8,})\1/i],
    ];
    const PLACEHOLDER_VALUE = /(your|example|changeme|xxxx|placeholder|dummy|sample|<|\$\{|\{\{|process\.env|getenv|env\(|\*\*\*)/i;
    const around = (line, i) => cut(line.length > 160 ? `${i > 60 ? '…' : ''}${line.slice(Math.max(0, i - 60), i + 90)}` : line.trim(), 160);

    const sources = $$('script:not([src])')
        .filter((s) => !s.type || /javascript|module/i.test(s.type))
        .map((s, n) => ({ name: `inline script #${n + 1}`, text: s.textContent }));
    for (const s of $$('script[src]').filter((s) => new URL(s.src, location.href).origin === location.origin).slice(0, 15)) {
        try {
            const r = await fetch(s.src, { cache: 'force-cache' });
            const body = r.ok ? await r.text() : '';
            if (body.length < 2_000_000) sources.push({ name: new URL(s.src).pathname, text: body });
        } catch { /* blocked or offline: skip */ }
    }
    for (const src of sources) {
        src.text.split('\n').forEach((line, i) => {
            const where = `${src.name}, line ${i + 1}`;
            const opts = (at) => ({ where, code: around(line, at) });
            let m;
            if ((m = /\bconsole\.(log|debug|info|trace|dir)\s*\(/.exec(line))) add('code-console-log', `console.${m[1]}() in ${where}.`, opts(m.index));
            if (/^\s*debugger;?\s*$/.test(line)) add('code-debugger', `debugger statement in ${where}.`, opts(0));
            if ((m = /(?<![.\w])eval\s*\(/.exec(line))) add('sec-eval', `eval() in ${where}.`, opts(m.index));
            if ((m = /\/\/.*\b(TODO|FIXME|HACK|XXX)\b|\/\*.*\b(TODO|FIXME|HACK|XXX)\b/.exec(line))) add('code-todo', `${m[1] ?? m[2]} comment in ${where}.`, opts(m.index));
            for (const [label, re] of SECRETS) {          // first match only: one secret per line is enough
                const s = re.exec(line);
                if (!s || (label === 'credential' && PLACEHOLDER_VALUE.test(s[2]))) continue;
                add('sec-hardcoded-secret', `Possible hard-coded ${label} in ${where}.`, { ...opts(s.index), code: around(line, s.index).replace(s[2] ?? s[0], (v) => `${v.slice(0, 4)}…${v.slice(-2)}`) });
                break;
            }
        });
    }

    /* ---------------- Security in markup ---------------- */
    if (location.protocol === 'http:' && !/^(localhost|127\.0\.0\.1|0\.0\.0\.0|\[::1\])$/.test(location.hostname)) {
        add('sec-not-https', `${location.origin} is served over plain HTTP.`);
    }
    $$('a[target="_blank" i]').filter((a) => !/\bnoopener\b|\bnoreferrer\b/i.test(a.rel)).forEach((a) => {
        add('sec-target-blank', `Link to “${cut(a.getAttribute('href'))}” opens a new tab without rel="noopener".`, { el: a, fix: withAttrs(a, { rel: `${a.rel} noopener noreferrer`.trim() }) });
    });
    $$('script[src], img[src], iframe[src], source[src], video[src], audio[src], embed[src], link[href][rel~="stylesheet" i], form[action]').forEach((el) => {
        const a = el.matches('link') ? 'href' : el.matches('form') ? 'action' : 'src';
        const url = el.getAttribute(a).trim();
        if (/^http:\/\//i.test(url) && !/^http:\/\/(localhost|127\.0\.0\.1|0\.0\.0\.0)/i.test(url)) {
            add('sec-insecure-resource', `<${el.tagName.toLowerCase()}> loads “${cut(url, 70)}” over plain HTTP${location.protocol === 'https:' ? ' on an HTTPS page (mixed content)' : ''}.`, { el, fix: withAttrs(el, { [a]: url.replace(/^http:/i, 'https:') }) });
        }
    });

    return { url: location.href, title, words, issues, hidden, scannedScripts: sources.length };
})();
