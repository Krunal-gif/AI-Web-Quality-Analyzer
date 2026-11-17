// Top bar. Home: logo, page links, local-AI status and the theme toggle (the target box is in the hero).
// Review screen: target input + Analyse button, the Ollama status/model picker and the workflow steps.
import { Fragment, useEffect, useState } from 'react';
import AiOrb from './AiOrb';

// absolute (/#…) so they also work from the History page
const LINKS = [['Features', '/#features'], ['How it works', '/#how'], ['Local AI', '/#ai'], ['Applying fixes', '/#apply']];

/**
 * The home section being read: the last anchor whose top has passed 40% of the viewport (the last one once the page
 * is scrolled to the bottom), or null above the first.
 */
function useActiveSection(ids, enabled) {
    const [active, setActive] = useState(null);
    useEffect(() => {
        if (!enabled) return setActive(null);
        let frame = 0;
        const update = () => {
            frame = 0;
            const line = window.innerHeight * 0.4;
            const atBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4;
            let current = null;
            for (const id of ids) {
                const el = document.getElementById(id);
                if (el && (el.getBoundingClientRect().top <= line || atBottom)) current = id;
            }
            setActive(current);
        };
        const onScroll = () => { frame ||= requestAnimationFrame(update); };
        update();
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll);
        return () => {
            cancelAnimationFrame(frame);
            window.removeEventListener('scroll', onScroll);
            window.removeEventListener('resize', onScroll);
        };
    }, [ids, enabled]);
    return [active, setActive];
}

const SECTION_IDS = LINKS.map(([, href]) => href.slice(2));

const STEPS = ['Analyse', 'Detect issues', 'Explain why', 'Review & approve', 'Apply fixes', 'Final report'];

export function stepIndex(data) {
    if (!data) return 0;
    if (data.after) return 6;
    if (data.issues.some((i) => i.status !== 'pending')) return 4;
    if (data.issues.some((i) => i.ai && !i.ai.error)) return 3;
    return 2;
}

function AiPill({ ai, onModel, onRefresh }) {
    const online = ai?.online;
    const models = online ? [...new Set([ai.model, ...ai.models])] : [];
    return (
        <div className="flex max-w-full items-center gap-1.5 rounded-full border border-ink-300 bg-white py-0.5 pr-1 pl-3 text-sm shadow-xs dark:border-ink-700 dark:bg-ink-900" title={ai?.error || (ai ? `Connected to ${ai.host}` : '')}>
            <AiOrb size={14} state={!ai ? 'thinking' : online && ai.model_installed ? 'idle' : 'off'} />
            {online ? (
                <>
                    <span>Ollama</span>
                    <select aria-label="Ollama model" className="max-w-48 bg-transparent py-1 outline-none" value={ai.model} onChange={(e) => onModel(e.target.value)}>
                        {models.map((m) => <option key={m} value={m}>{m}</option>)}
                    </select>
                </>
            ) : (
                <span className="truncate">{ai ? 'Ollama offline · rule explanations' : 'Checking Ollama…'}</span>
            )}
            <button className="btn btn-sm btn-ghost group !px-2" title="Re-check Ollama" aria-label="Re-check Ollama" onClick={onRefresh}>
                <span className="inline-block transition-transform duration-500 group-active:-rotate-180">↻</span>
            </button>
        </div>
    );
}

