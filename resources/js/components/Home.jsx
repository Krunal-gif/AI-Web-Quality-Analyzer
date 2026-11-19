// Start screen: hero (analyse box + illustration), the five areas checked, recent analyses,
// how a review works, how the AI is used, the ways to apply fixes and the demo site.
import { useEffect, useState } from 'react';
import { MAX_PAGES, SITE_PAGES } from '../lib/api';
import AiOrb from './AiOrb';
import ScanDemo from './ScanDemo';

const Icon = ({ d, className = 'size-5', width = 1.8 }) => (
    <svg aria-hidden viewBox="0 0 24 24" className={className} fill="none" stroke="currentColor" strokeWidth={width} strokeLinecap="round" strokeLinejoin="round"><path d={d} /></svg>
);

const ICONS = {
    search: 'M10.5 17a6.5 6.5 0 1 0 0-13 6.5 6.5 0 0 0 0 13m4.6-1.9L20 20',
    access: 'M12 5.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3M5 8l7 1.5L19 8m-7 1.5V14l-3 6m3-6 3 6',
    code: 'M8 7 3 12l5 5m8-10 5 5-5 5M13.5 5l-3 14',
    doc: 'M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8zm0 0v5h5M9 13h6m-6 4h6',
    shield: 'M12 3 5 6v5c0 4.5 3 8.2 7 10 4-1.8 7-5.5 7-10V6zm-3 9 2 2 4-4',
    globe: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18M3 12h18M12 3c2.4 2.6 3.6 5.6 3.6 9s-1.2 6.4-3.6 9c-2.4-2.6-3.6-5.6-3.6-9S9.6 5.6 12 3',
    folder: 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
    link: 'M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1',
    arrow: 'M5 12h14m-6-6 6 6-6 6',
    chevron: 'm9 6 6 6-6 6',
    down: 'm6 9 6 6 6-6',
};

// literal class strings so Tailwind picks them up
const TONES = {
    rose: 'bg-rose-100 text-rose-600 dark:bg-rose-950/60 dark:text-rose-300',
    teal: 'bg-brand-100 text-brand-700 dark:bg-brand-950/70 dark:text-brand-300',
    violet: 'bg-violet-100 text-violet-600 dark:bg-violet-950/60 dark:text-violet-300',
    sky: 'bg-sky-100 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300',
    amber: 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
};

// rule counts match app/Analyzer/Rules/Catalog.php (59 rules; Code Quality = HTML Structure 9 + Code 9)
const AREAS = [
    { name: 'SEO', rules: 14, icon: 'search', tone: 'rose', text: 'Titles, meta tags, headings and links.' },
    { name: 'Accessibility', rules: 8, icon: 'access', tone: 'teal', text: 'Alt text, labels and WCAG basics.' },
    { name: 'Code Quality', rules: 18, icon: 'code', tone: 'violet', text: 'HTML, CSS, JS and PHP syntax.' },
    { name: 'Content', rules: 4, icon: 'doc', tone: 'sky', text: 'Placeholder text and long sentences.' },
    { name: 'Security', rules: 15, icon: 'shield', tone: 'amber', text: 'Leaked keys, security headers and cookies.' },
];

const MODES = [
    { key: 'url', label: 'Website URL', icon: 'globe', placeholder: 'https://example.com', hint: 'Enter a website URL to check that page, or choose Whole site to read the sitemap first and analyse every page in it one by one. A sitemap.xml URL works too.' },
    { key: 'dir', label: 'Project Folder', icon: 'folder', placeholder: '/path/to/project', hint: 'Enter the full path of a folder on this computer. Every page and code file inside it is checked.' },
    { key: 'file', label: 'Single File', icon: 'doc', placeholder: '/path/to/file.html', hint: 'Enter the full path of one HTML, CSS, JS, PHP or JSON file on this computer.' },
];

