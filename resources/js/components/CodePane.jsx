// Shows source code with line numbers and highlights the issue's region
// (red = removed, green = added, yellow = flagged).
const MARK = {
    del: 'rounded-sm bg-red-100 text-red-800 line-through decoration-red-800/50 dark:bg-red-950 dark:text-red-200',
    ins: 'rounded-sm bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    flag: 'rounded-sm bg-yellow-100 shadow-[inset_0_-2px_0_var(--color-amber-500)] dark:bg-amber-400/20 dark:text-amber-100',
};

/**
 * Source context with line numbers; the issue's region is highlighted.
 * marker: 'missing' (before an insertion) or 'removed' (after a deletion) when the region is empty.
 */
export default function CodePane({ ctx, region, cls, marker, hl }) {
    if (!ctx) {
        return <div className="p-4 text-sm text-ink-500">Site-level finding — no source location.</div>;
    }
    const pieces = [[ctx.prefix, null]];
    if ((region ?? '') === '' && marker) pieces.push(['', marker]);
    else pieces.push([region ?? '', cls]);
    pieces.push([ctx.suffix, null]);

    const rows = [];
    let cur = [];
    let changed = false;
    let line = ctx.first_line;
    let key = 0;
    const flush = () => {
        rows.push({ line, parts: cur, changed });
        cur = [];
        changed = false;
        line++;
    };
    for (const [text, c] of pieces) {
        if (c && text === '' && (c === 'missing' || c === 'removed')) {
            cur.push(
                <span key={key++} className={`rounded px-1.5 font-sans text-[11px] ${c === 'missing' ? 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'}`}>
                    ⟨ {c} ⟩
                </span>,
            );
            changed = true;
            continue;
        }
        text.split('\n').forEach((part, k) => {
            if (k > 0) flush();
            if (part) {
                cur.push(c ? <mark key={key++} className={MARK[c]}>{part}</mark> : <span key={key++}>{part}</span>);
                if (c) changed = true;
            } else if (c && k > 0) changed = true;
        });
    }
    flush();

    return (
        <div className="max-h-[340px] overflow-auto font-mono text-[12.5px] leading-relaxed">
            {rows.map((r) => (
                <div key={r.line} className={`grid grid-cols-[44px_minmax(0,1fr)] ${hl === r.line ? 'bg-yellow-50 dark:bg-yellow-900/25' : ''}`}>
                    <span className={`border-r border-ink-200 pr-2.5 text-right select-none dark:border-ink-800 ${r.changed ? 'text-ink-900 dark:text-ink-100' : 'text-ink-400'}`}>
                        {r.line}
                    </span>
                    <span className="px-2.5 break-words whitespace-pre-wrap">{r.parts.length ? r.parts : ' '}</span>
                </div>
            ))}
        </div>
    );
}
