// One issue: header row (severity, title, file:line, status) and, when opened, the
// explanation, Before/After code and the Approve / Reject / Edit / Ask AI buttons.
import CodePane from './CodePane';
import ElementPreview from './ElementPreview';
import { SEV_BADGE, SEV_DOT, decidable } from '../lib/api';
import { useChanged } from '../lib/motion';
import AiOrb from './AiOrb';

const STATUS_CHIP = {
    approved: 'border-emerald-600 text-emerald-700 dark:text-emerald-400',
    rejected: 'text-ink-500',
    applied: 'border-emerald-600 bg-emerald-600 text-white',
    conflict: 'border-red-600 text-red-700 dark:text-red-400',
    failed: 'border-red-600 text-red-700 dark:text-red-400',
};
const CARD_RING = {
    approved: 'ring-2 ring-emerald-500/35',
    applied: 'ring-2 ring-emerald-500/60',
    conflict: 'ring-2 ring-red-500/50',
    failed: 'ring-2 ring-red-500/50',
};
const SOURCE_LABEL = { rule: 'rule-based fix', ai: 'AI-generated fix', user: 'your edit' };

function Note({ tone = 'warn', children }) {
    const tones = {
        warn: 'bg-amber-50 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300',
        err: 'bg-red-50 text-red-800 dark:bg-red-950/50 dark:text-red-300',
        ok: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300',
    };
    return <div className={`animate-fade rounded-lg px-3 py-2 text-[13px] ${tones[tone]}`}>{children}</div>;
}

const PANE_DOT = { before: 'bg-red-500', after: 'bg-emerald-500', edit: 'bg-brand-500', none: 'bg-ink-400' };

function Pane({ title, tone = 'none', aside, children }) {
    return (
        <div className="min-w-0 overflow-hidden rounded-xl border border-ink-200 bg-ink-50/80 dark:border-ink-800 dark:bg-ink-950">
            <h4 className="flex items-center justify-between gap-2 border-b border-ink-200 px-3 py-1.5 text-xs font-semibold text-ink-600 dark:border-ink-800 dark:text-ink-300">
                <span className="flex items-center gap-1.5"><i className={`size-1.5 rounded-full ${PANE_DOT[tone]}`} />{title}</span>
                {aside && <span className="truncate font-mono text-[11px] font-normal text-ink-400">{aside}</span>}
            </h4>
            {children}
        </div>
    );
}

