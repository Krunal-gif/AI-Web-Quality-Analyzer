// Main component: holds the app state (current analysis, filters, AI status), talks to the
// Laravel API and decides which screen to show (home page or the issue review screen).
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import ApplyBar from './components/ApplyBar';
import Footer from './components/Footer';
import History from './components/History';
import Home from './components/Home';
import IssueCard from './components/IssueCard';
import PageShots from './components/PageShots';
import Results from './components/Results';
import Sidebar, { EMPTY_FILTERS } from './components/Sidebar';
import TopBar from './components/TopBar';
import { SITE_PAGES, api, isUrl, pagePath, shareReport } from './lib/api';
import { withTransition } from './lib/motion';

const idFromPath = () => window.location.pathname.match(/^\/a\/([\w.-]+)/)?.[1] ?? null;
const onHistoryPage = () => window.location.pathname.replace(/\/$/, '') === '/history';

export default function App() {
    const [home, setHome] = useState(null);
    const [page, setPage] = useState(onHistoryPage() ? 'history' : 'home');
    const [data, setData] = useState(null);
    const [ai, setAi] = useState(null);
    const [filters, setFilters] = useState(EMPTY_FILTERS);
    const [open, setOpen] = useState(() => new Set());
    const [editing, setEditing] = useState({});
    const [loading, setLoading] = useState(null);
    const [toast, setToast] = useState(null);
    const [outdated, setOutdated] = useState(false);
    const poll = useRef(null);

    const notify = useCallback((message, error = false) => {
        setToast({ message, error });
        setTimeout(() => setToast(null), error ? 6000 : 3000);
    }, []);

    const run = useCallback(async (fn) => {
        try {
            return await fn();
        } catch (e) {
            notify(e.message, true);
        }
    }, [notify]);

    const loadAi = useCallback(async () => {
        try {
            setAi(await api('/api/ai/status'));
        } catch (e) {
            setAi({ online: false, error: e.message, models: [] });
        }
    }, []);

    const loadRoute = useCallback(async () => {
        const id = idFromPath();
        if (id) {
            try {
                const next = await api(`/api/analyses/${id}`);
                withTransition(() => setData(next));
            } catch (e) {
                if (e.status !== 404) return notify(e.message, true);
                // saved analyses are local JSON files; a stale link (deleted folder, other checkout) lands back home
                window.history.replaceState({}, '', '/history');
                notify(`Analysis ${id} no longer exists on this machine. Showing your saved analyses instead.`, true);
                return loadRoute();
            }
        } else {
            await run(async () => {
                const next = await api('/api/analyses');
                withTransition(() => {
                    setData(null);
                    setHome(next);
                    setPage(onHistoryPage() ? 'history' : 'home');
                });
            });
        }
    }, [run, notify]);

    // the server was rebuilt (npm run build) after this tab loaded: new features only appear after a reload
    useEffect(() => {
        const on = () => setOutdated(true);
        window.addEventListener('webqa:outdated', on);
        return () => window.removeEventListener('webqa:outdated', on);
    }, []);

    useEffect(() => {
        loadRoute();
        loadAi();
        const onPop = () => loadRoute();
        window.addEventListener('popstate', onPop);
        return () => window.removeEventListener('popstate', onPop);
    }, [loadRoute, loadAi]);

    // poll while AI work is running in the background
    useEffect(() => {
        clearTimeout(poll.current);
        if (data && (data.batch?.running || data.shots?.running || data.issues.some((i) => i.ai_pending))) {
            poll.current = setTimeout(async () => {
                try {
                    setData(await api(`/api/analyses/${data.id}`));
                } catch {
                    /* keep polling quietly */
                }
            }, 1500);
        }
        return () => clearTimeout(poll.current);
    }, [data]);

    const go = (path) => {
        window.history.pushState({}, '', path);
        window.scrollTo(0, 0);
    };

    const share = () =>
        run(async () => {
            if ((await shareReport(data.id)) === 'downloaded') notify('Report downloaded — send the .html file to share it.');
        });

    // pages: for a website, 1 = only that URL, more = read the sitemap first and analyse its pages one by one
    const analyze = (target, pages = 1) =>
        run(async () => {
            const sitemap = /\.xml(\.gz)?$/i.test(target);
            setLoading(!isUrl(target) ? 'Analysing files…'
                : sitemap || pages > 1 ? `Reading the sitemap and analysing up to ${pages} pages…` : 'Fetching and analysing the website…');
            try {
                const result = await api('/api/analyses', { method: 'POST', body: { target, pages } });
                go(`/a/${result.id}`);
                withTransition(() => {
                    setData(result);
                    setFilters(EMPTY_FILTERS);
                    setOpen(new Set());
                    setEditing({});
                });
            } finally {
                setLoading(null);
            }
        });

    const removeAnalysis = (h) => run(async () => {
        const res = await api(`/api/analyses/${h.id}`, { method: 'DELETE' });
        notify('Analysis removed');
        return res;
    });

    const openAnalysis = (id) => {
        go(`/a/${id}`);
        loadRoute();
    };

    const goHome = () => {
        go('/');
        loadRoute();
    };

    const goHistory = () => {
        go('/history');
        loadRoute();
    };

    const removeAll = () => run(async () => {
        if (!window.confirm(`Remove all ${home.history.length} saved analyses?\n\nTheir saved reports, patches, fixed copies and backups are deleted.\n\nYour project files are not touched.`)) return;
        const res = await api('/api/analyses', { method: 'DELETE' });
        setHome((x) => ({ ...x, history: res.history }));
        notify(res.kept ? `Removed ${res.removed}; ${res.kept} kept because an AI review is still running` : `Removed ${res.removed} saved analyses`);
    });

    const visible = useMemo(() => {
        if (!data) return [];
        const q = filters.q.toLowerCase();
        return data.issues.filter((i) =>
            (!filters.sev.length || filters.sev.includes(i.severity))
            && (!filters.cat || i.category === filters.cat)
            && (filters.status === 'all' || i.status === filters.status || (filters.status === 'manual' && !i.editable))
            && (!filters.fixable || (i.editable && i.proposed !== null))
            && (!filters.page || i.file === filters.page)
            && (!q || `${i.title} ${i.message} ${i.file}`.toLowerCase().includes(q)));
    }, [data, filters]);

    const toggle = (id) => setOpen((s) => {
        const next = new Set(s);
        next.has(id) ? next.delete(id) : next.add(id);
        return next;
    });

    const patchIssue = (i, body) => api(`/api/analyses/${data.id}/issues/${i.id}`, { method: 'PATCH', body }).then(setData);

    const act = (action, i) => run(async () => {
        switch (action) {
            case 'approve': return patchIssue(i, { status: 'approved' });
            case 'reject': return patchIssue(i, { status: 'rejected' });
            case 'pending': return patchIssue(i, { status: 'pending' });
            case 'use-ai': return patchIssue(i, { proposed: i.ai.corrected, source: 'ai' });
            case 'use-rule': return patchIssue(i, { proposed: i.rule_fix, source: 'rule' });
            case 'edit':
                setEditing((e) => ({ ...e, [i.id]: i.proposed ?? i.original }));
                setOpen((s) => new Set(s).add(i.id));
                return;
            case 'cancel':
                setEditing(({ [i.id]: _, ...rest }) => rest);
                return;
            case 'save':
                await patchIssue(i, { proposed: editing[i.id], source: 'user' });
                setEditing(({ [i.id]: _, ...rest }) => rest);
                return;
            case 'write': {
                const where = `${i.file}${i.line ? `, line ${i.line}` : ''}`;
                if (!window.confirm(`Write this fix into ${where}?\n\nThe file on your computer is changed now. A backup of the original is kept in the analysis folder.`)) return;
                const res = await api(`/api/analyses/${data.id}/issues/${i.id}/write`, { method: 'POST', body: {} });
                setData(res);
                const done = res.issues.find((x) => x.id === i.id);
                return done?.resolved
                    ? notify(`Fix written to ${i.file} — re-scan confirms it's fixed`)
                    : notify(`Fix written to ${i.file}, but the re-scan still detects the issue`, true);
            }
            case 'ai':
                if (!ai?.online) return notify('Ollama is not running. Start it with `ollama serve` in a terminal, then click ↻ at the top right.', true);
                setOpen((s) => new Set(s).add(i.id));
                return setData(await api(`/api/analyses/${data.id}/ai`, { method: 'POST', body: { ids: [i.id] } }));
        }
    });

    const bulk = (status, items) => run(async () => {
        const res = await api(`/api/analyses/${data.id}/bulk`, { method: 'POST', body: { ids: items.map((i) => i.id), status } });
        setData(res);
        notify(`${res.changed} issue(s) updated`);
    });

    const aiBatch = (ids) => run(async () => {
        if (!ai?.online) return notify('Ollama is not running. Start it with `ollama serve` in a terminal, then click ↻ at the top right.', true);
        setData(await api(`/api/analyses/${data.id}/ai`, { method: 'POST', body: { ids } }));
    });

    const aiCancel = () => run(async () => setData(await api(`/api/analyses/${data.id}/ai/cancel`, { method: 'POST', body: {} })));

    const apply = (mode) => run(async () => {
        if (mode === 'inplace' && !window.confirm('Write the approved fixes into your project files?\nOriginals are backed up to the analysis folder.')) return;
        setLoading('Applying fixes and re-scanning…');
        try {
            const res = await api(`/api/analyses/${data.id}/apply`, { method: 'POST', body: { mode } });
            setData(res);
            window.scrollTo(0, 0);
            notify(`Applied ${res.after.applied} fix(es) — ${res.after.resolved} verified`);
        } finally {
            setLoading(null);
        }
    });

    const setModel = (model) => run(async () => {
        const status = await api('/api/ai/settings', { method: 'POST', body: { ollama_model: model } });
        setAi(status);
        if (status.error) notify(status.error, true);
    });

    return (
        <>
            <TopBar data={data} initialTarget={data?.input} onAnalyze={(t) => analyze(t, (data?.target.site?.pages?.length ?? 0) > 1 ? SITE_PAGES : 1)} onHome={goHome} onHistory={goHistory} historyCount={home?.history?.length} page={page} ai={ai} onModel={setModel} onAiRefresh={loadAi} />

            {!data ? (
                <>
                    {page === 'history' ? (
                        <History
                            history={home?.history}
                            onOpen={openAnalysis}
                            onRemove={removeAnalysis}
                            onRemoved={(history) => setHome((x) => ({ ...x, history }))}
                            onRemoveAll={removeAll}
                            onHome={goHome}
                        />
                    ) : (
                        <Home home={home} ai={ai} onAnalyze={analyze} onModel={setModel} onAiRefresh={loadAi} />
                    )}
                    <Footer ai={ai} onDemo={home?.demo ? () => analyze(home.demo) : null} />
                </>
            ) : (
                <>
                    <div className="mx-auto grid max-w-[1500px] gap-4 p-4 lg:grid-cols-[300px_minmax(0,1fr)]">
                        <Sidebar
                            data={data}
                            visible={visible}
                            filters={filters}
                            setFilters={setFilters}
                            onBulk={bulk}
                            onAiBatch={aiBatch}
                            onAiCancel={aiCancel}
                            aiOnline={ai?.online}
                        />
                        <main className="min-w-0 pb-6">
                            {data.after && <Results data={data} onShare={share} />}
                            <PageShots data={data} current={filters.page} onPick={(page) => setFilters({ ...filters, page })} />
                            {data.locked && (
                                <div className="mb-3 animate-fade rounded-lg bg-amber-50 px-3 py-2 text-[13px] text-amber-800 dark:bg-amber-950/50 dark:text-amber-300">
                                    Fixes were written to your files. Re-run the analysis to review the updated project.
                                </div>
                            )}
                            <div className="mx-0.5 mb-2.5 flex flex-wrap items-center justify-between gap-2 text-[13px] text-ink-500">
                                <span>
                                    {visible.length} of {data.issues.length} issues
                                    {filters.page && <> on <b className="font-mono font-medium text-ink-700 dark:text-ink-300">{filters.page === '(site)' ? 'the whole site' : (data.target.site?.pages?.find((p) => p.rel === filters.page)?.url ? pagePath(data.target.site.pages.find((p) => p.rel === filters.page).url) : filters.page.replace(/^[^/]+/, ''))}</b> <button className="btn btn-sm btn-ghost" onClick={() => setFilters({ ...filters, page: null })}>All pages</button></>}
                                    {' '}· sorted by severity
                                </span>
                                <span>
                                    <button className="btn btn-sm btn-ghost" onClick={() => setOpen(new Set(visible.map((i) => i.id)))}>Expand all</button>
                                    <button className="btn btn-sm btn-ghost" onClick={() => setOpen(new Set())}>Collapse all</button>
                                </span>
                            </div>
                            {!visible.length && (
                                <div className="panel animate-fade py-10 text-center text-ink-500">
                                    No issues match these filters.
                                    {(filters !== EMPTY_FILTERS) && <button className="btn btn-sm mx-auto mt-3 flex" onClick={() => setFilters(EMPTY_FILTERS)}>Clear filters</button>}
                                </div>
                            )}
                            <div className="flex flex-col gap-2.5">
                                {visible.map((i) => (
                                    <IssueCard
                                        key={i.id}
                                        issue={i}
                                        open={open.has(i.id)}
                                        locked={data.locked}
                                        canWrite={data.target.kind !== 'url'}
                                        editing={editing[i.id]}
                                        aiModel={ai?.model}
                                        aiCurrent={data.batch?.running ? data.batch.current : null}
                                        onToggle={() => toggle(i.id)}
                                        onAct={act}
                                        onDraft={(v) => setEditing((e) => ({ ...e, [i.id]: v }))}
                                    />
                                ))}
                            </div>
                        </main>
                        <ApplyBar data={data} onApply={apply} onShare={share} />
                    </div>
                    <Footer ai={ai} compact />
                </>
            )}

            {loading && (
                <div className="fixed inset-0 z-50 grid animate-fade place-items-center bg-ink-100/60 px-4 backdrop-blur-sm dark:bg-ink-950/60" role="status" aria-live="polite">
                    <div className="panel flex w-full max-w-xs animate-rise flex-col items-center gap-5 py-8 text-center shadow-2xl">
                        <div aria-hidden className="relative h-20 w-16 overflow-hidden rounded-lg border border-ink-200 bg-white p-2.5 dark:border-ink-700 dark:bg-ink-950">
                            {[90, 70, 100, 55, 80, 65].map((w, n) => (
                                <span key={n} className="mb-1.5 block h-1 rounded-full bg-ink-200 dark:bg-ink-700" style={{ width: `${w}%` }} />
                            ))}
                            <span className="absolute inset-x-0 h-5 animate-scan border-b-2 border-beam bg-gradient-to-b from-transparent to-beam/30" />
                        </div>
                        <p className="text-[15px] font-medium">{loading}</p>
                    </div>
                </div>
            )}
            {outdated && (
                <div role="alert" className="fixed inset-x-0 top-0 z-50 flex animate-fade flex-wrap items-center justify-center gap-x-4 gap-y-1 bg-brand-700 px-4 py-2 text-[13.5px] text-white shadow-lg">
                    WebQA has been updated. Reload this tab to use the latest version.
                    <button className="rounded-md bg-white px-3 py-1 text-[13px] font-semibold text-brand-800 hover:bg-brand-50" onClick={() => window.location.reload()}>Reload</button>
                </div>
            )}
            {toast && (
                <div
                    key={toast.message}
                    role="status"
                    className={`fixed right-4 bottom-24 z-50 flex max-w-md animate-toast items-start gap-2.5 rounded-xl px-3.5 py-2.5 text-[13.5px] shadow-2xl ${toast.error ? 'bg-red-700 text-white' : 'bg-ink-900 text-white dark:bg-ink-100 dark:text-ink-900'}`}
                >
                    <span aria-hidden className={`mt-px grid size-[18px] shrink-0 place-items-center rounded-full text-[11px] font-bold ${toast.error ? 'bg-white/20' : 'bg-emerald-500 text-white'}`}>{toast.error ? '!' : '✓'}</span>
                    {toast.message}
                </div>
            )}
        </>
    );
}