const STEPS = [
    { title: 'Add your target', tone: 'sky', text: 'Enter a website URL, a project folder or a single file.' },
    { title: 'Detect issues', tone: 'teal', text: '59 rules check SEO, accessibility, HTML, content, code and security, down to the exact line.' },
    { title: 'Get AI explanation', tone: 'violet', text: 'Each issue is explained in simple words: what is wrong, why it matters and how to fix it.' },
    { title: 'Review and apply fixes', tone: 'amber', text: 'Compare the code before and after, approve the fixes you want and apply them. Originals are backed up.' },
];

const SAFETY = [
    'It sees one issue and a few lines around it, never your whole project',
    'It may only change the exact code the rule flagged',
    'Answers that are empty, unchanged or far too large are thrown away',
    'Indentation is matched to your file',
    'Nothing is written until you approve it',
];

const APPLY = [
    { title: 'Write fix to file', badge: 'One at a time', text: 'Click it on any issue to change that exact spot in the file straight away. Keep going with the next one; the tool keeps track of the new line numbers.', tone: 'brand' },
    { title: 'Prepare a patch', badge: 'Default', text: 'Approved fixes go into fixed copies and a changes.patch file. Your originals stay untouched. The only option for live websites.', tone: 'ink' },
    { title: 'Write all to project files', badge: 'All at once', text: 'Every approved fix is written into your files in one go, with backups of the originals. Re-run the analysis to continue afterwards.', tone: 'ink' },
];

function Eyebrow({ children, className = '' }) {
    return <p className={`text-[13px] font-semibold tracking-[0.22em] text-ink-600 uppercase dark:text-ink-400 ${className}`}>{children}</p>;
}

function SectionHead({ id, eyebrow, title, text, action }) {
    return (
        <div className="reveal mb-10 flex flex-wrap items-end justify-between gap-4">
            <div className="max-w-2xl">
                <Eyebrow>{eyebrow}</Eyebrow>
                <h2 id={id} className="mt-2 scroll-mt-28 font-display text-[clamp(2rem,4vw,2.75rem)] leading-[1.08] font-extrabold tracking-[-0.035em] text-ink-900 dark:text-white">{title}</h2>
                {text && <p className="mt-3 text-[17px] leading-relaxed text-ink-600 dark:text-ink-400">{text}</p>}
            </div>
            {action}
        </div>
    );
}

const OllamaIcon = () => (
    <svg aria-hidden viewBox="0 0 24 24" className="size-7 shrink-0 text-ink-900 dark:text-ink-100" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
        <path d="M9 9.4V4.6a1.3 1.3 0 0 1 2.6 0v3.6m.8 0V4.6a1.3 1.3 0 0 1 2.6 0v4.8M6.8 13.5c0-3 2.3-5.3 5.2-5.3s5.2 2.3 5.2 5.3v3.7c0 2-1.6 3.8-3.6 3.8H10.4c-2 0-3.6-1.7-3.6-3.8z" />
        <ellipse cx="12" cy="17" rx="2.1" ry="1.5" />
        <circle cx="9.7" cy="13.3" r=".5" fill="currentColor" />
        <circle cx="14.3" cy="13.3" r=".5" fill="currentColor" />
    </svg>
);