export default function IssueCard({ issue: i, open, locked, canWrite, editing, aiModel, aiCurrent, onToggle, onAct, onDraft }) {
    const ai = i.ai && !i.ai.error ? i.ai : null;
    const canDecide = decidable(i) && !locked;
    const writable = canWrite && canDecide && i.status !== 'rejected';
    const busy = i.ai_pending;
    // the AI answers one issue at a time; the rest of a batch is waiting its turn
    const working = busy && (!aiCurrent || aiCurrent === i.id);
    const statusLabel = !i.editable ? 'manual' : i.proposed === null && i.status === 'pending' ? 'no fix yet' : i.status;
    const isEditing = editing !== undefined;
    const confirm = useChanged(`${i.status}|${i.resolved}`) ? 'animate-pop' : '';

    const quick = canDecide && (
        <>
            {i.status !== 'approved' && (
                <button className="btn btn-sm btn-ok" title="Approve fix" aria-label="Approve fix" onClick={(e) => { e.stopPropagation(); onAct('approve', i); }}>✓</button>
            )}
            {i.status !== 'rejected' && (
                <button className="btn btn-sm btn-bad" title="Reject fix" aria-label="Reject fix" onClick={(e) => { e.stopPropagation(); onAct('reject', i); }}>✕</button>
            )}
        </>
    );

    const chip =
        i.written ? <span className={`rounded-full border px-2.5 py-px text-xs whitespace-nowrap ${confirm} ${i.resolved === false ? STATUS_CHIP.conflict : STATUS_CHIP.applied}`}>{i.resolved === false ? 'written, still detected' : '✓ written to file'}</span>
        : i.resolved === true ? <span className={`rounded-full border px-2.5 py-px text-xs whitespace-nowrap ${confirm} ${STATUS_CHIP.applied}`}>✓ verified</span>
        : i.resolved === false ? <span className={`rounded-full border px-2.5 py-px text-xs whitespace-nowrap ${confirm} ${STATUS_CHIP.conflict}`}>still detected</span>
        : <span className={`rounded-full border border-ink-300 px-2.5 py-px text-xs whitespace-nowrap dark:border-ink-700 ${confirm} ${STATUS_CHIP[i.status] ?? ''}`}>{statusLabel}</span>;

    return (
        <article className={`relative overflow-hidden rounded-xl border border-ink-200 bg-white pl-1 shadow-[0_1px_2px_rgb(12_40_52/0.04)] transition-[opacity,box-shadow] duration-300 dark:border-ink-800 dark:bg-ink-900 ${CARD_RING[i.status] ?? ''} ${open ? 'shadow-[0_12px_32px_-12px_rgb(12_40_52/0.18)]' : ''} ${i.status === 'rejected' ? 'opacity-55' : ''}`}>
            <span aria-hidden className={`absolute inset-y-0 left-0 w-1 ${SEV_DOT[i.severity]}`} />
            {working && (
                <span aria-hidden className="absolute inset-x-0 top-0 h-0.5 overflow-hidden">
                    <span className="block h-full w-1/3 animate-shimmer bg-gradient-to-r from-transparent via-aura-2 to-transparent" />
                </span>
            )}
            <div
                role="button"
                tabIndex={0}
                aria-expanded={open}
                onClick={onToggle}
                onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), onToggle())}
                className="group grid cursor-pointer grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-2 px-3 py-2.5 transition-colors hover:bg-ink-50 sm:grid-cols-[auto_minmax(0,1fr)_auto] dark:hover:bg-ink-800/50"
            >
                <span className={`min-w-[66px] rounded-md px-2 py-0.5 text-center text-[11.5px] font-semibold capitalize ring-1 ring-inset ${SEV_BADGE[i.severity]}`}>{i.severity}</span>
                <div className="min-w-0">
                    <b className="font-semibold">{i.title}</b>
                    <span className="ml-2 font-mono text-xs text-ink-400">{i.file}{i.line ? `:${i.line}` : ''}</span>
                    <span className="ml-2 text-[11.5px] text-ink-400">{i.category}</span>
                    {ai && <span className="ml-2 inline-flex items-center gap-1 rounded-md border-aura px-1.5 py-px align-[1px] text-[11px] font-semibold"><AiOrb size={9} /><span className="text-aura">AI</span></span>}
                    {working && <span className="ml-2 inline-flex items-center gap-1.5 align-[-2px] text-[11.5px] text-ink-500"><AiOrb size={12} state="thinking" />asking AI…</span>}
                    {busy && !working && <span className="ml-2 align-[1px] text-[11.5px] text-ink-400">waiting for AI</span>}
                    <div className="truncate text-[13px] text-ink-500 dark:text-ink-400">{i.message}</div>
                </div>
                <div className="col-span-2 flex items-center justify-end gap-1.5 sm:col-span-1">
                    {chip}{quick}
                    <svg aria-hidden viewBox="0 0 16 16" className={`size-4 shrink-0 text-ink-400 transition-transform duration-300 ease-out group-hover:text-ink-600 ${open ? 'rotate-90' : ''}`}>
                        <path d="M6 3.5 10.5 8 6 12.5" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                </div>
            </div>

            {open && (
                <div className="flex animate-unfold flex-col gap-3 border-t border-ink-200 px-3.5 py-3.5 dark:border-ink-800">
                    <dl className="grid gap-x-4 gap-y-1.5 text-[13.5px] sm:grid-cols-[130px_minmax(0,1fr)]">
                        <dt className="text-xs text-ink-500">Problem</dt>
                        <dd>{i.message}</dd>
                        <dt className="text-xs text-ink-500">
                            Why it matters
                            <span className="block text-[11px] text-ink-400">
                                {ai?.why ? `AI · ${ai.model} · ${ai.seconds}s${ai.confidence ? ` · ${ai.confidence} confidence` : ''}` : 'Rule catalogue'}
                            </span>
                        </dt>
                        <dd>{ai?.why || i.why}</dd>
                        <dt className="text-xs text-ink-500">Suggested fix</dt>
                        <dd>{ai?.fix || i.how}</dd>
                    </dl>

                    <ElementPreview issue={i} />

                    <div className="grid gap-2.5 lg:grid-cols-2">
                        <Pane title="Before" tone="before" aside={i.file}>
                            <CodePane
                                ctx={i.context}
                                region={i.original}
                                cls={i.editable && i.proposed !== null ? 'del' : 'flag'}
                                marker={i.editable ? 'missing' : null}
                                hl={i.editable ? null : i.line}
                            />
                        </Pane>
                        {!i.editable ? (
                            <Pane title="After">
                                <p className="p-4 text-[13px] text-ink-500">This needs a manual or site-wide change — follow the fix guidance above.{ai ? '' : ' Ask AI for specific steps.'}</p>
                            </Pane>
                        ) : isEditing ? (
                            <Pane title="Edit fix" tone="edit" aside={`replaces the highlighted ${i.insert ? 'insert point' : 'region'}`}>
                                <textarea
                                    className="min-h-32 w-full resize-y border-0 bg-transparent p-2.5 font-mono text-[12.5px] outline-none"
                                    aria-label="Edit proposed fix"
                                    value={editing}
                                    autoFocus
                                    onChange={(e) => onDraft(e.target.value)}
                                />
                            </Pane>
                        ) : i.proposed === null ? (
                            <Pane title="After">
                                <p className="p-4 text-[13px] text-ink-500">No automatic fix yet. Ask AI to propose one, or write your own.</p>
                            </Pane>
                        ) : (
                            <Pane title="After" tone="after" aside={SOURCE_LABEL[i.proposed_source]}>
                                <CodePane ctx={i.context} region={i.proposed} cls="ins" marker="removed" />
                            </Pane>
                        )}
                    </div>

                    {i.fix_note && i.proposed_source === 'rule' && <Note>⚠ {i.fix_note}</Note>}
                    {i.ai?.error && <Note tone="err">AI error: {i.ai.error}</Note>}
                    {ai?.warning && <Note>AI: {ai.warning}</Note>}
                    {i.result_note && <Note tone="err">{i.result_note}</Note>}
                    {i.written && (i.resolved === false
                        ? <Note>Written to {i.file}, but the re-scan still detects this issue. The original is in the analysis folder's backup.</Note>
                        : <Note tone="ok">✓ Written to {i.file} and the re-scan confirms it's fixed. The original is kept in the analysis folder's backup.</Note>)}
                    {!i.written && i.resolved === true && <Note tone="ok">✓ Re-scan confirms this issue is fixed.</Note>}

                    {!locked && !i.written && (
                        <div className="flex flex-wrap items-center gap-2">
                            {busy ? (
                                <span className="flex items-center gap-2 text-sm text-ink-500">
                                    <AiOrb size={18} state={working ? 'thinking' : 'idle'} />
                                    {working ? `Asking ${aiModel || 'AI'}…` : 'Waiting its turn — the AI answers one issue at a time.'}
                                </span>
                            ) : (
                                <button className="btn btn-sm btn-ai" onClick={() => onAct('ai', i)}><AiOrb size={13} /> {ai ? 'Ask AI again' : 'Explain & fix with AI'}</button>
                            )}
                            {i.editable && (isEditing ? (
                                <>
                                    <button className="btn btn-sm btn-primary" onClick={() => onAct('save', i)}>Save edit</button>
                                    <button className="btn btn-sm" onClick={() => onAct('cancel', i)}>Cancel</button>
                                </>
                            ) : (
                                <button className="btn btn-sm" onClick={() => onAct('edit', i)}>✎ {i.proposed === null ? 'Write fix' : 'Edit fix'}</button>
                            ))}
                            {!isEditing && i.editable && ai?.corrected && i.proposed !== ai.corrected && (
                                <button className="btn btn-sm" onClick={() => onAct('use-ai', i)}>Use AI version</button>
                            )}
                            {!isEditing && i.editable && i.rule_fix !== null && i.proposed !== i.rule_fix && (
                                <button className="btn btn-sm" onClick={() => onAct('use-rule', i)}>Use rule version</button>
                            )}
                            <span className="flex-1" />
                            {canDecide && !isEditing && (
                                <>
                                    {i.status !== 'rejected' && <button className="btn btn-sm btn-bad" onClick={() => onAct('reject', i)}>✕ Reject</button>}
                                    {i.status !== 'approved'
                                        ? <button className="btn btn-sm btn-ok" onClick={() => onAct('approve', i)}>✓ Approve fix</button>
                                        : <button className="btn btn-sm" onClick={() => onAct('pending', i)}>Undo approval</button>}
                                </>
                            )}
                            {writable && !isEditing && (
                                <button className="btn btn-sm btn-primary" title="Change the file on disk now (a backup is kept)" onClick={() => onAct('write', i)}>
                                    Write fix to file
                                </button>
                            )}
                        </div>
                    )}
                </div>
            )}
        </article>
    );
}
