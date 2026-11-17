// History page (/history): every saved analysis in the "Pick up where you left off" list, with Remove all.
import RecentList from './RecentList';

export default function History({ history, onOpen, onRemove, onRemoved, onRemoveAll, onHome }) {
    return (
        <main className="mx-auto min-h-[60vh] max-w-[1360px] px-4 pt-12 pb-20 sm:px-6">
            <div className="mb-8 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 className="font-display text-[clamp(2rem,4vw,2.75rem)] leading-[1.08] font-extrabold tracking-[-0.035em] text-ink-900 dark:text-white">History</h1>
                    <p className="mt-2 text-[15px] text-ink-600 dark:text-ink-400">Open a saved analysis to continue reviewing it, or remove the ones you no longer need.</p>
                </div>
                {history?.length > 0 && (
                    <button className="btn btn-bad" onClick={onRemoveAll}>Remove all ({history.length})</button>
                )}
            </div>

            {history === undefined ? null : history.length ? (
                <RecentList history={history} onOpen={onOpen} onRemove={onRemove} onRemoved={onRemoved} />
            ) : (
                <div className="panel py-14 text-center">
                    <p className="text-[15px] font-medium">No saved analyses yet</p>
                    <p className="mt-1 text-sm text-ink-500">Every analysis you run is saved here automatically.</p>
                    <button className="btn btn-primary mx-auto mt-5 flex" onClick={onHome}>Analyse a website or project</button>
                </div>
            )}
        </main>
    );
}