/** Model picker: shows "Ollama / model"; a native <select> sits on top so keyboard and screen readers work as usual. */
function ModelPicker({ ai, onModel, onRefresh }) {
    const ready = ai?.online;
    const models = ready ? [...new Set([ai.model, ...ai.models])] : [];
    const sub = !ai ? 'Checking…' : ready ? ai.model : 'Offline · built-in rules';
    const box = 'relative flex h-14 items-center gap-3 rounded-xl border border-ink-200 bg-white pr-3 pl-3.5 text-left transition-[border-color,box-shadow] dark:border-ink-700 dark:bg-ink-900';

    if (!ready) {
        return (
            <button type="button" onClick={onRefresh} className={`${box} hover:border-ink-300`} title={ai?.error || 'Re-check Ollama'}>
                <OllamaIcon />
                <span className="min-w-0 flex-1 leading-tight">
                    <span className="block text-[15px] text-ink-900 dark:text-ink-100">Ollama</span>
                    <span className="block truncate text-[13px] text-ink-500 dark:text-ink-400">{sub}</span>
                </span>
                <span aria-hidden className="text-ink-500">↻</span>
            </button>
        );
    }
    return (
        <label className={`${box} focus-within:border-brand-500 focus-within:ring-4 focus-within:ring-brand-500/15 hover:border-ink-300`}>
            <OllamaIcon />
            <span className="min-w-0 flex-1 leading-tight">
                <span className="block text-[15px] text-ink-900 dark:text-ink-100">Ollama</span>
                <span className="block truncate text-[13px] text-ink-500 dark:text-ink-400">{sub}</span>
            </span>
            <Icon d={ICONS.down} className="size-4 shrink-0 text-ink-600 dark:text-ink-300" width={2} />
            <select aria-label="Ollama model" className="absolute inset-0 cursor-pointer opacity-0" value={ai.model} onChange={(e) => onModel(e.target.value)}>
                {models.map((m) => <option key={m} value={m}>{m}</option>)}
            </select>
        </label>
    );
}

