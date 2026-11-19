// Left column: quality scores per category, filters (severity/status/search) and bulk actions.
import { Fragment } from 'react';
import AiOrb from './AiOrb';
import ScoreRing from './ScoreRing';
import { CATEGORIES, SEVERITIES, SEV_DOT, decidable, pagePath } from '../lib/api';

const STATUS_OPTIONS = [
    ['all', 'All statuses'],
    ['pending', 'Awaiting review'],
    ['approved', 'Approved'],
    ['rejected', 'Rejected'],
    ['applied', 'Applied'],
    ['conflict', 'Conflicts'],
    ['manual', 'Manual action needed'],
];

const scoreColor = (v) => (v >= 85 ? 'bg-emerald-500' : v >= 60 ? 'bg-amber-400' : 'bg-orange-600');

/** Country code → flag emoji (regional indicator letters). */
const flag = (cc) => (cc && /^[A-Z]{2}$/i.test(cc) ? String.fromCodePoint(...[...cc.toUpperCase()].map((c) => 0x1f1a5 + c.charCodeAt(0))) : '');
const named = (list) => list.map((t) => (t.version ? `${t.name} ${t.version}` : t.name));

/** What the site is built with and where it is hosted (from the first page analysed). */
function ProfilePanel({ profile }) {
    const h = profile.hosting;
    const where = h && !h.error ? [h.city, h.region, h.country].filter(Boolean).filter((v, i, a) => a.indexOf(v) === i).join(', ') : null;
    const rows = [
        ['Platform', named(profile.platform).join(', ') || 'Not identified'],
        ['Server language', profile.languages.server.join(', ') || 'Not visible'],
        ['Client side', profile.languages.client.join(', ')],
        ['Libraries', named(profile.frontend).join(', ') || null],
        ['Web server', profile.server || (profile.cdn ? `Hidden by ${profile.cdn}` : null)],
        ['CDN', profile.cdn],
    ].filter(([, v]) => v);

    return (
        <div className="panel">
            <h3 className="panel-title">Site profile</h3>
            <dl className="grid grid-cols-[6.5rem_minmax(0,1fr)] gap-x-3 gap-y-1.5 text-[12.5px]">
                {rows.map(([k, v]) => (
                    <Fragment key={k}>
                        <dt className="text-ink-500">{k}</dt>
                        <dd className="min-w-0 break-words text-ink-900 dark:text-ink-100">{v}</dd>
                    </Fragment>
                ))}
            </dl>
            {h && (
                <div className="mt-3 rounded-lg bg-ink-50 px-3 py-2.5 text-[12.5px] dark:bg-ink-950/50">
                    <span className="mb-1 block text-[11px] font-semibold tracking-wide text-ink-500 uppercase">Hosted in</span>
                    {h.error ? (
                        <span className="text-ink-500">{h.error}</span>
                    ) : (
                        <>
                            <b className="flex items-center gap-2 text-[14px] font-semibold">
                                {flag(h.country_code) && <span aria-hidden className="text-lg leading-none">{flag(h.country_code)}</span>}
                                {where || 'Unknown location'}
                            </b>
                            <span className="mt-0.5 block text-ink-600 dark:text-ink-400">{h.provider ?? 'Unknown provider'}{h.asn && <span className="text-ink-400"> · {h.asn}</span>}</span>
                            <span className="block font-mono text-[11.5px] text-ink-400">{h.ip}</span>
                            {h.behind_cdn && (
                                <span className="mt-1.5 block text-[12px] text-amber-700 dark:text-amber-300">
                                    This is {profile.cdn}'s nearest server. The site's own server and its country are hidden behind the CDN.
                                </span>
                            )}
                        </>
                    )}
                </div>
            )}
        </div>
    );
}

