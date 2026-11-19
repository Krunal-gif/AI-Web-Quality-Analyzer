// "Page previews": a picture of each analysed page's first screen (taken by headless Chrome in the background),
// with its issue count, in a slider of 4 (2 on tablets, 1 on phones). Clicking a picture shows only that page's issues.
import { useCallback, useEffect, useRef, useState } from 'react';
import { SEVERITIES, SEV_DOT, pagePath } from '../lib/api';

export default function PageShots({ data, current, onPick }) {
    const [open, setOpen] = useState(true);
    const track = useRef(null);
    const [view, setView] = useState({ first: 0, count: 1 });
    const shots = data.shots;
    const pages = (data.target.site?.pages ?? []).filter((p) => p.analysed).slice(0, shots?.total || undefined);

    // which cards are in view, from the track's scroll position (cards are equal width)
    const measure = useCallback(() => {
        const el = track.current;
        const card = el?.firstElementChild;
        if (!card) return;
        const step = card.getBoundingClientRect().width + parseFloat(getComputedStyle(el).columnGap || 0);
        setView({ first: Math.round(el.scrollLeft / step), count: Math.max(1, Math.round((el.clientWidth + 1) / step)) });
    }, []);
    useEffect(() => {
        measure();
        window.addEventListener('resize', measure);
        return () => window.removeEventListener('resize', measure);
    }, [measure, open, pages.length]);
    const slide = (dir) => track.current?.scrollBy({
        left: dir * track.current.clientWidth,
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
    });
    const last = Math.min(pages.length, view.first + view.count);
    if (data.target.kind !== 'url' || !shots || (!shots.total && !shots.error) || !pages.length) return null;

    return (
        <section className="panel mb-3 !p-0" aria-labelledby="shots-title">
            <button
                onClick={() => setOpen(!open)}
                aria-expanded={open}
                className="flex w-full items-center gap-2 px-4 py-2.5 text-left"
            >
                <h2 id="shots-title" className="text-sm font-semibold">Page previews</h2>
                <span className="text-xs text-ink-500 tabular-nums">
                    {shots.running ? `taking pictures ${shots.done}/${shots.total}…` : `${pages.length} page${pages.length === 1 ? '' : 's'} · first screen`}
                </span>
                <svg aria-hidden viewBox="0 0 16 16" className={`ml-auto size-4 text-ink-400 transition-transform duration-300 ${open ? 'rotate-90' : ''}`}>
                    <path d="M6 3.5 10.5 8 6 12.5" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
            </button>
            {open && (
                <div className="border-t border-ink-200 p-3 dark:border-ink-800">
                    {shots.error ? (
                        <p className="text-[13px] text-ink-500">{shots.error}</p>
                    ) : (
                        <>
                        <div className="mb-2.5 flex items-center justify-end gap-2">
                            <span className="mr-auto text-xs text-ink-500 tabular-nums" aria-live="polite">
                                {pages.length > view.count ? `${view.first + 1}–${last} of ${pages.length}` : ''}
                            </span>
                            {pages.length > view.count && [[-1, 'Previous pages', 'M10 3.5 5.5 8 10 12.5'], [1, 'Next pages', 'M6 3.5 10.5 8 6 12.5']].map(([dir, label, d]) => (
                                <button
                                    key={dir}
                                    className="btn btn-sm !px-2"
                                    aria-label={label}
                                    title={label}
                                    disabled={dir < 0 ? view.first === 0 : last >= pages.length}
                                    onClick={() => slide(dir)}
                                >
                                    <svg aria-hidden viewBox="0 0 16 16" className="size-4"><path d={d} fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" /></svg>
                                </button>
                            ))}
                        </div>
                        <ul
                            ref={track}
                            onScroll={measure}
                            className="flex snap-x snap-mandatory gap-3 overflow-x-auto scroll-smooth pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                        >
                            {pages.map((p) => {
                                const shot = shots.items[p.rel];
                                const on = current === p.rel;
                                const worst = SEVERITIES.find((s) => p.counts?.[s]);
                                return (
                                    <li key={p.rel} className="min-w-0 shrink-0 basis-full snap-start sm:basis-[calc((100%-0.75rem)/2)] lg:basis-[calc((100%-2.25rem)/4)]">
                                        <button
                                            onClick={() => onPick(on ? null : p.rel)}
                                            aria-pressed={on}
                                            title={`${p.url} — click to show only this page's issues`}
                                            className={`group block w-full overflow-hidden rounded-lg border text-left transition-[border-color,box-shadow] ${on ? 'border-brand-500 ring-2 ring-brand-500/30' : 'border-ink-200 hover:border-ink-400 dark:border-ink-700 dark:hover:border-ink-500'}`}
                                        >
                                            <span className="relative block aspect-[1366/768] overflow-hidden bg-ink-100 dark:bg-ink-800">
                                                {shot?.ok ? (
                                                    <img
                                                        src={`/analyses/${data.id}/shots/${shot.key}`}
                                                        alt={`First screen of ${pagePath(p.url)}`}
                                                        loading="lazy"
                                                        className="h-full w-full object-cover object-top transition-transform duration-500 group-hover:scale-[1.03]"
                                                    />
                                                ) : shot ? (
                                                    <span className="grid h-full place-items-center px-3 text-center text-xs text-ink-500">{shot.error}</span>
                                                ) : shots.running ? (
                                                    <span className="absolute inset-0 animate-pulse bg-gradient-to-br from-ink-100 to-ink-200 dark:from-ink-800 dark:to-ink-700" aria-label="Taking picture" />
                                                ) : (
                                                    <span className="grid h-full place-items-center text-xs text-ink-500">No picture</span>
                                                )}
                                            </span>
                                            <span className="flex items-center gap-2 px-2.5 py-2 text-[12.5px]">
                                                <i className={`size-2 shrink-0 rounded-full ${p.issues ? SEV_DOT[worst] : 'bg-emerald-500'}`} />
                                                <span className="min-w-0 flex-1 truncate font-mono text-xs">{pagePath(p.url)}</span>
                                                <span className="text-ink-500 tabular-nums">{p.issues} issue{p.issues === 1 ? '' : 's'}</span>
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                        </>
                    )}
                </div>
            )}
        </section>
    );
}
