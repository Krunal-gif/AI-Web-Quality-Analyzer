// "Pick up where you left off": saved analyses in a scrollable list (max 600px), each with an × to remove it.
import { useState } from 'react';

const KIND = { url: 'Website', dir: 'Folder', file: 'File' };
const scoreTone = (v) => (v >= 85 ? 'bg-emerald-500' : v >= 60 ? 'bg-amber-400' : 'bg-orange-600');
const COLLAPSE_MS = 320;

export default function RecentList({ history, onOpen, onRemove, onRemoved }) {
    const [gone, setGone] = useState(() => new Set());

    const remove = async (h) => {
        const backups = h.backups
            ? '\n\nIt also holds backups of original files that "Write fix to file" changed. Those backups are deleted too.'
            : '';
        if (!window.confirm(`Remove the analysis of ${h.label}?\n\nIts saved report, patch and fixed copies are deleted.${backups}\n\nYour project files are not touched.`)) return;

        setGone((s) => new Set(s).add(h.id));   // collapse the row while the request runs
        const [res] = await Promise.all([onRemove(h), new Promise((r) => setTimeout(r, COLLAPSE_MS))]);
        if (res) onRemoved(res.history);
        setGone((s) => {
            const next = new Set(s);
            next.delete(h.id);
            return next;
        });
    };

    return (
        <div className="panel !p-0">
            <div className="flex items-center justify-between gap-3 border-b border-ink-200/80 px-5 py-3.5 dark:border-ink-800">
                <h2 id="recent" className="text-[15px] font-semibold">Pick up where you left off</h2>
                <span className="rounded-full bg-ink-100 px-2.5 py-0.5 text-xs text-ink-600 tabular-nums dark:bg-ink-800 dark:text-ink-300">{history.length} saved</span>
            </div>
            <ul className="recent-scroll max-h-[600px] overflow-y-auto overscroll-contain p-2">
                {history.map((h) => (
                    <li
                        key={h.id}
                        className={`grid transition-[grid-template-rows,opacity,translate] duration-300 ease-out ${gone.has(h.id) ? 'pointer-events-none -translate-x-3 grid-rows-[0fr] opacity-0' : 'grid-rows-[1fr]'}`}
                    >
                        <div className="group flex min-h-0 items-center gap-1 overflow-hidden rounded-xl transition-colors hover:bg-ink-100 dark:hover:bg-ink-800/70">
                            <button
                                onClick={() => onOpen(h.id)}
                                className="grid min-w-0 flex-1 grid-cols-[4.5rem_minmax(0,1fr)_auto] items-center gap-3 rounded-xl py-2.5 pl-2.5 text-left sm:grid-cols-[4.5rem_minmax(0,1fr)_6rem_9rem_10rem]"
                            >
                                <span className="justify-self-start rounded-md bg-ink-100 px-1.5 py-0.5 text-[11.5px] text-ink-600 group-hover:bg-white dark:bg-ink-800 dark:text-ink-300 dark:group-hover:bg-ink-700">{KIND[h.kind] ?? h.kind}</span>
                                <span className="truncate text-sm font-medium group-hover:text-brand-700 dark:group-hover:text-brand-300" title={h.label}>{h.label}</span>
                                <span className="hidden text-right text-xs text-ink-500 tabular-nums sm:block">{h.issues} issues</span>
                                <span className="flex items-center gap-2">
                                    <span className="relative h-1.5 w-14 overflow-hidden rounded-full bg-ink-200 dark:bg-ink-800">
                                        <span className={`bar-fill absolute inset-0 rounded-full ${scoreTone(h.score_after ?? h.score)}`} style={{ '--w': (h.score_after ?? h.score) / 100 }} />
                                    </span>
                                    <span className="text-sm font-semibold tabular-nums">
                                        {h.score}{h.score_after !== null && <span className="text-emerald-600"> → {h.score_after}</span>}
                                    </span>
                                </span>
                                <span className="hidden text-right text-xs text-ink-400 sm:block">{new Date(h.created).toLocaleString()}</span>
                            </button>
                            <button
                                onClick={() => remove(h)}
                                className="mr-1.5 grid size-8 shrink-0 place-items-center rounded-full text-ink-400 transition-[color,background-color,transform] hover:bg-red-50 hover:text-red-600 active:scale-90 dark:hover:bg-red-950/50 dark:hover:text-red-400"
                                title="Remove from the list"
                                aria-label={`Remove the analysis of ${h.label}`}
                            >
                                <svg aria-hidden viewBox="0 0 24 24" className="size-4"><path d="M6 6l12 12M18 6 6 18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" /></svg>
                            </button>
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}
