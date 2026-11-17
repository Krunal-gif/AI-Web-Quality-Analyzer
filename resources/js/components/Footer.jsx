// Site footer. Full version on the home page; `compact` on the review screen (a single row).
import AiOrb from './AiOrb';

const RESOURCES = [
    ['Source code on GitHub', 'https://github.com/Krunal-gif/AI-Web-Quality-Analyzer'],
    ['Ollama (local AI)', 'https://ollama.com'],
    ['Laravel documentation', 'https://laravel.com/docs'],
];

function AiLine({ ai }) {
    const ready = ai?.online && ai?.model_installed;
    return (
        <span className="flex items-center gap-2">
            <AiOrb size={12} state={!ai ? 'thinking' : ready ? 'idle' : 'off'} />
            {!ai ? 'Checking for local AI…' : ready ? `AI: ${ai.model}` : ai.online ? `AI: ${ai.model} not downloaded` : 'AI off: built-in explanations'}
        </span>
    );
}

export default function Footer({ ai, compact = false, onDemo }) {
    const year = new Date().getFullYear();

    if (compact) {
        return (
            <footer className="mx-auto flex max-w-[1500px] flex-wrap items-center justify-between gap-x-6 gap-y-2 border-t border-ink-200/80 px-4 pt-5 pb-28 text-[12.5px] text-ink-500 dark:border-ink-800">
                <span>WebQA, AI Web Quality Analyzer. Analyses run on this computer.</span>
                <span className="flex flex-wrap items-center gap-x-5 gap-y-1">
                    <AiLine ai={ai} />
                    <a className="hover:text-brand-700 dark:hover:text-brand-300" href={RESOURCES[0][1]} target="_blank" rel="noopener noreferrer">GitHub ↗</a>
                    <span>© {year} Krunal Bhavsar</span>
                </span>
            </footer>
        );
    }

    return (
        <footer className="border-t border-ink-200/80 bg-ink-50 dark:border-ink-800 dark:bg-ink-950">
            <div className="mx-auto grid max-w-[1180px] gap-10 px-4 py-14 sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1.2fr]">
                <div>
                    <span className="flex items-center gap-2 font-semibold tracking-tight">
                        <span className="grid size-8 place-items-center rounded-[9px] bg-gradient-to-br from-brand-600 to-brand-800 text-[11px] font-bold text-white">QA</span>
                        <span className="text-[17px] font-extrabold">Web<span className="text-brand-600 dark:text-brand-400">QA</span></span>
                    </span>
                    <p className="mt-3 max-w-[34ch] text-[14px] leading-relaxed text-ink-500 dark:text-ink-400">
                        Finds website and code problems, explains them in plain words and fixes only what you approve.
                    </p>
                </div>

                <nav aria-label="On this page">
                    <h2 className="text-[13px] font-semibold text-ink-900 dark:text-ink-100">On this page</h2>
                    <ul className="mt-3 grid gap-2 text-[14px] text-ink-500 dark:text-ink-400">
                        <li><a className="hover:text-brand-700 dark:hover:text-brand-300" href="/#features">What it checks</a></li>
                        <li><a className="hover:text-brand-700 dark:hover:text-brand-300" href="/#how">How it works</a></li>
                        <li><a className="hover:text-brand-700 dark:hover:text-brand-300" href="/#ai">How the AI helps</a></li>
                        <li><a className="hover:text-brand-700 dark:hover:text-brand-300" href="/#apply">Applying fixes</a></li>
                        <li><a className="hover:text-brand-700 dark:hover:text-brand-300" href="/history">History</a></li>
                        {onDemo && <li><button className="hover:text-brand-700 dark:hover:text-brand-300" onClick={onDemo}>Analyse the demo site</button></li>}
                    </ul>
                </nav>

                <nav aria-label="Resources">
                    <h2 className="text-[13px] font-semibold text-ink-900 dark:text-ink-100">Resources</h2>
                    <ul className="mt-3 grid gap-2 text-[14px] text-ink-500 dark:text-ink-400">
                        {RESOURCES.map(([label, href]) => (
                            <li key={href}><a className="hover:text-brand-700 dark:hover:text-brand-300" href={href} target="_blank" rel="noopener noreferrer">{label} ↗</a></li>
                        ))}
                    </ul>
                </nav>

                <div>
                    <h2 className="text-[13px] font-semibold text-ink-900 dark:text-ink-100">On this computer</h2>
                    <ul className="mt-3 grid gap-2 text-[14px] text-ink-500 dark:text-ink-400">
                        <li><AiLine ai={ai} /></li>
                        <li>Analyses saved in <code className="font-mono text-[12.5px]">storage/app/webqa</code></li>
                        <li>Laravel 13, React 19, Tailwind CSS 4</li>
                    </ul>
                </div>
            </div>
            <div className="border-t border-ink-200/80 dark:border-ink-800">
                <div className="mx-auto flex max-w-[1180px] flex-wrap justify-between gap-2 px-4 py-5 text-[12.5px] text-ink-500">
                    <span>© {year} Krunal Bhavsar. Released under the MIT License.</span>
                    <span>Your code is analysed on this computer, not in the cloud.</span>
                </div>
            </div>
        </footer>
    );
}
