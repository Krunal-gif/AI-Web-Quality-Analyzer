// Circular quality score: the arc sweeps in and the number counts up when an analysis opens,
// and again from "before" to "after" once fixes are applied.
import { useCountUp } from '../lib/motion';

const C = 2 * Math.PI * 44; // circumference for r=44 (matches .ring-arc's starting offset in app.css)
const tone = (v) => (v >= 85 ? 'var(--color-emerald-500)' : v >= 60 ? 'var(--color-amber-400)' : 'var(--color-orange-600)');

export default function ScoreRing({ before, after }) {
    const value = after ?? before;
    const shown = useCountUp(value);

    return (
        <div className="relative size-28 shrink-0">
            <svg viewBox="0 0 100 100" className="size-full -rotate-90" aria-hidden>
                <circle cx="50" cy="50" r="44" fill="none" strokeWidth="8" className="stroke-ink-200 dark:stroke-ink-800" />
                {after !== undefined && (
                    <circle cx="50" cy="50" r="44" fill="none" strokeWidth="8" strokeDasharray={C} className="ring-arc stroke-ink-300 dark:stroke-ink-700" style={{ '--off': C * (1 - before / 100) }} />
                )}
                <circle cx="50" cy="50" r="44" fill="none" strokeWidth="8" strokeLinecap="round" strokeDasharray={C} className="ring-arc" style={{ '--off': C * (1 - value / 100), stroke: tone(value) }} />
            </svg>
            <div className="absolute inset-0 grid place-content-center text-center" role="img" aria-label={`Quality score ${value} out of 100${after !== undefined ? `, up from ${before}` : ''}`}>
                <span className="text-[34px] leading-none font-semibold tracking-tight tabular-nums">{shown}</span>
                <span className="mt-1 text-[11px] text-ink-500">{after !== undefined ? `was ${before}` : 'of 100'}</span>
            </div>
        </div>
    );
}
