// Small animation helpers: View Transitions for screen changes and a count-up hook for scores.
import { useEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';

export const reducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

/** Runs a state update inside a View Transition (cross-fade between screens) where the browser supports it. */
export function withTransition(update) {
    if (!document.startViewTransition || reducedMotion()) return update();
    document.startViewTransition(() => flushSync(update));
}

/** Animates a number from its previous value (0 on first render) to `value`. */
export function useCountUp(value, duration = 900) {
    const [shown, setShown] = useState(() => (reducedMotion() ? value : 0));
    const from = useRef(shown);

    useEffect(() => {
        if (typeof value !== 'number' || reducedMotion()) {
            from.current = value;
            setShown(value);
            return;
        }
        const start = performance.now();
        const a = typeof from.current === 'number' ? from.current : 0;
        let raf;
        const tick = (now) => {
            const t = Math.min(1, (now - start) / duration);
            const v = Math.round(a + (value - a) * (1 - (1 - t) ** 3));
            from.current = v;
            setShown(v);
            if (t < 1) raf = requestAnimationFrame(tick);
        };
        raf = requestAnimationFrame(tick);
        return () => cancelAnimationFrame(raf);
    }, [value, duration]);

    return shown;
}

/** True on the render where `value` differs from the previous render (used to replay a confirm animation). */
export function useChanged(value) {
    const prev = useRef(value);
    const changed = prev.current !== value;
    useEffect(() => {
        prev.current = value;
    });
    return changed;
}
