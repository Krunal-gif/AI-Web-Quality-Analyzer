// "Element" panel on an opened issue: the flagged HTML element drawn in a sandboxed frame (scripts off), its
// accessible label, its code, and the resource it points at, grouped under that resource's domain.
import { useMemo } from 'react';

const VISUAL = new Set(['img', 'picture', 'svg', 'video', 'iframe', 'input', 'button', 'select', 'textarea', 'canvas', 'object', 'embed']);
const RESOURCE_ATTRS = ['src', 'href', 'data', 'action', 'poster', 'srcset'];
const VOID = new Set(['img', 'input', 'br', 'hr', 'meta', 'link', 'source', 'embed', 'area', 'col', 'track', 'wbr']);

/** First element in an HTML snippet, read with DOMParser (which never runs scripts or loads anything). */
function parseElement(html) {
    const text = (html ?? '').trim();
    if (!/^<[a-z][\w-]*[\s>/]/i.test(text)) return null;
    const el = new DOMParser().parseFromString(text, 'text/html').body.firstElementChild;
    if (!el) return null;
    const tag = el.tagName.toLowerCase();
    const attr = RESOURCE_ATTRS.find((a) => el.getAttribute(a));
    const raw = attr ? el.getAttribute(attr) : null;
    // some rules flag only the opening tag, so the element's text (and so its label) isn't part of the snippet
    const whole = VOID.has(tag) || new RegExp(`</${tag}\\s*>`, 'i').test(text);
    const label = el.getAttribute('alt') ?? el.getAttribute('aria-label') ?? el.getAttribute('title')
        ?? (el.textContent.trim() || el.getAttribute('placeholder') || el.getAttribute('value') || null);
    const visible = VISUAL.has(tag) || el.querySelector([...VISUAL].join(',')) || el.textContent.trim() !== '';
    return { tag, attr, raw: attr === 'srcset' ? raw.split(',')[0].trim().split(/\s+/)[0] : raw, label, whole, visible };
}

const absolute = (raw, base) => {
    try {
        return new URL(raw, base || undefined);
    } catch {
        return null;
    }
};

/** Pretty-print one tag so long attribute lists wrap one per line, like a dev-tools view. */
const pretty = (html) => {
    const open = html.match(/^<[^>]+>/)?.[0] ?? html;
    return open.length < 70 ? html : open.replace(/\s+(?=[\w:-]+=)/g, '\n    ') + html.slice(open.length);
};

export default function ElementPreview({ issue }) {
    const el = useMemo(() => parseElement(issue.original), [issue.original]);
    if (!el) return null;

    const base = issue.page_url;
    const url = el.raw && !/^(javascript|data):/i.test(el.raw) ? absolute(el.raw, base) : null;
    const pageHost = base ? absolute(base)?.hostname : null;
    const host = url?.hostname || pageHost || 'this page';
    const thirdParty = url && pageHost && url.hostname.replace(/^www\./, '') !== pageHost.replace(/^www\./, '');
    // relative URLs only resolve for fetched pages; a local file's preview would point at the app itself
    const canPreview = el.visible && (base || !el.raw || /^https?:/i.test(el.raw));
    const safeBase = base ? base.replace(/"/g, '%22') : '';
    const srcdoc = `<!doctype html><meta charset="utf-8">${safeBase ? `<base href="${safeBase}">` : ''}`
        + '<style>html,body{margin:0;height:100%}body{display:grid;place-items:center;overflow:hidden;font:13px system-ui,sans-serif;color:#222;background:#fff}'
        + 'body>*{max-width:96%;max-height:96%;outline:2px solid #e3c400;outline-offset:2px}img,video,iframe,svg,canvas{max-width:100%;max-height:120px;object-fit:contain}</style>'
        + issue.original;

    return (
        <section className="overflow-hidden rounded-xl border border-ink-200 dark:border-ink-800" aria-label="Element">
            <h4 className="flex flex-wrap items-center gap-2 border-b border-ink-200 bg-ink-100/70 px-3 py-2 text-[13px] text-ink-600 dark:border-ink-800 dark:bg-ink-800/50 dark:text-ink-300">
                {host}
                {thirdParty && <span className="rounded bg-amber-100 px-1.5 text-[11px] font-medium text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">third-party</span>}
                <span className="ml-auto font-mono text-[11px] text-ink-400">&lt;{el.tag}&gt;</span>
            </h4>
            <div className="grid gap-4 bg-ink-50/60 p-3 sm:grid-cols-[180px_minmax(0,1fr)] lg:grid-cols-[180px_minmax(0,1fr)_minmax(0,14rem)] dark:bg-ink-950/40">
                <div className="grid h-[120px] w-full place-items-center overflow-hidden rounded-md border border-ink-200 bg-[repeating-conic-gradient(#eef1f3_0_25%,#fff_0_50%)] bg-[length:16px_16px] dark:border-ink-700">
                    {canPreview ? (
                        <iframe
                            title={`Preview of the ${el.tag} element`}
                            sandbox=""
                            referrerPolicy="no-referrer"
                            loading="lazy"
                            srcDoc={srcdoc}
                            className="pointer-events-none h-full w-full border-0"
                        />
                    ) : (
                        <span className="px-3 text-center text-xs text-ink-500">No visual preview for this element</span>
                    )}
                </div>
                <div className="min-w-0">
                    {el.label ? (
                        <p className="text-[13.5px] text-ink-900 dark:text-ink-100">{el.label}</p>
                    ) : el.whole ? (
                        <p className="text-[13.5px] text-red-700 italic dark:text-red-300">No label</p>
                    ) : (
                        <p className="text-[13px] text-ink-500 italic">Opening tag only</p>
                    )}
                    <pre className="mt-1.5 overflow-x-auto font-mono text-[12.5px] leading-relaxed whitespace-pre-wrap break-all text-brand-800 dark:text-brand-300">{pretty(issue.original)}</pre>
                </div>
                {url && (
                    <div className="min-w-0 text-[13px] sm:col-span-2 lg:col-span-1 lg:self-center">
                        <a href={url.href} target="_blank" rel="noopener noreferrer" className="block truncate underline decoration-dotted underline-offset-2 hover:text-brand-700 dark:hover:text-brand-300" title={url.href}>
                            {url.hostname === pageHost ? `${url.pathname}${url.search}` || '/' : `${url.pathname}${url.search}`}
                        </a>
                        <span className="text-xs text-ink-500">({url.hostname})</span>
                    </div>
                )}
            </div>
        </section>
    );
}