/** Every crawled page with its issue count; picking one shows only that page's issues. */
function PagesPanel({ site, files, issues, current, onPick }) {
    const pages = site.pages;
    // the site's own JavaScript and CSS, downloaded with the pages and analysed as code
    const code = files.filter((f) => f.kind === 'js' || f.kind === 'css').map((f) => {
        const own = issues.filter((i) => i.file === f.rel);
        return { ...f, issues: own.length, worst: SEVERITIES.find((s) => own.some((i) => i.severity === s)) };
    });
    const siteWide = issues.filter((i) => i.file === '(site)').length;
    const worst = (p) => SEVERITIES.find((s) => p.counts?.[s]);
    const row = (key, label, title, count, dot, extra) => (
        <li key={key}>
            <button
                onClick={() => onPick(current === key ? null : key)}
                aria-pressed={current === key}
                title={title}
                className={`-mx-1.5 flex w-[calc(100%+0.75rem)] items-center gap-2 rounded-lg px-1.5 py-1 text-left text-[12.5px] transition-colors hover:bg-ink-100 dark:hover:bg-ink-800 ${current === key ? 'bg-brand-50 ring-1 ring-brand-200 dark:bg-brand-950/60 dark:ring-brand-800' : ''}`}
            >
                <i className={`size-2 shrink-0 rounded-full ${dot ?? 'bg-emerald-500'}`} />
                <span className="min-w-0 flex-1 truncate font-mono text-xs">{label}</span>
                {extra}
                <span className="text-ink-500 tabular-nums">{count}</span>
            </button>
        </li>
    );

    return (
        <div className="panel">
            <h3 className="panel-title">Pages <span className="font-normal text-ink-400">({pages.filter((p) => p.analysed).length} analysed)</span></h3>
            {site.sitemap_url ? (
                <p className="mb-2 text-xs break-all text-ink-500">
                    Sitemap <span className="font-mono">{pagePath(site.sitemap_url)}</span> lists {site.sitemap_total} page(s)
                    {site.sitemap_total > pages.filter((p) => p.source === 'sitemap').length && <> — raise the page limit to analyse them all</>}.
                </p>
            ) : site.sitemap === false && <p className="mb-2 text-xs text-ink-500">No sitemap found — pages were found by following links.</p>}
            <ul className="-mr-1 flex max-h-72 flex-col overflow-auto pr-1">
                {siteWide > 0 && row('(site)', 'Whole site', 'Site-wide issues (robots.txt, sitemap, HTTPS…)', siteWide, 'bg-ink-400')}
                {pages.map((p) => row(
                    p.rel,
                    pagePath(p.url),
                    `${p.url}${p.source === 'link' ? ' — found by following a link (not in the sitemap)' : ''}`,
                    p.issues,
                    p.issues ? SEV_DOT[worst(p)] : null,
                    p.status === 0 || p.status >= 400
                        ? <span className="rounded bg-red-600/12 px-1 text-[10.5px] font-semibold text-red-700 dark:text-red-300">{p.status || 'failed'}</span>
                        : p.source === 'link' && site.sitemap_url
                            ? <span className="rounded bg-ink-500/12 px-1 text-[10.5px] text-ink-500">link</span>
                            : null,
                ))}
                {code.length > 0 && (
                    <li className="mt-2 mb-0.5 text-[11px] font-semibold tracking-wide text-ink-500 uppercase">Site code files ({code.length})</li>
                )}
                {code.map((f) => row(
                    f.rel,
                    f.rel.replace(/^[^/]+/, ''),
                    `${f.rel} — ${f.lines} lines of ${f.kind === 'js' ? 'JavaScript' : 'CSS'}`,
                    f.issues,
                    f.issues ? SEV_DOT[f.worst] : null,
                    <span className="rounded bg-ink-500/12 px-1 text-[10.5px] text-ink-500 uppercase">{f.kind}</span>,
                ))}
            </ul>
        </div>
    );
}

/** "About 12 min left", from how long the answers so far took (a CPU-only model takes minutes per issue). */
function aiEta(issues, batch) {
    const times = issues.map((i) => i.ai?.seconds).filter((t) => t > 0);
    if (!batch.done || !times.length) return 'The first answer is slowest: the model loads into memory first.';
    // the first answer includes loading the model, so prefer the later ones when there are some
    const recent = times.length > 1 ? times.slice(1) : times;
    const each = recent.reduce((a, b) => a + b, 0) / recent.length;
    const minutes = Math.ceil(((batch.total - batch.done) * each) / 60);
    return `About ${minutes} min left (≈${Math.round(each)} s per issue on this computer).`;
}

export const EMPTY_FILTERS = { sev: [], cat: null, status: 'all', q: '', fixable: false, page: null };

