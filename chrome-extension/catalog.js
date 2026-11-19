// Rule catalogue: category, default severity and the built-in explanation for every rule the extension runs.
// Generated from app/Analyzer/Rules/Catalog.php so the wording matches the web app.
export const RULES = {
    "a11y-button-no-name": {
        "category": "Accessibility",
        "severity": "high",
        "title": "Button without accessible name",
        "why": "An icon-only button with no label is announced as just \"button\" — users can't tell what it does (WCAG 4.1.2).",
        "how": "Add visible text or an aria-label that describes the action (e.g. \"Open menu\")."
    },
    "a11y-generic-link-text": {
        "category": "Accessibility",
        "severity": "low",
        "title": "Non-descriptive link text",
        "why": "Screen-reader users often browse a list of links out of context; \"click here\" or \"read more\" says nothing. Search engines also use anchor text to understand the target page (WCAG 2.4.4).",
        "how": "Rewrite the link text to describe the destination, e.g. \"Read our pricing guide\"."
    },
    "a11y-iframe-title": {
        "category": "Accessibility",
        "severity": "medium",
        "title": "Iframe without title",
        "why": "Screen readers announce iframes by their title; without one users can't tell what the embedded content is (WCAG 4.1.2).",
        "how": "Add a title attribute describing the embedded content, e.g. title=\"Office location map\"."
    },
    "a11y-img-alt-missing": {
        "category": "Accessibility",
        "severity": "high",
        "title": "Image without alt text",
        "why": "Screen readers can't describe an image without alt text — many read out the file name instead. Search engines also rely on alt text to understand images (WCAG 1.1.1).",
        "how": "Add an alt attribute describing the image's purpose; use alt=\"\" only for purely decorative images."
    },
    "a11y-input-no-label": {
        "category": "Accessibility",
        "severity": "high",
        "title": "Form field without label",
        "why": "Without a label, screen-reader users only hear \"edit text\" and don't know what to enter (WCAG 1.3.1, 3.3.2). Placeholders are not a substitute — they disappear while typing.",
        "how": "Associate a <label for=\"…\"> with the field, or add aria-label if a visible label is impossible."
    },
    "a11y-lang-missing": {
        "category": "Accessibility",
        "severity": "medium",
        "title": "Missing page language",
        "why": "Screen readers use the lang attribute to choose pronunciation; translation tools and search engines use it to detect the language (WCAG 3.1.1).",
        "how": "Add lang=\"en\" (or the correct language code) to the <html> element."
    },
    "a11y-link-no-text": {
        "category": "Accessibility",
        "severity": "high",
        "title": "Link without accessible name",
        "why": "A link with no text (e.g. icon-only) is announced as just \"link\", so screen-reader users can't know where it goes (WCAG 2.4.4, 4.1.2).",
        "how": "Add visible text, an aria-label, or alt text on the image inside the link describing its destination."
    },
    "a11y-positive-tabindex": {
        "category": "Accessibility",
        "severity": "low",
        "title": "Positive tabindex",
        "why": "tabindex values above 0 override the natural keyboard order, making focus jump around unpredictably (WCAG 2.4.3).",
        "how": "Use tabindex=\"0\" (or remove it) and fix the order in the DOM instead."
    },
    "code-console-log": {
        "category": "Code",
        "severity": "low",
        "title": "Debug logging left in code",
        "why": "console.log calls leak internal data to anyone opening DevTools, add noise and cost a little performance.",
        "how": "Remove debug logging, or route it through a logger that is disabled in production."
    },
    "code-debugger": {
        "category": "Code",
        "severity": "medium",
        "title": "debugger statement",
        "why": "A `debugger` statement pauses execution whenever DevTools is open and should never ship to production.",
        "how": "Remove the statement."
    },
    "code-todo": {
        "category": "Code",
        "severity": "info",
        "title": "TODO / FIXME comment",
        "why": "Unresolved TODO/FIXME markers indicate unfinished work that may hide known bugs.",
        "how": "Resolve the item or move it into the issue tracker."
    },
    "content-long-sentence": {
        "category": "Content",
        "severity": "info",
        "title": "Very long sentence",
        "why": "Sentences over ~35–40 words are hard to read, especially on mobile and for non-native readers.",
        "how": "Split the sentence into two or three shorter ones."
    },
    "content-placeholder": {
        "category": "Content",
        "severity": "high",
        "title": "Placeholder text left in page",
        "why": "Lorem ipsum or dummy text looks unprofessional, erodes trust and can be indexed by search engines.",
        "how": "Replace the placeholder with the real content."
    },
    "content-repeated-word": {
        "category": "Content",
        "severity": "low",
        "title": "Repeated word",
        "why": "Doubled words (\"the the\") are typos that make content look careless.",
        "how": "Remove the duplicate word."
    },
    "content-thin": {
        "category": "Content",
        "severity": "low",
        "title": "Thin content",
        "why": "Pages with very little text give search engines little to rank and users little reason to stay.",
        "how": "Expand the page with genuinely useful content that answers the visitor's question (aim for 300+ words on key pages)."
    },
    "html-charset-missing": {
        "category": "HTML Structure",
        "severity": "medium",
        "title": "Missing character encoding",
        "why": "Without a declared charset browsers may guess the encoding and show garbled characters (é → Ã©), and some encodings open XSS vectors.",
        "how": "Add <meta charset=\"utf-8\"> as the first element in <head>."
    },
    "html-deprecated-tag": {
        "category": "HTML Structure",
        "severity": "medium",
        "title": "Deprecated HTML element",
        "why": "Obsolete presentational elements (<center>, <font>, <marquee>…) are not part of HTML5, mix styling into markup and may be dropped or rendered inconsistently.",
        "how": "Replace with semantic elements and move the styling into CSS."
    },
    "html-doctype-missing": {
        "category": "HTML Structure",
        "severity": "medium",
        "title": "Missing <!DOCTYPE html>",
        "why": "Without a doctype, browsers render in quirks mode and emulate legacy layout bugs, causing inconsistent rendering.",
        "how": "Add <!DOCTYPE html> as the very first line of the document."
    },
    "html-duplicate-id": {
        "category": "HTML Structure",
        "severity": "high",
        "title": "Duplicate id attribute",
        "why": "ids must be unique. Duplicates break label/aria references, anchor links and JavaScript like getElementById, which silently picks the first match.",
        "how": "Give each element a unique id and update any CSS/JS/label references to it."
    },
    "html-empty-link": {
        "category": "HTML Structure",
        "severity": "low",
        "title": "Link with empty or placeholder href",
        "why": "href=\"\" or \"#\" links go nowhere (or jump to the top), confusing users and crawlers. If it is really a button, it should be a <button>.",
        "how": "Point the link at a real URL, or use a <button> for JavaScript actions."
    },
    "html-heading-skip": {
        "category": "HTML Structure",
        "severity": "low",
        "title": "Skipped heading level",
        "why": "Jumping from <h2> to <h4> breaks the document outline that screen-reader users navigate by, and signals a confusing content hierarchy (WCAG 1.3.1).",
        "how": "Use heading levels in order; style headings with CSS rather than picking levels by size."
    },
    "html-inline-styles": {
        "category": "HTML Structure",
        "severity": "info",
        "title": "Inline styles",
        "why": "Inline style attributes can't be cached, are hard to maintain, override stylesheets and block a strict CSP.",
        "how": "Move the styles into CSS classes."
    },
    "sec-eval": {
        "category": "Security",
        "severity": "high",
        "title": "Use of eval()",
        "why": "eval executes arbitrary strings as code; if any part is user-controlled it becomes a code-injection hole. It also blocks optimisations and a strict CSP.",
        "how": "Replace eval with explicit parsing (JSON.parse), lookups or functions."
    },
    "sec-hardcoded-secret": {
        "category": "Security",
        "severity": "critical",
        "title": "Hard-coded secret",
        "why": "Secrets committed to code end up in version control, backups and — for front-end files — in every visitor's browser. Anyone who sees them can use your account.",
        "how": "Revoke/rotate the secret, then load it from an environment variable or secret manager on the server."
    },
    "sec-insecure-resource": {
        "category": "Security",
        "severity": "medium",
        "title": "Resource loaded over HTTP",
        "why": "Scripts, styles, images or frames loaded over plain http can be intercepted and modified, and on https pages browsers block or warn about them (mixed content).",
        "how": "Load the resource over https (check it's available there)."
    },
    "sec-not-https": {
        "category": "Security",
        "severity": "high",
        "title": "Site not served over HTTPS",
        "why": "Without HTTPS, traffic can be read and modified in transit, browsers show \"Not secure\", and rankings suffer.",
        "how": "Install a TLS certificate and redirect all http traffic to https."
    },
    "sec-target-blank": {
        "category": "Security",
        "severity": "low",
        "title": "target=\"_blank\" without rel=\"noopener\"",
        "why": "Pages opened with target=_blank can access window.opener in older browsers and redirect your tab (reverse tabnabbing). Modern browsers default to noopener, but being explicit protects older ones.",
        "how": "Add rel=\"noopener noreferrer\" to links that open in a new tab."
    },
    "seo-canonical-missing": {
        "category": "SEO",
        "severity": "low",
        "title": "Missing canonical URL",
        "why": "Without rel=canonical, URL variants (tracking parameters, trailing slashes, http/https) can be indexed as duplicates and split ranking signals.",
        "how": "Add <link rel=\"canonical\" href=\"…\"> pointing at the preferred absolute URL of this page."
    },
    "seo-h1-missing": {
        "category": "SEO",
        "severity": "medium",
        "title": "No <h1> heading",
        "why": "The <h1> tells users and search engines what the page is about and is the main landmark screen-reader users jump to.",
        "how": "Add one <h1> that states the page's main topic, normally the first heading of the main content."
    },
    "seo-h1-multiple": {
        "category": "SEO",
        "severity": "low",
        "title": "Multiple <h1> headings",
        "why": "Several <h1> elements dilute the page's main topic signal and flatten the outline screen-reader users navigate by.",
        "how": "Keep one <h1> for the page topic and demote the others to <h2> (check CSS that targeted h1)."
    },
    "seo-http-error": {
        "category": "SEO",
        "severity": "critical",
        "title": "Page returned an HTTP error",
        "why": "Error responses are not indexed and break the user journey; persistent errors drop the URL from search.",
        "how": "Fix the server error or redirect the URL to the right page."
    },
    "seo-meta-description-length": {
        "category": "SEO",
        "severity": "low",
        "title": "Meta description length not optimal",
        "why": "Descriptions over ~160 characters are cut off in results; very short ones under-sell the page and are often replaced by the search engine.",
        "how": "Rewrite the description to 120–160 characters summarising the page's value."
    },
    "seo-meta-description-missing": {
        "category": "SEO",
        "severity": "medium",
        "title": "Missing meta description",
        "why": "Without a meta description, search engines pull a random fragment of page text for the result snippet, which usually lowers click-through. Social and chat link previews also fall back to it.",
        "how": "Add <meta name=\"description\"> with a 120–160 character summary that matches the page and invites the click."
    },
    "seo-noindex": {
        "category": "SEO",
        "severity": "high",
        "title": "Page blocked from indexing",
        "why": "A robots noindex directive removes the page from search results entirely — right for staging or private pages, a serious mistake on a public page.",
        "how": "Remove the noindex directive if this page should be found in search."
    },
    "seo-og-missing": {
        "category": "SEO",
        "severity": "low",
        "title": "Missing Open Graph tags",
        "why": "Facebook, LinkedIn, Slack, WhatsApp and others build link previews from og: tags. Without them previews show the wrong text or no image, which reduces shares and clicks.",
        "how": "Add og:title and og:description (ideally og:image and og:url too) to <head>."
    },
    "seo-robots-missing": {
        "category": "SEO",
        "severity": "low",
        "title": "robots.txt not found",
        "why": "robots.txt tells crawlers what they may fetch and where the sitemap is. A missing file isn't fatal but loses control over crawling and sitemap discovery.",
        "how": "Publish /robots.txt with crawl rules and a `Sitemap:` line."
    },
    "seo-sitemap-missing": {
        "category": "SEO",
        "severity": "low",
        "title": "XML sitemap not found",
        "why": "A sitemap helps search engines discover every page and notice updates quickly, especially on new or large sites.",
        "how": "Generate /sitemap.xml (most CMSs and SEO plugins can) and reference it in robots.txt."
    },
    "seo-slow-response": {
        "category": "SEO",
        "severity": "medium",
        "title": "Slow server response",
        "why": "Slow server response (TTFB) delays everything else, hurts Core Web Vitals (LCP) and increases bounce rate.",
        "how": "Enable page caching/CDN, optimise database queries and check hosting resources."
    },
    "seo-title-length": {
        "category": "SEO",
        "severity": "medium",
        "title": "Title length not optimal",
        "why": "Search engines truncate titles longer than ~60 characters (≈580px), and very short titles waste the strongest on-page ranking and click-through signal.",
        "how": "Rewrite the title to 30–60 characters with the primary topic first and the brand at the end."
    },
    "seo-title-missing": {
        "category": "SEO",
        "severity": "high",
        "title": "Missing page title",
        "why": "The <title> is the clickable headline in search results and the label of the browser tab. Without it search engines invent one (often badly), users can't tell tabs apart, and screen readers have nothing to announce on load.",
        "how": "Add a unique, descriptive <title> of about 30–60 characters inside <head>: main topic first, brand last."
    },
    "seo-viewport-missing": {
        "category": "SEO",
        "severity": "high",
        "title": "Missing viewport meta tag",
        "why": "Without a viewport tag, mobile browsers render the page at desktop width and zoom out, making text tiny. Google indexes mobile-first and treats the page as not mobile-friendly.",
        "how": "Add <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"> to <head>."
    }
};

export const CATEGORIES = ["SEO", "Accessibility", "HTML Structure", "Content", "Code", "Security"];
export const SEVERITIES = ["critical", "high", "medium", "low", "info"];

// same weights and curve as app/Analyzer/Scoring.php
const WEIGHTS = { critical: 25, high: 10, medium: 5, low: 2, info: 0.5 };

export function score(issues) {
    const penalty = Object.fromEntries(CATEGORIES.map((c) => [c, 0]));
    for (const i of issues) penalty[i.category] += WEIGHTS[i.severity] ?? 0;
    const categories = Object.fromEntries(CATEGORIES.map((c) => [c, Math.round(100 * Math.exp(-penalty[c] / 60))]));
    const overall = Math.round(Object.values(categories).reduce((a, b) => a + b, 0) / CATEGORIES.length);
    return { overall, categories };
}