function AnalyseCard({ ai, demo, onAnalyze, onModel, onAiRefresh }) {
    const [mode, setMode] = useState('url');
    const [target, setTarget] = useState('');
    const [wholeSite, setWholeSite] = useState(false);
    const [pages, setPages] = useState(SITE_PAGES);
    const m = MODES.find((x) => x.key === mode);
    const pageCount = mode === 'url' && wholeSite ? Math.min(MAX_PAGES, Math.max(1, Number(pages) || SITE_PAGES)) : 1;

    return (
        <form
            className="mt-9 rounded-2xl border border-ink-200/80 bg-white p-3 shadow-[0_24px_60px_-30px_rgb(16_35_63/0.25)] sm:p-4 dark:border-ink-800 dark:bg-ink-900/80 dark:shadow-black/40"
            onSubmit={(e) => { e.preventDefault(); if (target.trim()) onAnalyze(target.trim(), pageCount); }}
        >
            <div className="flex flex-wrap gap-1.5" role="group" aria-label="What to analyse">
                {MODES.map((x) => (
                    <button
                        key={x.key}
                        type="button"
                        aria-pressed={mode === x.key}
                        onClick={() => setMode(x.key)}
                        className={`flex flex-1 items-center justify-center gap-2 rounded-xl border px-3 py-2.5 text-[14.5px] whitespace-nowrap transition-colors sm:flex-none sm:px-5 ${mode === x.key ? 'border-brand-200 bg-brand-50 font-medium text-ink-900 dark:border-brand-800 dark:bg-brand-950/70 dark:text-white' : 'border-transparent bg-ink-50 text-ink-700 hover:bg-ink-100 dark:bg-ink-800/60 dark:text-ink-300 dark:hover:bg-ink-800'}`}
                    >
                        <Icon d={ICONS[x.icon]} className={`size-5 ${mode === x.key ? 'text-brand-600 dark:text-brand-400' : 'text-ink-500'}`} width={1.7} />
                        <span className="max-sm:sr-only">{x.label}</span>
                    </button>
                ))}
            </div>

            <div className="mt-3 grid gap-2.5 md:grid-cols-[minmax(0,1fr)_13.5rem_auto]">
                <label className="relative flex h-14 items-center rounded-xl border border-ink-200 bg-white transition-[border-color,box-shadow] focus-within:border-brand-500 focus-within:ring-4 focus-within:ring-brand-500/15 dark:border-ink-700 dark:bg-ink-900">
                    <Icon d={ICONS.link} className="pointer-events-none absolute left-4 size-5 text-ink-500" />
                    <input
                        className="h-full w-full rounded-xl bg-transparent pr-4 pl-12 text-[15.5px] text-ink-900 outline-none placeholder:text-ink-400 dark:text-ink-100"
                        placeholder={m.placeholder}
                        aria-label={m.label}
                        value={target}
                        onChange={(e) => setTarget(e.target.value)}
                        autoComplete="off"
                        spellCheck={false}
                    />
                </label>
                <ModelPicker ai={ai} onModel={onModel} onRefresh={onAiRefresh} />
                <button className="flex h-14 items-center justify-center gap-2.5 rounded-xl bg-brand-700 px-8 text-[16px] font-semibold text-white shadow-[inset_0_1px_0_rgb(255_255_255/0.18),0_10px_24px_-10px_rgb(0_127_108/0.7)] transition-[background-color,transform] hover:bg-brand-800 active:scale-[0.98]">
                    Analyse <Icon d={ICONS.arrow} className="size-5" width={2} />
                </button>
            </div>

            {mode === 'url' && (
                <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 px-1 text-[13.5px] text-ink-700 dark:text-ink-300">
                    <div className="flex rounded-lg bg-ink-100 p-0.5 dark:bg-ink-800" role="group" aria-label="How much of the site">
                        {[[false, 'This page only'], [true, 'Whole site (sitemap)']].map(([v, label]) => (
                            <button
                                key={label}
                                type="button"
                                aria-pressed={wholeSite === v}
                                onClick={() => setWholeSite(v)}
                                className={`rounded-md px-3 py-1 transition-colors ${wholeSite === v ? 'bg-white font-medium text-ink-900 shadow-sm dark:bg-ink-950 dark:text-white' : 'text-ink-600 hover:text-ink-900 dark:text-ink-400 dark:hover:text-white'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    {wholeSite && (
                        <label className="flex items-center gap-2">
                            Up to
                            <input
                                type="number"
                                min={1}
                                max={MAX_PAGES}
                                value={pages}
                                onChange={(e) => setPages(e.target.value)}
                                className="field h-8 w-20 py-0 tabular-nums"
                                aria-label="Maximum pages to analyse"
                            />
                            pages
                        </label>
                    )}
                </div>
            )}

            <p className="mt-3 px-1 text-[13.5px] leading-relaxed text-ink-600 dark:text-ink-400">
                {m.hint}
                {demo && <> No target handy? <button type="button" className="font-medium text-brand-700 underline-offset-2 hover:underline dark:text-brand-300" onClick={demo}>Analyse the demo site</button>.</>}
            </p>
        </form>
    );
}

function FloatCard({ icon, tone, title, text, className, delay }) {
    return (
        <div className={`absolute z-10 flex w-[212px] animate-float items-start gap-3 rounded-2xl border border-ink-100 bg-white p-3.5 shadow-[0_18px_40px_-18px_rgb(16_35_63/0.28)] dark:border-ink-800 dark:bg-ink-900 ${className}`} style={{ animationDelay: delay }}>
            <span className={`grid size-10 shrink-0 place-items-center rounded-xl ${TONES[tone]}`}><Icon d={ICONS[icon]} /></span>
            <span className="leading-tight">
                <b className="block text-[14px] font-semibold text-ink-900 dark:text-white">{title}</b>
                <span className="mt-1 block text-[12px] leading-snug text-ink-500 dark:text-ink-400">{text}</span>
            </span>
        </div>
    );
}

/** Decorative hero art: a web page being checked, with the five areas floating around it. */
function HeroArt() {
    const line = 'block h-2 rounded-full bg-ink-100 dark:bg-ink-800';
    return (
        <div aria-hidden className="relative mx-auto hidden h-[460px] w-full max-w-[560px] lg:block">
            <span className="absolute top-0 right-2 size-[420px] rounded-full bg-gradient-to-br from-brand-100 via-brand-50 to-transparent dark:from-brand-950 dark:via-brand-950/40" />
            <span className="hero-dots absolute top-14 left-0 h-28 w-28" />
            <span className="hero-dots absolute right-0 bottom-20 h-28 w-16" />

            <div className="absolute top-12 right-[13%] left-[13%] h-[330px] overflow-hidden rounded-2xl border border-ink-200/80 bg-white shadow-[0_30px_70px_-30px_rgb(16_35_63/0.35)] dark:border-ink-800 dark:bg-ink-900">
                <div className="flex items-center gap-3 border-b border-ink-100 px-4 py-3 dark:border-ink-800">
                    <span className="flex gap-1.5"><i className="size-2.5 rounded-full bg-[#ff5f57]" /><i className="size-2.5 rounded-full bg-[#febc2e]" /><i className="size-2.5 rounded-full bg-[#28c840]" /></span>
                    <span className="h-3 flex-1 rounded-full bg-ink-100 dark:bg-ink-800" />
                </div>
                <div className="grid grid-cols-[0.8fr_1.2fr] gap-5 p-5">
                    <div className="grid content-start gap-3 pt-2">
                        <span className={`${line} w-3/4`} /><span className={`${line} w-full`} /><span className={`${line} w-2/3`} />
                        <span className={`${line} mt-6 w-full`} /><span className={`${line} w-5/6`} /><span className={`${line} w-3/5`} />
                        <span className="mt-5 block h-7 w-24 rounded-lg bg-brand-100 dark:bg-brand-950" />
                    </div>
                    <div>
                        <div className="relative h-28 overflow-hidden rounded-lg bg-indigo-50 dark:bg-indigo-950/50">
                            <span className="absolute top-5 right-8 size-6 rounded-full bg-indigo-200 dark:bg-indigo-800" />
                            <svg viewBox="0 0 200 100" preserveAspectRatio="none" className="absolute inset-x-0 bottom-0 h-20 w-full text-indigo-200 dark:text-indigo-800"><path d="M0 100 70 30l45 45 25-20 60 45z" fill="currentColor" /></svg>
                        </div>
                        <span className="mt-4 block h-2.5 w-2/3 rounded-full bg-indigo-100 dark:bg-indigo-900/60" />
                        <span className={`${line} mt-4 w-full`} /><span className={`${line} mt-2.5 w-5/6`} /><span className={`${line} mt-2.5 w-11/12`} />
                        <div className="mt-5 grid grid-cols-3 gap-2"><span className="h-12 rounded-lg bg-ink-50 dark:bg-ink-800" /><span className="h-12 rounded-lg bg-ink-50 dark:bg-ink-800" /><span className="h-12 rounded-lg bg-ink-50 dark:bg-ink-800" /></div>
                    </div>
                </div>
            </div>

            <FloatCard className="top-0 right-0" icon="search" tone="teal" title="SEO" text="Find missing meta, headings and more." delay="0s" />
            <FloatCard className="top-[38%] -left-4" icon="code" tone="violet" title="Code Quality" text="Detect errors and bad practices." delay="-1.5s" />
            <FloatCard className="top-[46%] -right-6" icon="access" tone="teal" title="Accessibility" text="Check WCAG issues and usability." delay="-3s" />
            <FloatCard className="bottom-2 left-[22%]" icon="doc" tone="sky" title="Content" text="Find missing or weak content." delay="-4.5s" />
            <FloatCard className="right-[-8px] -bottom-4" icon="shield" tone="teal" title="Security" text="Detect security risks." delay="-2.2s" />
        </div>
    );
}

export default function Home({ home, ai, onAnalyze, onModel, onAiRefresh }) {
    const demo = home?.demo ? () => onAnalyze(home.demo) : null;
    // a /#section link (e.g. from the History page) arrives before React has drawn the section, so scroll once it exists
    useEffect(() => {
        const id = window.location.hash.slice(1);
        if (id) document.getElementById(id)?.scrollIntoView();
    }, []);
    const aiReady = ai?.online && ai?.model_installed;
    const wrap = 'mx-auto max-w-[1360px] px-4 sm:px-6';

    return (
        <div className="relative isolate overflow-x-clip bg-white dark:bg-ink-950">
            {/* ---------------- hero ---------------- */}
            <section className="bg-gradient-to-b from-ink-50/70 to-white dark:from-ink-900/40 dark:to-ink-950">
                <div className={`${wrap} grid items-center gap-10 pt-12 pb-14 sm:pt-16 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)] lg:gap-8`}>
                    <div className="animate-rise">
                        <Eyebrow>AI-powered website analysis</Eyebrow>
                        <h1 className="mt-4 font-display text-[clamp(2.9rem,6.4vw,4.9rem)] leading-[1.02] font-extrabold tracking-[-0.045em] text-ink-900 dark:text-white">
                            Find issues.<br />
                            <span className="text-brand-600 dark:text-brand-400">Get clear</span> fixes.
                        </h1>
                        <p className="mt-6 max-w-[56ch] text-[clamp(1.05rem,1.4vw,1.25rem)] leading-relaxed text-ink-600 dark:text-ink-300">
                            Analyse any website, project folder or single file. WebQA finds SEO, accessibility, HTML, content,
                            code and security issues, explains each one in plain words and suggests a fix using local AI.
                        </p>
                        <a
                            href="#analyse"
                            onClick={(e) => {
                                e.preventDefault();
                                document.getElementById('analyse')?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
                                setTimeout(() => document.querySelector('#analyse input')?.focus({ preventScroll: true }), 450);
                            }}
                            className="mt-8 inline-flex h-12 items-center gap-2.5 rounded-xl bg-brand-700 px-7 text-[15.5px] font-semibold text-white shadow-[inset_0_1px_0_rgb(255_255_255/0.18),0_10px_24px_-10px_rgb(0_127_108/0.7)] transition-[background-color,transform] hover:bg-brand-800 active:scale-[0.98]"
                        >
                            Start analysing <Icon d={ICONS.down} className="size-5" width={2} />
                        </a>
                    </div>
                    <HeroArt />
                </div>

                {/* ---------------- the five areas ---------------- */}
                <div className={wrap}>
                    <ul id="features" aria-label="What WebQA checks" className="grid scroll-mt-28 gap-y-6 rounded-3xl border border-ink-100 bg-ink-50 px-6 py-7 sm:grid-cols-2 lg:grid-cols-5 lg:gap-0 lg:divide-x lg:divide-ink-200 dark:border-ink-800 dark:bg-ink-900/60 dark:lg:divide-ink-800">
                        {AREAS.map((a) => (
                            <li key={a.name} className="flex items-start gap-4 lg:px-5 lg:first:pl-1 lg:last:pr-1">
                                <span className={`grid size-14 shrink-0 place-items-center rounded-full ${TONES[a.tone]}`}><Icon d={ICONS[a.icon]} className="size-6" /></span>
                                <span>
                                    <b className="block text-[17px] font-semibold text-ink-900 dark:text-white">{a.name}</b>
                                    <span className="mt-1 block text-[14.5px] leading-snug text-ink-600 dark:text-ink-400">{a.text}</span>
                                    <span className="mt-1.5 block text-[12.5px] font-medium text-ink-500 tabular-nums">{a.rules} rules</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>

                {/* ---------------- analyse box ---------------- */}
                <div id="analyse" className={`${wrap} scroll-mt-24 pb-16`}>
                    <AnalyseCard ai={ai} demo={demo} onAnalyze={onAnalyze} onModel={onModel} onAiRefresh={onAiRefresh} />
                </div>
            </section>

            {/* ---------------- how it works ---------------- */}
            <section className="rounded-t-[2.5rem] bg-ink-50 py-20 dark:bg-ink-900/50">
                <div className={wrap}>
                    <SectionHead
                        id="how"
                        eyebrow="Simple process"
                        title="How it works"
                        action={<a href="#apply" className="flex items-center gap-1.5 text-[16px] font-medium text-brand-700 hover:underline dark:text-brand-300">See how fixes are applied <Icon d={ICONS.arrow} width={2} /></a>}
                    />
                    <ol className="grid gap-4 lg:grid-cols-[1fr_auto_1fr_auto_1fr_auto_1fr] lg:gap-3">
                        {STEPS.flatMap((s, n) => [
                            n > 0 && <li key={`c${n}`} aria-hidden className="hidden place-items-center text-ink-400 lg:grid"><Icon d={ICONS.chevron} /></li>,
                            <li key={s.title} className="reveal flex gap-4 rounded-2xl border border-ink-100 bg-white p-6 shadow-[0_1px_2px_rgb(16_35_63/0.04)] dark:border-ink-800 dark:bg-ink-900">
                                <span className={`grid size-12 shrink-0 place-items-center rounded-full font-display text-[18px] font-bold tabular-nums ${TONES[s.tone]}`}>{String(n + 1).padStart(2, '0')}</span>
                                <div>
                                    <h3 className="text-[16px] font-semibold text-ink-900 dark:text-white">{s.title}</h3>
                                    <p className="mt-1.5 text-[14.5px] leading-relaxed text-ink-600 dark:text-ink-400">{s.text}</p>
                                </div>
                            </li>,
                        ])}
                    </ol>
                </div>
            </section>

            {/* ---------------- the AI ---------------- */}
            <section className="bg-ink-50 pb-20 dark:bg-ink-900/50">
                <div className={wrap}>
                    <div className="reveal relative overflow-hidden rounded-3xl border-aura bg-white p-6 sm:p-10 dark:bg-ink-900">
                        <div className="grid gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,460px)]">
                            <div>
                                <Eyebrow>Local AI</Eyebrow>
                                <div className="mt-2 flex items-center gap-3">
                                    <AiOrb size={40} />
                                    <h2 id="ai" className="scroll-mt-28 font-display text-[clamp(1.8rem,3vw,2.4rem)] leading-tight font-extrabold tracking-[-0.03em] text-ink-900 dark:text-white">
                                        How the AI helps
                                    </h2>
                                </div>
                                <p className="mt-4 max-w-[58ch] text-[16px] leading-relaxed text-ink-600 dark:text-ink-300">
                                    A local language model, run by Ollama on this computer, writes explanations and fixes. The default
                                    is <code className="font-mono text-[14px]">qwen2.5-coder:7b</code>, a model trained on code. It's
                                    optional: finding issues, scoring and applying fixes are all done by the rules, without AI.
                                </p>
                                <h3 className="mt-7 text-[15px] font-semibold text-ink-900 dark:text-white">Guard rails on every answer</h3>
                                <ul className="mt-3 grid gap-2">
                                    {SAFETY.map((t) => (
                                        <li key={t} className="flex gap-2.5 text-[14.5px] text-ink-600 dark:text-ink-300">
                                            <Icon d="M5 12.5 10 17.5 19 7" className="mt-0.5 size-4.5 shrink-0 text-brand-600 dark:text-brand-400" width={2.4} />
                                            {t}
                                        </li>
                                    ))}
                                </ul>
                                <p className="mt-6 text-[13.5px] text-ink-500 dark:text-ink-400">
                                    {aiReady
                                        ? <>Connected to {ai.host}. Choose another model in the box at the top of the page.</>
                                        : <>To turn it on, install Ollama and run <code className="font-mono">ollama pull qwen2.5-coder:3b</code> (a lighter model that suits computers without a graphics card), then click the Ollama box at the top of the page.</>}
                                </p>
                            </div>

                            <div className="grid content-start gap-3 font-mono text-[12.5px]">
                                <div className="rounded-2xl bg-ink-900 p-4 text-ink-200 shadow-xl dark:bg-ink-950">
                                    <p className="mb-2 font-sans text-[11.5px] font-semibold text-ink-400">What the model is sent</p>
                                    <p><span className="text-brand-300">Issue:</span> Image without alt text</p>
                                    <p><span className="text-brand-300">File:</span> index.html, line 9</p>
                                    <p className="mt-1.5 text-ink-400">&lt;a href="/"&gt;<span className="rounded bg-amber-400/20 text-amber-200">&lt;img src="acme-logo.png"&gt;</span>&lt;/a&gt;</p>
                                </div>
                                <div className="ml-6 rounded-2xl border-aura p-4 shadow-xl">
                                    <p className="mb-2 flex items-center gap-2 font-sans text-[11.5px] font-semibold"><AiOrb size={14} state="thinking" /><span className="text-aura">What it answers</span></p>
                                    <p className="text-ink-700 dark:text-ink-200"><span className="text-brand-700 dark:text-brand-300">why:</span> the logo link has no name for screen readers</p>
                                    <p className="mt-1 text-emerald-700 dark:text-emerald-300">&lt;img src="acme-logo.png" alt="Acme Plumbing logo"&gt;</p>
                                    <p className="mt-1 text-ink-500">confidence: high</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {/* ---------------- applying fixes ---------------- */}
            <section className={`${wrap} py-20`}>
                <SectionHead id="apply" eyebrow="Your choice" title="Three ways to apply a fix" text="Pick what suits the moment. Whichever you choose, the changed code is scanned again to confirm the problem is gone." />
                <div className="grid gap-4 lg:grid-cols-3">
                    {APPLY.map((a) => (
                        <article key={a.title} className={`reveal rounded-2xl border p-6 ${a.tone === 'brand' ? 'border-brand-200 bg-brand-50 dark:border-brand-800 dark:bg-brand-950/50' : 'border-ink-100 bg-ink-50 dark:border-ink-800 dark:bg-ink-900/60'}`}>
                            <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${a.tone === 'brand' ? 'bg-brand-700 text-white' : 'bg-white text-ink-600 ring-1 ring-ink-200 dark:bg-ink-800 dark:text-ink-300 dark:ring-ink-700'}`}>{a.badge}</span>
                            <h3 className="mt-4 text-[17px] font-semibold text-ink-900 dark:text-white">{a.title}</h3>
                            <p className="mt-1.5 text-[14.5px] leading-relaxed text-ink-600 dark:text-ink-300">{a.text}</p>
                        </article>
                    ))}
                </div>

                {/* ---------------- demo ---------------- */}
                <div className="reveal mt-16 grid items-center gap-12 overflow-hidden rounded-3xl bg-ink-900 px-6 pt-10 pb-16 text-white sm:px-10 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)] lg:py-14 dark:bg-ink-900 dark:ring-1 dark:ring-ink-800">
                    <div>
                        <Eyebrow className="!text-brand-300">See it in action</Eyebrow>
                        <h2 className="mt-2 font-display text-[clamp(1.8rem,3vw,2.4rem)] leading-tight font-extrabold tracking-[-0.03em]">Scan, explain, fix, verify.</h2>
                        <p className="mt-3 max-w-[46ch] text-[16px] leading-relaxed text-ink-300">
                            {demo
                                ? 'Try it on the demo site: a small plumbing website with 49 deliberate problems. Analysing it changes nothing on your computer.'
                                : 'Each issue is flagged on its exact line, explained, fixed with your approval and scanned again to prove the fix worked.'}
                        </p>
                        {demo && (
                            <button className="mt-7 flex items-center gap-2.5 rounded-xl bg-brand-700 px-6 py-3.5 text-[15px] font-semibold text-white transition-[background-color,transform] hover:bg-brand-800 active:scale-[0.98]" onClick={demo}>
                                Analyse the demo site <Icon d={ICONS.arrow} width={2} />
                            </button>
                        )}
                        {demo && <p className="mt-3 truncate font-mono text-xs text-ink-400" title={home.demo}>{home.demo}</p>}
                    </div>
                    <div className="text-ink-900 dark:text-ink-100">
                        <ScanDemo />
                    </div>
                </div>
            </section>
        </div>
    );
}