export default function Sidebar({ data, visible, filters, setFilters, onBulk, onAiBatch, onAiCancel, aiOnline }) {
    const before = data.scores_before;
    const after = data.after?.scores;
    const counts = Object.fromEntries(SEVERITIES.map((s) => [s, 0]));
    data.issues.forEach((i) => counts[i.severity]++);
    const fixable = visible.filter(decidable);
    const ruleFixes = fixable.filter((i) => i.proposed_source === 'rule' && i.status === 'pending');
    const needAi = visible.filter((i) => !i.ai || i.ai.error);
    const batch = data.batch ?? {};
    const filtered = filters.sev.length || filters.cat || filters.status !== 'all' || filters.q || filters.fixable || filters.page;
    const set = (patch) => setFilters({ ...filters, ...patch });
    const kindLabel = { url: 'Website', dir: 'Project folder', file: 'File' }[data.target.kind];

    return (
        <aside className="flex flex-col gap-3 lg:sticky lg:top-[118px] lg:max-h-[calc(100vh-210px)] lg:self-start lg:overflow-auto lg:pb-2">
            <div className="panel">
                <div className="mb-4 flex items-center gap-4">
                    <ScoreRing before={before.overall} after={after?.overall} />
                    <div className="min-w-0 text-[13px] text-ink-500 dark:text-ink-400">
                        <b className="block text-sm font-semibold text-ink-900 dark:text-ink-100">{kindLabel}</b>
                        <span dir="rtl" className="block truncate text-left font-mono text-xs" title={data.target.label}><bdi>{data.target.label}</bdi></span>
                        <span className="mt-1 block">
                            {data.target.kind === 'url'
                                ? (() => {
                                    const pages = data.target.files.filter((f) => f.kind === 'html').length;
                                    const code = data.target.files.length - pages;
                                    return `${pages} page(s)${code ? `, ${code} code file(s)` : ''}`;
                                })()
                                : `${data.target.files.length} file(s)`}, {data.issues.length} issue(s)
                        </span>
                    </div>
                </div>
                <h3 className="panel-title">Score by category</h3>
                <div className="flex flex-col gap-1.5">
                    {CATEGORIES.map((c) => {
                        const v = before.categories[c];
                        const a = after?.categories[c];
                        return (
                            <button
                                key={c}
                                onClick={() => set({ cat: filters.cat === c ? null : c })}
                                title={`Filter by ${c}`}
                                aria-pressed={filters.cat === c}
                                className={`-mx-1.5 grid grid-cols-[1fr_auto] gap-x-2 gap-y-1 rounded-lg px-1.5 py-1 text-left text-[12.5px] transition-colors hover:bg-ink-100 dark:hover:bg-ink-800 ${filters.cat === c ? 'bg-brand-50 ring-1 ring-brand-200 dark:bg-brand-950/60 dark:ring-brand-800' : ''}`}
                            >
                                <span>{c} <span className="text-ink-400">({before.counts[c]})</span></span>
                                <span className="text-ink-500 tabular-nums">
                                    {v}{a !== undefined && a !== v && <> → <b className="text-emerald-600">{a}</b></>}
                                </span>
                                <span className="relative col-span-2 h-1.5 overflow-hidden rounded-full bg-ink-200 dark:bg-ink-800">
                                    {a !== undefined && <span className="bar-fill absolute inset-0 rounded-full bg-emerald-500/35" style={{ '--w': a / 100 }} />}
                                    <span className={`bar-fill absolute inset-0 rounded-full ${scoreColor(v)}`} style={{ '--w': v / 100 }} />
                                </span>
                            </button>
                        );
                    })}
                </div>
            </div>

            {data.target.site?.profile && <ProfilePanel profile={data.target.site.profile} />}

            {data.target.site?.pages && (data.target.site.pages.length > 1 || data.target.files.some((f) => f.kind !== 'html')) && (
                <PagesPanel site={data.target.site} files={data.target.files} issues={data.issues} current={filters.page} onPick={(page) => set({ page })} />
            )}

            <div className="panel flex flex-col gap-2.5">
                <h3 className="panel-title !mb-0">Filter</h3>
                <div className="flex flex-wrap gap-1.5">
                    {SEVERITIES.filter((s) => counts[s]).map((s) => {
                        const on = filters.sev.includes(s);
                        return (
                            <button
                                key={s}
                                onClick={() => set({ sev: on ? filters.sev.filter((x) => x !== s) : [...filters.sev, s] })}
                                aria-pressed={on}
                                className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[12.5px] transition-[background-color,border-color,transform] active:scale-95 ${on ? 'border-brand-500 bg-brand-50 dark:bg-brand-950/60' : 'border-ink-300 hover:border-ink-400 dark:border-ink-700'}`}
                            >
                                <i className={`size-2 rounded-full ${SEV_DOT[s]}`} />
                                {s}
                                <span className="text-ink-400 tabular-nums">{counts[s]}</span>
                            </button>
                        );
                    })}
                </div>
                <select className="field" aria-label="Status filter" value={filters.status} onChange={(e) => set({ status: e.target.value })}>
                    {STATUS_OPTIONS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
                <input className="field" placeholder="Search issues, files…" aria-label="Search" value={filters.q} onChange={(e) => set({ q: e.target.value })} />
                <label className="flex items-center gap-2 text-[13px] text-ink-600 dark:text-ink-400">
                    <input type="checkbox" checked={filters.fixable} onChange={(e) => set({ fixable: e.target.checked })} />
                    Only issues with a proposed fix
                </label>
                {filtered && <button className="btn btn-sm btn-ghost self-start" onClick={() => setFilters(EMPTY_FILTERS)}>Clear filters</button>}
            </div>

            <div className="panel flex flex-col gap-1.5">
                <h3 className="panel-title">Bulk actions <span className="font-normal text-ink-400">for {visible.length} in view</span></h3>
                {batch.running ? (
                    <>
                        <div className="flex items-baseline justify-between gap-2 text-sm">
                            <span className="flex items-center gap-2"><AiOrb size={16} state="thinking" />AI reviewing <b className="tabular-nums">{batch.done}/{batch.total}</b></span>
                            {batch.current && <span className="truncate text-xs text-ink-500">on {batch.current}</span>}
                        </div>
                        <p className="text-xs text-ink-500">{aiEta(data.issues, batch)}</p>
                        <div className="h-2 overflow-hidden rounded-full bg-ink-200 dark:bg-ink-800">
                            <div
                                className="h-full animate-stripes rounded-full bg-brand-600 bg-[linear-gradient(45deg,rgb(255_255_255/0.18)_25%,transparent_25%,transparent_50%,rgb(255_255_255/0.18)_50%,rgb(255_255_255/0.18)_75%,transparent_75%)] bg-size-[16px_16px] transition-[width] duration-700 ease-out"
                                style={{ width: `${batch.total ? Math.max(4, (batch.done / batch.total) * 100) : 4}%` }}
                            />
                        </div>
                        <button className="btn btn-sm justify-start" onClick={onAiCancel}>Stop AI review</button>
                    </>
                ) : (
                    <button className="btn btn-sm btn-ai justify-start" disabled={!needAi.length || data.locked} onClick={() => onAiBatch(needAi.map((i) => i.id))} title={aiOnline ? '' : 'Ollama is offline'}>
                        <AiOrb size={13} state={aiOnline ? 'idle' : 'off'} /> Explain &amp; fix {needAi.length} with AI
                    </button>
                )}
                <button className="btn btn-sm btn-ok justify-start" disabled={!ruleFixes.length || data.locked} onClick={() => onBulk('approved', ruleFixes)}>✓ Approve {ruleFixes.length} rule-based fixes</button>
                <button className="btn btn-sm btn-ok justify-start" disabled={!fixable.length || data.locked} onClick={() => onBulk('approved', fixable)}>✓ Approve all {fixable.length} proposed fixes</button>
                <button className="btn btn-sm btn-bad justify-start" disabled={!fixable.length || data.locked} onClick={() => onBulk('rejected', fixable)}>✕ Reject all {fixable.length} in view</button>
                <button className="btn btn-sm justify-start" disabled={!fixable.length || data.locked} onClick={() => onBulk('pending', fixable)}>↺ Reset to pending</button>
                {batch.errors > 0 && (
                    <div className="animate-fade rounded-lg bg-red-50 px-3 py-2 text-[13px] text-red-800 dark:bg-red-950/50 dark:text-red-300">
                        <b className="font-semibold">{batch.errors} AI request{batch.errors === 1 ? '' : 's'} failed.</b>
                        {batch.last_error ? <span className="mt-1 block break-words">{batch.last_error}</span> : ' See the issue cards.'}
                    </div>
                )}
            </div>
        </aside>
    );
}
