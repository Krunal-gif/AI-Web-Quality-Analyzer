// Home-page hero: a looping, CSS-only replay of the workflow on a tiny file — the scanner sweeps,
// two lines are flagged, the fixes are struck through / inserted and the score climbs.
// Timings live in app.css (.scan-demo); `late` offsets the second issue by 1.2s.
import AiOrb from './AiOrb';

const Tag = ({ children }) => <span className="text-brand-700 dark:text-brand-300">{children}</span>;
const Attr = ({ children }) => <span className="text-sky-700 dark:text-sky-300">{children}</span>;
const Str = ({ children }) => <span className="text-emerald-700 dark:text-emerald-300">{children}</span>;

function Row({ n, className = '', children }) {
    return (
        <div className={`grid grid-cols-[2.25rem_minmax(0,1fr)] ${className}`}>
            <span className="pr-3 text-right text-ink-400 select-none dark:text-ink-600">{n}</span>
            <span className="truncate pr-3">{children}</span>
        </div>
    );
}

function Insert({ late = false, children }) {
    return (
        <div className={`insert grid grid-cols-[2.25rem_minmax(0,1fr)] bg-emerald-500/12 shadow-[inset_3px_0_0_var(--color-emerald-500)] ${late ? 'late' : ''}`}>
            <span className="pr-3 text-right text-emerald-600 select-none">+</span>
            <span className="truncate pr-3">{children}</span>
        </div>
    );
}

export default function ScanDemo() {
    return (
        <figure className="scan-demo relative" aria-label="Animated example: a file is scanned, two issues are flagged and fixed, and the score rises from 58 to 96">
            <div className="overflow-hidden rounded-2xl border border-ink-200 bg-white shadow-[0_24px_60px_-20px_rgb(12_40_52/0.25)] dark:border-ink-800 dark:bg-ink-900 dark:shadow-black/40">
                <div className="flex items-center gap-2 border-b border-ink-200 px-4 py-2.5 text-[13px] dark:border-ink-800">
                    <span className="flex gap-1.5" aria-hidden>
                        <i className="size-2.5 rounded-full bg-ink-200 dark:bg-ink-700" />
                        <i className="size-2.5 rounded-full bg-ink-200 dark:bg-ink-700" />
                        <i className="size-2.5 rounded-full bg-ink-200 dark:bg-ink-700" />
                    </span>
                    <span className="ml-1 font-mono text-xs text-ink-500">demo-site/index.html</span>
                    <span className="ml-auto flex items-baseline gap-1 text-ink-500">
                        score <b className="score font-mono text-base tabular-nums" />
                    </span>
                </div>

                <div className="relative py-3 font-mono text-[12.5px] leading-[1.75] sm:text-[13px]" aria-hidden>
                    <span className="beam pointer-events-none absolute inset-x-0 z-10 h-8 -translate-y-full border-b-2 border-beam bg-gradient-to-b from-transparent to-beam/25" />
                    <Row n="1"><Tag>&lt;html</Tag> <Attr>lang</Attr>=<Str>"en"</Str><Tag>&gt;</Tag></Row>
                    <Row n="2"><Tag>&lt;head&gt;</Tag></Row>
                    <Row n="3" className="flag">
                        {'  '}<span className="old"><Tag>&lt;title&gt;&lt;/title&gt;</Tag></span>
                    </Row>
                    <Insert>{'  '}<Tag>&lt;title&gt;</Tag>Acme — Handmade ceramics<Tag>&lt;/title&gt;</Tag></Insert>
                    <Row n="4"><Tag>&lt;/head&gt;</Tag></Row>
                    <Row n="5"><Tag>&lt;body&gt;</Tag></Row>
                    <Row n="6" className="flag late">
                        {'  '}<span className="old"><Tag>&lt;img</Tag> <Attr>src</Attr>=<Str>"hero.jpg"</Str><Tag>&gt;</Tag></span>
                    </Row>
                    <Insert late>{'  '}<Tag>&lt;img</Tag> <Attr>src</Attr>=<Str>"hero.jpg"</Str> <Attr>alt</Attr>=<Str>"Glazed bowls on a shelf"</Str><Tag>&gt;</Tag></Insert>
                    <Row n="7">{'  '}<Tag>&lt;h1&gt;</Tag>Made by hand in Leeds<Tag>&lt;/h1&gt;</Tag></Row>
                </div>

                <div className="relative h-11 border-t border-ink-200 text-[13px] dark:border-ink-800" aria-hidden>
                    <p className="phase phase-1 absolute inset-0 flex items-center gap-2 px-4 text-ink-500">
                        <span className="size-2 animate-pulse rounded-full bg-beam" /> Scanning 7 lines…
                    </p>
                    <p className="phase phase-2 absolute inset-0 flex items-center gap-2 px-4 text-amber-700 dark:text-amber-400">
                        <span className="size-2 rounded-full bg-amber-500" /> 2 issues: missing page title, image without alt text
                    </p>
                    <p className="phase phase-3 absolute inset-0 flex items-center gap-2 px-4 text-emerald-700 dark:text-emerald-400">
                        <span className="size-2 rounded-full bg-emerald-500" /> 2 fixes approved and verified by re-scan
                    </p>
                </div>
            </div>

            {/* the model's explanation, typed out after the scan */}
            <div className="ai-note absolute -bottom-10 -left-4 z-20 w-[min(330px,88%)] rounded-2xl border-aura p-3.5 shadow-[0_24px_50px_-18px_rgb(30_60_110/0.45)] sm:-left-10" aria-hidden>
                <div className="mb-1.5 flex items-center gap-2 text-xs font-semibold">
                    <AiOrb size={18} state="thinking" />
                    <span className="text-aura">AI explains</span>
                    <span className="ml-auto font-mono text-[10.5px] font-normal text-ink-400">qwen2.5-coder</span>
                </div>
                <p className="text-[13px] leading-snug text-ink-700 dark:text-ink-200">
                    <span className="type-line type-1">Screen readers can't describe this image.</span>
                    <br />
                    <span className="type-line type-2 caret">I added alt text from the context.</span>
                </p>
            </div>
        </figure>
    );
}
