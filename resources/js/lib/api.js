// Shared helpers: api() sends JSON requests to Laravel (with the CSRF token), plus the
// severity/category lists and colour classes used across the UI.
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const loadedBuild = document.querySelector('meta[name="webqa-build"]')?.content;

/** Tell the app once when the server has a newer frontend build than this tab is running. */
function checkBuild(res) {
    const current = res.headers.get('X-WebQA-Build');
    if (current && loadedBuild && current !== loadedBuild && !checkBuild.told) {
        checkBuild.told = true;
        window.dispatchEvent(new Event('webqa:outdated'));
    }
}

/** JSON request to the Laravel API. Throws Error(message) with a `status` property on failure. */
export async function api(path, { method = 'GET', body } = {}) {
    let res;
    try {
        res = await fetch(path, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
    } catch {
        // the browser's own "Failed to fetch" says nothing useful: the server isn't answering at all
        throw new Error('Can\'t reach the WebQA server. Check that `php artisan serve` is still running in its terminal, then try again.');
    }
    checkBuild(res);
    const json = await res.json().catch(() => ({
        message: res.status >= 500 || res.status === 0
            ? `The server stopped while working (HTTP ${res.status}). Check the terminal running php artisan serve for the error.`
            : `Unexpected response (${res.status})`,
    }));
    if (!res.ok) {
        if (res.status === 419) throw new Error('Your session expired — reload the page.');
        const first = json.errors && Object.values(json.errors)[0]?.[0];
        throw Object.assign(new Error(first || json.message || res.statusText), { status: res.status });
    }
    return json;
}

/**
 * Share the standalone HTML report: the system share sheet where the browser can share files,
 * otherwise a download of the .html file. Returns 'shared', 'cancelled' or 'downloaded'.
 */
export async function shareReport(id) {
    const res = await fetch(`/analyses/${id}/report.html`, { credentials: 'same-origin' });
    if (!res.ok) throw new Error(`Couldn't build the report (${res.status})`);
    const name = `webqa-report-${id}.html`;
    const blob = await res.blob();
    const file = new File([blob], name, { type: 'text/html' });
    if (navigator.canShare?.({ files: [file] })) {
        try {
            await navigator.share({ files: [file], title: 'WebQA report' });
            return 'shared';
        } catch (e) {
            if (e.name === 'AbortError') return 'cancelled';
        }
    }
    const url = URL.createObjectURL(blob);
    Object.assign(document.createElement('a'), { href: url, download: name }).click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    return 'downloaded';
}

export const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];
export const CATEGORIES = ['SEO', 'Accessibility', 'HTML Structure', 'Content', 'Code', 'Security'];

export const SEV_BADGE = {
    critical: 'bg-red-600/12 text-red-700 ring-red-600/25 dark:bg-red-500/15 dark:text-red-300',
    high: 'bg-orange-500/12 text-orange-700 ring-orange-500/25 dark:bg-orange-500/15 dark:text-orange-300',
    medium: 'bg-amber-400/20 text-amber-800 ring-amber-500/30 dark:bg-amber-400/15 dark:text-amber-300',
    low: 'bg-blue-500/12 text-blue-700 ring-blue-500/25 dark:bg-blue-500/15 dark:text-blue-300',
    info: 'bg-ink-500/12 text-ink-600 ring-ink-500/25 dark:bg-ink-400/15 dark:text-ink-300',
};

export const SEV_DOT = {
    critical: 'bg-red-700',
    high: 'bg-orange-600',
    medium: 'bg-amber-400',
    low: 'bg-blue-600',
    info: 'bg-ink-500',
};

export const SEV_BORDER = {
    critical: 'border-l-red-700',
    high: 'border-l-orange-600',
    medium: 'border-l-amber-400',
    low: 'border-l-blue-600',
    info: 'border-l-ink-400',
};

export const isUrl = (t) => /^https?:|^[\w-]+\.[a-z]{2,}/i.test(t);

/** How many pages a whole-site scan analyses (sitemap pages first, then links). The server allows up to 500. */
export const SITE_PAGES = 50;
export const MAX_PAGES = 500;

/** A crawled page's address without the scheme and host, for compact lists ("/about/"). */
export const pagePath = (url) => {
    try {
        const u = new URL(url);
        return decodeURI(u.pathname) + u.search || '/';
    } catch {
        return url;
    }
};

/** Issues that have a fix the user can approve/reject right now. */
export const decidable = (i) =>
    i.editable && i.proposed !== null && ['pending', 'approved', 'rejected'].includes(i.status);
