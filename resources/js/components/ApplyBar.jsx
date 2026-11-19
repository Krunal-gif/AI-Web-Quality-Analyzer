// Bottom bar: counts of approved/rejected fixes, apply mode (patch or in-place) and the Apply button.
import { useState } from 'react';
import { decidable } from '../lib/api';

export default function ApplyBar({ data, onApply, onShare }) {
    const [mode, setMode] = useState('patch');
    const approved = data.issues.filter((i) => ['approved', 'applied', 'conflict'].includes(i.status) && !i.written).length;
    const written = data.issues.filter((i) => i.written).length;
    const awaiting = data.issues.filter((i) => i.status === 'pending' && decidable(i)).length;
    const manual = data.issues.filter((i) => !i.editable).length;
    const isUrl = data.target.kind === 'url';
    const security = data.issues.filter((i) => i.category === 'Security').length;

    return (
        <div className="pointer-events-none fixed inset-x-0 bottom-0 z-20 px-2 pb-2 [view-transition-name:applybar] sm:px-4 sm:pb-3">
            <div className="pointer-events-auto mx-auto flex max-w-[1468px] animate-rise flex-wrap items-center gap-2 rounded-2xl border border-ink-200/80 bg-white/85 px-3 py-2.5 shadow-[0_18px_40px_-16px_rgb(12_40_52/0.3)] backdrop-blur-xl backdrop-saturate-150 sm:gap-3 sm:px-4 dark:border-ink-700/70 dark:bg-ink-900/85 dark:shadow-black/50">
                <div className="flex flex-wrap gap-3.5 text-[13px] text-ink-500">
                    <span><b key={approved} className="inline-block animate-pop text-emerald-600 tabular-nums dark:text-emerald-400">{approved}</b> approved</span>
                    {written > 0 && <span><b className="text-brand-600 tabular-nums dark:text-brand-300">{written}</b> written to files</span>}
                    <span className="max-sm:hidden"><b className="text-ink-900 tabular-nums dark:text-ink-100">{data.statuses.rejected ?? 0}</b> rejected</span>
                    <span className="max-sm:hidden"><b className="text-ink-900 tabular-nums dark:text-ink-100">{awaiting}</b> awaiting review</span>
                    <span className="max-sm:hidden"><b className="text-ink-900 tabular-nums dark:text-ink-100">{manual}</b> manual</span>
                </div>
                <span className="flex-1 max-sm:hidden" />
                <a className="btn btn-sm btn-ghost" href={`/analyses/${data.id}/report`} target="_blank" rel="noopener">
                    {data.after ? 'Final report ↗' : 'Report so far ↗'}
                </a>
                <a
                    className="btn btn-sm border-amber-300 bg-amber-50 text-amber-900 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-200 dark:hover:bg-amber-950"
                    href={`/analyses/${data.id}/security-report`}
                    target="_blank"
                    rel="noopener"
                    title="Open a report with only the Security findings"
                >
                    <svg aria-hidden viewBox="0 0 24 24" className="size-4" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round"><path d="M12 3 5 6v5c0 4.5 3 8.2 7 10 4-1.8 7-5.5 7-10V6z" /></svg>
                    Security report{security > 0 && <span className="rounded-full bg-amber-200/70 px-1.5 text-[11.5px] tabular-nums dark:bg-amber-800/60">{security}</span>} ↗
                </a>
                <button className="btn btn-sm btn-ghost" onClick={onShare}>Share report</button>
                <select className="field min-w-0 py-1.5 max-sm:flex-1" aria-label="Apply mode" value={mode} disabled={data.locked} onChange={(e) => setMode(e.target.value)}>
                    <option value="patch">Prepare patch + fixed copies (originals untouched)</option>
                    {!isUrl && <option value="inplace">Write to project files (backups kept)</option>}
                </select>
                <button className="btn btn-primary" disabled={!approved || data.locked} onClick={() => onApply(mode)}>
                    Apply {approved} approved fix{approved === 1 ? '' : 'es'}
                </button>
            </div>
        </div>
    );
}
