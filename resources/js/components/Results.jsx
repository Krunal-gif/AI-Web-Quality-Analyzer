// Shown after fixes are applied: score before → after, verified count and download links.
import { useCountUp } from '../lib/motion';

function Kpi({ value, label, tone = '' }) {
    const shown = useCountUp(value);
    return (
        <div className="min-w-28 rounded-xl border border-ink-200 bg-ink-50/70 px-3.5 py-2.5 dark:border-ink-800 dark:bg-ink-950/50">
            <b className={`block text-[22px] leading-tight font-semibold tabular-nums ${tone}`}>{shown}</b>
            <span className="text-xs text-ink-500">{label}</span>
        </div>
    );
}

export default function Results({ data, onShare }) {
    const a = data.after;
    const b = data.scores_before;
    const base = `/analyses/${data.id}`;
    const score = useCountUp(a.scores.overall, 1400);
    return (
        <section className="mb-4 animate-rise overflow-hidden rounded-2xl border border-emerald-500/60 bg-white shadow-[0_16px_40px_-20px_rgb(16_185_129/0.45)] dark:bg-ink-900">
            <div className="flex flex-wrap items-center gap-x-6 gap-y-3 bg-gradient-to-r from-emerald-500/10 to-transparent p-4">
                <svg aria-hidden viewBox="0 0 24 24" className="size-10 shrink-0 rounded-full bg-emerald-500 p-2 text-white">
                    <path className="check-draw" d="M5 12.5 10 17.5 19 7" fill="none" stroke="currentColor" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
                <div className="min-w-0 flex-1">
                    <h2 className="text-lg font-semibold">Fixes applied {a.mode === 'inplace' ? 'to your files' : 'as a patch'}</h2>
                    <p className="text-sm text-ink-500">The fixed files were re-analysed to verify each change.</p>
                </div>
                <div className="flex items-baseline gap-2 tabular-nums" aria-label={`Overall score ${b.overall} to ${a.scores.overall}`}>
                    <span className="text-2xl text-ink-400 line-through decoration-ink-400/60">{b.overall}</span>
                    <span className="text-5xl font-semibold tracking-tight text-emerald-600">{score}</span>
                </div>
            </div>
            <div className="p-4 pt-3">
                <div className="mb-3 flex flex-wrap gap-2.5">
                    <Kpi value={a.applied} label="Fixes applied" />
                    <Kpi value={a.resolved} label="Verified fixed" tone="text-emerald-600" />
                    <Kpi value={a.unresolved} label="Still detected" />
                    <Kpi value={a.conflicts} label="Conflicts" />
                    <Kpi value={a.scores.total} label="Issues remaining" />
                    {a.new_issues.length > 0 && <Kpi value={a.new_issues.length} label="New after fix" tone="text-red-600" />}
                </div>
                <div className="flex flex-wrap gap-2">
                    <a className="btn btn-primary" href={`${base}/report`} target="_blank" rel="noopener">Open final report ↗</a>
                    <a className="btn" href={`${base}/security-report`} target="_blank" rel="noopener">Security report ↗</a>
                    <button className="btn" onClick={onShare}>Share report</button>
                    {a.patch && <a className="btn" href={`${base}/changes.patch`}>Download changes.patch</a>}
                    <a className="btn" href={`${base}/report.json`}>Report JSON</a>
                </div>
                <div className="mt-3 font-mono text-xs break-all text-ink-500">
                    {a.fixed_dir && <div>Fixed copies: {a.fixed_dir}</div>}
                    {a.backup_dir && <div>Backups of originals: {a.backup_dir}</div>}
                    <div>Output folder: {data.out_dir}</div>
                </div>
            </div>
        </section>
    );
}