/** Moon / sun button. Stores the choice in localStorage; app.blade.php applies it before the first paint. */
function ThemeToggle() {
    const systemDark = () => window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false;
    const [dark, setDark] = useState(() => (document.documentElement.dataset.theme ?? (systemDark() ? 'dark' : 'light')) === 'dark');

    const toggle = () => {
        const next = dark ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        try {
            localStorage.setItem('theme', next);
        } catch {
            /* private mode: the choice lasts until reload */
        }
        setDark(!dark);
    };

    return (
        <button
            onClick={toggle}
            className="grid size-10 shrink-0 place-items-center rounded-full border border-ink-200 bg-white text-ink-700 transition-[background-color,transform] hover:bg-ink-50 active:scale-95 dark:border-ink-700 dark:bg-ink-900 dark:text-ink-200 dark:hover:bg-ink-800"
            aria-label={dark ? 'Switch to light mode' : 'Switch to dark mode'}
            title={dark ? 'Light mode' : 'Dark mode'}
        >
            <svg aria-hidden viewBox="0 0 24 24" className="size-[18px]" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                {dark
                    ? <path d="M12 4V2m0 20v-2m8-8h2M2 12h2m13.66-5.66 1.41-1.41M4.93 19.07l1.41-1.41m0-11.32L4.93 4.93m14.14 14.14-1.41-1.41M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0" />
                    : <path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5" />}
            </svg>
        </button>
    );
}

function Logo({ onHome }) {
    return (
        <button onClick={onHome} className="group flex items-center gap-2.5 rounded-lg whitespace-nowrap">
            <span className="relative grid size-9 place-items-center overflow-hidden rounded-[10px] bg-gradient-to-br from-brand-600 to-brand-800 text-[13px] font-bold text-white shadow-[inset_0_1px_0_rgb(255_255_255/0.25),0_4px_12px_-4px_rgb(0_155_132/0.6)]">
                QA
                <span aria-hidden className="absolute inset-x-0 -top-1 h-1 bg-white/80 shadow-[0_0_8px_white] transition-transform duration-700 ease-out group-hover:translate-y-10" />
            </span>
            <span className="font-display text-[22px] font-extrabold tracking-[-0.03em]">Web<span className="text-brand-600 dark:text-brand-400">QA</span></span>
            <small className="mt-0.5 hidden text-[13px] text-ink-500 lg:inline dark:text-ink-400">AI Web Quality Analyzer</small>
        </button>
    );
}

/** Home header pill: "Local AI · Ready". Click to re-check Ollama. */
function AiState({ ai, onRefresh }) {
    const ready = ai?.online && ai?.model_installed;
    const label = !ai ? 'Checking…' : ready ? 'Ready' : ai.online ? 'Model missing' : 'Offline';
    return (
        <button
            onClick={onRefresh}
            title={ai?.error || (ready ? `${ai.model} on ${ai.host}. Click to re-check.` : 'Click to re-check Ollama')}
            className={`hidden items-center gap-2 rounded-full px-4 py-2 text-sm font-medium whitespace-nowrap transition-colors sm:flex ${ready ? 'bg-brand-50 text-brand-800 hover:bg-brand-100 dark:bg-brand-950/70 dark:text-brand-200 dark:hover:bg-brand-950' : 'bg-ink-100 text-ink-600 hover:bg-ink-200 dark:bg-ink-800 dark:text-ink-300 dark:hover:bg-ink-700'}`}
        >
            <span aria-hidden className={`size-2 rounded-full ${!ai ? 'animate-pulse bg-ink-400' : ready ? 'bg-brand-500 shadow-[0_0_0_3px_rgb(0_155_132/0.18)]' : ai.online ? 'bg-amber-500' : 'bg-ink-400'}`} />
            Local AI <span aria-hidden>·</span> {label}
        </button>
    );
}

function HistoryLink({ onHistory, count, active, className = '' }) {
    return (
        <a
            href="/history"
            onClick={(e) => { e.preventDefault(); onHistory(); }}
            aria-current={active ? 'page' : undefined}
            className={`flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-[15px] whitespace-nowrap transition-colors ${active ? 'bg-brand-50 text-brand-800 dark:bg-brand-950/60 dark:text-brand-200' : 'text-ink-700 hover:bg-ink-50 hover:text-ink-900 dark:text-ink-300 dark:hover:bg-ink-900 dark:hover:text-white'} ${className}`}
        >
            History
            {count > 0 && <span className="rounded-full bg-ink-100 px-1.5 text-[11.5px] text-ink-600 tabular-nums dark:bg-ink-800 dark:text-ink-300">{count}</span>}
        </a>
    );
}

export default function TopBar({ data, initialTarget, onAnalyze, onHome, onHistory, historyCount, page, ai, onModel, onAiRefresh }) {
    const [target, setTarget] = useState(initialTarget ?? '');
    useEffect(() => setTarget(initialTarget ?? ''), [initialTarget]);
    const at = stepIndex(data);
    const onHomePage = !data && page !== 'history';
    const [section, setSection] = useActiveSection(SECTION_IDS, onHomePage);

    // on the home page, scroll smoothly and highlight right away; from History the link loads the home page
    const jump = (e, id) => {
        const el = onHomePage && document.getElementById(id);
        if (!el) return;
        e.preventDefault();
        setSection(id);
        el.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        window.history.replaceState({}, '', `/#${id}`);
    };

    if (!data) {
        return (
            <header className="sticky top-0 z-20 border-b border-ink-200/70 bg-white/85 backdrop-blur-xl backdrop-saturate-150 [view-transition-name:topbar] dark:border-ink-800 dark:bg-ink-950/80">
                <div className="mx-auto flex h-[68px] max-w-[1360px] items-center gap-4 px-4 sm:px-6">
                    <Logo onHome={onHome} />
                    <nav aria-label="On this page" className="mx-auto hidden items-center gap-1 md:flex">
                        {LINKS.map(([label, href]) => {
                            const id = href.slice(2);
                            const on = section === id;
                            return (
                                <a
                                    key={href}
                                    href={href}
                                    onClick={(e) => jump(e, id)}
                                    aria-current={on ? 'location' : undefined}
                                    className={`rounded-lg px-3.5 py-2 text-[15px] transition-colors ${on ? 'bg-brand-50 font-medium text-brand-800 dark:bg-brand-950/60 dark:text-brand-200' : 'text-ink-700 hover:bg-ink-50 hover:text-ink-900 dark:text-ink-300 dark:hover:bg-ink-900 dark:hover:text-white'}`}
                                >
                                    {label}
                                </a>
                            );
                        })}
                        <HistoryLink onHistory={onHistory} count={historyCount} active={page === 'history'} />
                    </nav>
                    <div className="ml-auto flex items-center gap-3 md:ml-0">
                        <HistoryLink onHistory={onHistory} count={historyCount} active={page === 'history'} className="md:hidden" />
                        <AiState ai={ai} onRefresh={onAiRefresh} />
                        <ThemeToggle />
                    </div>
                </div>
            </header>
        );
    }

    return (
        <header className="sticky top-0 z-20 border-b border-ink-200/80 bg-white/80 backdrop-blur-xl backdrop-saturate-150 [view-transition-name:topbar] dark:border-ink-800 dark:bg-ink-900/75">
            <div className="mx-auto flex max-w-[1500px] flex-wrap items-center gap-3 px-4 py-2.5">
                <Logo onHome={onHome} />
                <form
                    className="flex min-w-0 flex-[1_1_420px] gap-2 max-sm:basis-full"
                    onSubmit={(e) => { e.preventDefault(); if (target.trim()) onAnalyze(target.trim()); }}
                >
                    <input
                        className="field min-w-0 flex-1"
                        placeholder="https://example.com  ·  /path/to/project  ·  /path/to/file.html"
                        aria-label="Website URL, project folder or file"
                        value={target}
                        onChange={(e) => setTarget(e.target.value)}
                    />
                    <button className="btn btn-primary">Analyse</button>
                </form>
                <HistoryLink onHistory={onHistory} className="!px-2.5 !text-sm" />
                <AiPill ai={ai} onModel={onModel} onRefresh={onAiRefresh} />
                <ThemeToggle />
            </div>
            <nav className="mx-auto flex max-w-[1500px] items-center overflow-x-auto px-4 pb-2.5" aria-label="Workflow">
                {STEPS.map((s, n) => (
                    <Fragment key={s}>
                        {n > 0 && (
                            <span aria-hidden className="relative mx-1 h-0.5 w-5 shrink-0 overflow-hidden rounded-full bg-ink-200 sm:w-8 dark:bg-ink-800">
                                <span className="bar-fill absolute inset-0 bg-emerald-500" style={{ '--w': n <= at ? 1 : 0 }} />
                            </span>
                        )}
                        <span
                            aria-current={n === at ? 'step' : undefined}
                            className={`flex items-center gap-1.5 rounded-full py-0.5 pr-2.5 pl-1 text-xs whitespace-nowrap transition-colors duration-300 ${n < at ? 'text-ink-500' : n === at ? 'bg-brand-50 text-brand-800 ring-1 ring-brand-200 dark:bg-brand-950/60 dark:text-brand-200 dark:ring-brand-800' : 'text-ink-400'}`}
                        >
                            <b className={`grid size-[18px] place-items-center rounded-full border text-[10px] transition-colors duration-300 ${n < at ? 'border-emerald-600 bg-emerald-600 text-white' : n === at ? 'border-brand-600 bg-brand-600 text-white' : 'border-ink-300 dark:border-ink-700'}`}>
                                {n < at ? <span key="done" className="animate-pop">✓</span> : n + 1}
                            </b>
                            {s}
                        </span>
                    </Fragment>
                ))}
            </nav>
        </header>
    );
}
