<?php

namespace App\Analyzer\Rules;

/**
 * Rule catalogue: category, default severity and the static (non-AI) explanation for every rule.
 *
 * The `why` / `how` texts are the fallback explanation used when Ollama is not available,
 * so the report is always complete even without AI.
 */
final class Catalog
{
    /** @var array<string, array{category:string, severity:string, title:string, why:string, how:string}> */
    public const RULES = [
        'seo-title-missing' => [
            'category' => 'SEO', 'severity' => 'high', 'title' => 'Missing page title',
            'why' => 'The <title> is the clickable headline in search results and the label of the browser tab. Without it search engines invent one (often badly), users can\'t tell tabs apart, and screen readers have nothing to announce on load.',
            'how' => 'Add a unique, descriptive <title> of about 30–60 characters inside <head>: main topic first, brand last.',
        ],
        'seo-title-length' => [
            'category' => 'SEO', 'severity' => 'medium', 'title' => 'Title length not optimal',
            'why' => 'Search engines truncate titles longer than ~60 characters (≈580px), and very short titles waste the strongest on-page ranking and click-through signal.',
            'how' => 'Rewrite the title to 30–60 characters with the primary topic first and the brand at the end.',
        ],
        'seo-meta-description-missing' => [
            'category' => 'SEO', 'severity' => 'medium', 'title' => 'Missing meta description',
            'why' => 'Without a meta description, search engines pull a random fragment of page text for the result snippet, which usually lowers click-through. Social and chat link previews also fall back to it.',
            'how' => 'Add <meta name="description"> with a 120–160 character summary that matches the page and invites the click.',
        ],
        'seo-meta-description-length' => [
            'category' => 'SEO', 'severity' => 'low', 'title' => 'Meta description length not optimal',
            'why' => 'Descriptions over ~160 characters are cut off in results; very short ones under-sell the page and are often replaced by the search engine.',
            'how' => 'Rewrite the description to 120–160 characters summarising the page\'s value.',
        ],
        'seo-h1-missing' => [
            'category' => 'SEO', 'severity' => 'medium', 'title' => 'No <h1> heading',
            'why' => 'The <h1> tells users and search engines what the page is about and is the main landmark screen-reader users jump to.',
            'how' => 'Add one <h1> that states the page\'s main topic, normally the first heading of the main content.',
        ],
        'seo-h1-multiple' => [
            'category' => 'SEO', 'severity' => 'low', 'title' => 'Multiple <h1> headings',
            'why' => 'Several <h1> elements dilute the page\'s main topic signal and flatten the outline screen-reader users navigate by.',
            'how' => 'Keep one <h1> for the page topic and demote the others to <h2> (check CSS that targeted h1).',
        ],
        'seo-canonical-missing' => [
            'category' => 'SEO', 'severity' => 'low', 'title' => 'Missing canonical URL',
            'why' => 'Without rel=canonical, URL variants (tracking parameters, trailing slashes, http/https) can be indexed as duplicates and split ranking signals.',
            'how' => 'Add <link rel="canonical" href="…"> pointing at the preferred absolute URL of this page.',
        ],
        'seo-viewport-missing' => [
            'category' => 'SEO', 'severity' => 'high', 'title' => 'Missing viewport meta tag',
            'why' => 'Without a viewport tag, mobile browsers render the page at desktop width and zoom out, making text tiny. Google indexes mobile-first and treats the page as not mobile-friendly.',
            'how' => 'Add <meta name="viewport" content="width=device-width, initial-scale=1"> to <head>.',
        ],
        'seo-og-missing' => [
            'category' => 'SEO', 'severity' => 'low', 'title' => 'Missing Open Graph tags',
            'why' => 'Facebook, LinkedIn, Slack, WhatsApp and others build link previews from og: tags. Without them previews show the wrong text or no image, which reduces shares and clicks.',
            'how' => 'Add og:title and og:description (ideally og:image and og:url too) to <head>.',
        ],
        'seo-noindex' => [
            'category' => 'SEO', 'severity' => 'high', 'title' => 'Page blocked from indexing',
            'why' => 'A robots noindex directive removes the page from search results entirely — right for staging or private pages, a serious mistake on a public page.',
            'how' => 'Remove the noindex directive if this page should be found in search.',
        ],
        'seo-robots-missing' => [
            'category' => 'SEO', 'severity' => 'low', 'title' => 'robots.txt not found',
            'why' => 'robots.txt tells crawlers what they may fetch and where the sitemap is. A missing file isn\'t fatal but loses control over crawling and sitemap discovery.',
            'how' => 'Publish /robots.txt with crawl rules and a `Sitemap:` line.',
        ],
        'seo-sitemap-missing' => [
            'category' => 'SEO', 'severity' => 'low', 'title' => 'XML sitemap not found',
            'why' => 'A sitemap helps search engines discover every page and notice updates quickly, especially on new or large sites.',
            'how' => 'Generate /sitemap.xml (most CMSs and SEO plugins can) and reference it in robots.txt.',
        ],
        'seo-slow-response' => [
            'category' => 'SEO', 'severity' => 'medium', 'title' => 'Slow server response',
            'why' => 'Slow server response (TTFB) delays everything else, hurts Core Web Vitals (LCP) and increases bounce rate.',
            'how' => 'Enable page caching/CDN, optimise database queries and check hosting resources.',
        ],
        'seo-http-error' => [
            'category' => 'SEO', 'severity' => 'critical', 'title' => 'Page returned an HTTP error',
            'why' => 'Error responses are not indexed and break the user journey; persistent errors drop the URL from search.',
            'how' => 'Fix the server error or redirect the URL to the right page.',
        ],
        'a11y-lang-missing' => [
            'category' => 'Accessibility', 'severity' => 'medium', 'title' => 'Missing page language',
            'why' => 'Screen readers use the lang attribute to choose pronunciation; translation tools and search engines use it to detect the language (WCAG 3.1.1).',
            'how' => 'Add lang="en" (or the correct language code) to the <html> element.',
        ],
        'a11y-img-alt-missing' => [
            'category' => 'Accessibility', 'severity' => 'high', 'title' => 'Image without alt text',
            'why' => 'Screen readers can\'t describe an image without alt text — many read out the file name instead. Search engines also rely on alt text to understand images (WCAG 1.1.1).',
            'how' => 'Add an alt attribute describing the image\'s purpose; use alt="" only for purely decorative images.',
        ],
        'a11y-link-no-text' => [
            'category' => 'Accessibility', 'severity' => 'high', 'title' => 'Link without accessible name',
            'why' => 'A link with no text (e.g. icon-only) is announced as just "link", so screen-reader users can\'t know where it goes (WCAG 2.4.4, 4.1.2).',
            'how' => 'Add visible text, an aria-label, or alt text on the image inside the link describing its destination.',
        ],
        'a11y-button-no-name' => [
            'category' => 'Accessibility', 'severity' => 'high', 'title' => 'Button without accessible name',
            'why' => 'An icon-only button with no label is announced as just "button" — users can\'t tell what it does (WCAG 4.1.2).',
            'how' => 'Add visible text or an aria-label that describes the action (e.g. "Open menu").',
        ],
        'a11y-input-no-label' => [
            'category' => 'Accessibility', 'severity' => 'high', 'title' => 'Form field without label',
            'why' => 'Without a label, screen-reader users only hear "edit text" and don\'t know what to enter (WCAG 1.3.1, 3.3.2). Placeholders are not a substitute — they disappear while typing.',
            'how' => 'Associate a <label for="…"> with the field, or add aria-label if a visible label is impossible.',
        ],
        'a11y-iframe-title' => [
            'category' => 'Accessibility', 'severity' => 'medium', 'title' => 'Iframe without title',
            'why' => 'Screen readers announce iframes by their title; without one users can\'t tell what the embedded content is (WCAG 4.1.2).',
            'how' => 'Add a title attribute describing the embedded content, e.g. title="Office location map".',
        ],
        'a11y-generic-link-text' => [
            'category' => 'Accessibility', 'severity' => 'low', 'title' => 'Non-descriptive link text',
            'why' => 'Screen-reader users often browse a list of links out of context; "click here" or "read more" says nothing. Search engines also use anchor text to understand the target page (WCAG 2.4.4).',
            'how' => 'Rewrite the link text to describe the destination, e.g. "Read our pricing guide".',
        ],
        'a11y-positive-tabindex' => [
            'category' => 'Accessibility', 'severity' => 'low', 'title' => 'Positive tabindex',
            'why' => 'tabindex values above 0 override the natural keyboard order, making focus jump around unpredictably (WCAG 2.4.3).',
            'how' => 'Use tabindex="0" (or remove it) and fix the order in the DOM instead.',
        ],
        'html-doctype-missing' => [
            'category' => 'HTML Structure', 'severity' => 'medium', 'title' => 'Missing <!DOCTYPE html>',
            'why' => 'Without a doctype, browsers render in quirks mode and emulate legacy layout bugs, causing inconsistent rendering.',
            'how' => 'Add <!DOCTYPE html> as the very first line of the document.',
        ],
        'html-charset-missing' => [
            'category' => 'HTML Structure', 'severity' => 'medium', 'title' => 'Missing character encoding',
            'why' => 'Without a declared charset browsers may guess the encoding and show garbled characters (é → Ã©), and some encodings open XSS vectors.',
            'how' => 'Add <meta charset="utf-8"> as the first element in <head>.',
        ],
        'html-duplicate-id' => [
            'category' => 'HTML Structure', 'severity' => 'high', 'title' => 'Duplicate id attribute',
            'why' => 'ids must be unique. Duplicates break label/aria references, anchor links and JavaScript like getElementById, which silently picks the first match.',
            'how' => 'Give each element a unique id and update any CSS/JS/label references to it.',
        ],
        'html-deprecated-tag' => [
            'category' => 'HTML Structure', 'severity' => 'medium', 'title' => 'Deprecated HTML element',
            'why' => 'Obsolete presentational elements (<center>, <font>, <marquee>…) are not part of HTML5, mix styling into markup and may be dropped or rendered inconsistently.',
            'how' => 'Replace with semantic elements and move the styling into CSS.',
        ],
        'html-heading-skip' => [
            'category' => 'HTML Structure', 'severity' => 'low', 'title' => 'Skipped heading level',
            'why' => 'Jumping from <h2> to <h4> breaks the document outline that screen-reader users navigate by, and signals a confusing content hierarchy (WCAG 1.3.1).',
            'how' => 'Use heading levels in order; style headings with CSS rather than picking levels by size.',
        ],
        'html-unclosed-element' => [
            'category' => 'HTML Structure', 'severity' => 'medium', 'title' => 'Unclosed element',
            'why' => 'The browser has to guess where the element ends; different browsers may guess differently, producing broken layouts and unexpected styling.',
            'how' => 'Add the missing closing tag in the correct place.',
        ],
        'html-stray-end-tag' => [
            'category' => 'HTML Structure', 'severity' => 'low', 'title' => 'Stray closing tag',
            'why' => 'A closing tag with no matching opening tag indicates broken nesting, often from a copy/paste or template error.',
            'how' => 'Remove the stray tag or add the missing opening tag.',
        ],
        'html-inline-styles' => [
            'category' => 'HTML Structure', 'severity' => 'info', 'title' => 'Inline styles',
            'why' => 'Inline style attributes can\'t be cached, are hard to maintain, override stylesheets and block a strict CSP.',
            'how' => 'Move the styles into CSS classes.',
        ],
        'html-empty-link' => [
            'category' => 'HTML Structure', 'severity' => 'low', 'title' => 'Link with empty or placeholder href',
            'why' => 'href="" or "#" links go nowhere (or jump to the top), confusing users and crawlers. If it is really a button, it should be a <button>.',
            'how' => 'Point the link at a real URL, or use a <button> for JavaScript actions.',
        ],
        'content-thin' => [
            'category' => 'Content', 'severity' => 'low', 'title' => 'Thin content',
            'why' => 'Pages with very little text give search engines little to rank and users little reason to stay.',
            'how' => 'Expand the page with genuinely useful content that answers the visitor\'s question (aim for 300+ words on key pages).',
        ],
        'content-placeholder' => [
            'category' => 'Content', 'severity' => 'high', 'title' => 'Placeholder text left in page',
            'why' => 'Lorem ipsum or dummy text looks unprofessional, erodes trust and can be indexed by search engines.',
            'how' => 'Replace the placeholder with the real content.',
        ],
        'content-repeated-word' => [
            'category' => 'Content', 'severity' => 'low', 'title' => 'Repeated word',
            'why' => 'Doubled words ("the the") are typos that make content look careless.',
            'how' => 'Remove the duplicate word.',
        ],
        'content-long-sentence' => [
            'category' => 'Content', 'severity' => 'info', 'title' => 'Very long sentence',
            'why' => 'Sentences over ~35–40 words are hard to read, especially on mobile and for non-native readers.',
            'how' => 'Split the sentence into two or three shorter ones.',
        ],
        'code-syntax-error' => [
            'category' => 'Code', 'severity' => 'critical', 'title' => 'Syntax error',
            'why' => 'The file cannot be parsed, so the code (or config) fails to run at all — often breaking every feature that depends on it.',
            'how' => 'Fix the syntax at the reported line.',
        ],
        'code-console-log' => [
            'category' => 'Code', 'severity' => 'low', 'title' => 'Debug logging left in code',
            'why' => 'console.log calls leak internal data to anyone opening DevTools, add noise and cost a little performance.',
            'how' => 'Remove debug logging, or route it through a logger that is disabled in production.',
        ],
        'code-debugger' => [
            'category' => 'Code', 'severity' => 'medium', 'title' => 'debugger statement',
            'why' => 'A `debugger` statement pauses execution whenever DevTools is open and should never ship to production.',
            'how' => 'Remove the statement.',
        ],
        'code-loose-equality' => [
            'category' => 'Code', 'severity' => 'low', 'title' => 'Loose equality (==)',
            'why' => '== performs type coercion (0 == \'\' is true, \'1\' == 1 is true), a classic source of subtle bugs.',
            'how' => 'Use === / !== unless coercion is intentional.',
        ],
        'code-var' => [
            'category' => 'Code', 'severity' => 'info', 'title' => 'var declaration',
            'why' => 'var is function-scoped and hoisted, which allows accidental reuse and use-before-assignment bugs.',
            'how' => 'Use const (or let when the variable is reassigned).',
        ],
        'code-todo' => [
            'category' => 'Code', 'severity' => 'info', 'title' => 'TODO / FIXME comment',
            'why' => 'Unresolved TODO/FIXME markers indicate unfinished work that may hide known bugs.',
            'how' => 'Resolve the item or move it into the issue tracker.',
        ],
        'css-empty-rule' => [
            'category' => 'Code', 'severity' => 'low', 'title' => 'Empty CSS rule',
            'why' => 'Empty rule sets are dead code that adds bytes and confusion.',
            'how' => 'Remove the empty rule.',
        ],
        'css-important-overuse' => [
            'category' => 'Code', 'severity' => 'low', 'title' => 'Heavy use of !important',
            'why' => 'Frequent !important is a sign of specificity wars; it makes styles hard to override and maintain.',
            'how' => 'Reduce selector specificity / reorganise the cascade instead of forcing with !important.',
        ],
        'code-broken-resource' => [
            'category' => 'Code', 'severity' => 'high', 'title' => 'Broken file reference',
            'why' => 'The page asks for a script, stylesheet or image that does not load. A missing script can break menus, forms and checkout; a missing stylesheet breaks the layout; a missing image shows a broken icon. Every failed request also slows the page.',
            'how' => 'Fix the path, upload the missing file, or remove the reference if it is no longer used.',
        ],
        'sec-hardcoded-secret' => [
            'category' => 'Security', 'severity' => 'critical', 'title' => 'Hard-coded secret',
            'why' => 'Secrets committed to code end up in version control, backups and — for front-end files — in every visitor\'s browser. Anyone who sees them can use your account.',
            'how' => 'Revoke/rotate the secret, then load it from an environment variable or secret manager on the server.',
        ],
        'sec-eval' => [
            'category' => 'Security', 'severity' => 'high', 'title' => 'Use of eval()',
            'why' => 'eval executes arbitrary strings as code; if any part is user-controlled it becomes a code-injection hole. It also blocks optimisations and a strict CSP.',
            'how' => 'Replace eval with explicit parsing (JSON.parse), lookups or functions.',
        ],
        'sec-target-blank' => [
            'category' => 'Security', 'severity' => 'low', 'title' => 'target="_blank" without rel="noopener"',
            'why' => 'Pages opened with target=_blank can access window.opener in older browsers and redirect your tab (reverse tabnabbing). Modern browsers default to noopener, but being explicit protects older ones.',
            'how' => 'Add rel="noopener noreferrer" to links that open in a new tab.',
        ],
        'sec-insecure-resource' => [
            'category' => 'Security', 'severity' => 'medium', 'title' => 'Resource loaded over HTTP',
            'why' => 'Scripts, styles, images or frames loaded over plain http can be intercepted and modified, and on https pages browsers block or warn about them (mixed content).',
            'how' => 'Load the resource over https (check it\'s available there).',
        ],
        'sec-tls-chain-incomplete' => [
            'category' => 'Security', 'severity' => 'high', 'title' => 'Incomplete SSL certificate chain',
            'why' => 'Browsers quietly download the missing intermediate certificate, but cURL, PHP, many search and social crawlers, payment webhooks and some phones do not. For them the site fails with "unable to get local issuer certificate".',
            'how' => 'Install the full chain on the server (e.g. fullchain.pem, or your certificate followed by the CA bundle your provider gave you), then check it with an SSL test such as SSL Labs.',
        ],
        'sec-not-https' => [
            'category' => 'Security', 'severity' => 'high', 'title' => 'Site not served over HTTPS',
            'why' => 'Without HTTPS, traffic can be read and modified in transit, browsers show "Not secure", and rankings suffer.',
            'how' => 'Install a TLS certificate and redirect all http traffic to https.',
        ],
        // ---- website security headers and cookies (read from the response, nothing is probed)
        'sec-hsts-missing' => [
            'category' => 'Security', 'severity' => 'medium', 'title' => 'No Strict-Transport-Security header',
            'why' => 'Without HSTS a visitor\'s first request can still go over plain HTTP, where an attacker on the same network can intercept it or strip the redirect to HTTPS.',
            'how' => 'Send "Strict-Transport-Security: max-age=31536000; includeSubDomains" on every HTTPS response (in the web server, CDN or app).',
        ],
        'sec-hsts-weak' => [
            'category' => 'Security', 'severity' => 'low', 'title' => 'Strict-Transport-Security max-age is short',
            'why' => 'A short max-age means browsers forget to force HTTPS soon after a visit, so the protection lapses between visits.',
            'how' => 'Raise max-age to at least 15768000 (6 months); 31536000 (1 year) is the usual value.',
        ],
        'sec-csp-missing' => [
            'category' => 'Security', 'severity' => 'medium', 'title' => 'No Content-Security-Policy',
            'why' => 'A Content-Security-Policy limits where scripts, styles and frames may load from. Without one, any injected script (XSS) runs with full access to the page and its visitors.',
            'how' => 'Add a Content-Security-Policy header. Start with Content-Security-Policy-Report-Only to see what would be blocked, then enforce it.',
        ],
        'sec-clickjacking' => [
            'category' => 'Security', 'severity' => 'medium', 'title' => 'Page can be framed by other sites (clickjacking)',
            'why' => 'Without X-Frame-Options or a CSP frame-ancestors rule, another site can load this page in an invisible frame and trick visitors into clicking buttons on it.',
            'how' => 'Send "X-Frame-Options: SAMEORIGIN", or add "frame-ancestors \'self\'" to the Content-Security-Policy.',
        ],
        'sec-nosniff-missing' => [
            'category' => 'Security', 'severity' => 'low', 'title' => 'No X-Content-Type-Options header',
            'why' => 'Without "nosniff" browsers may guess a file\'s type, so an uploaded or user-supplied file can be run as a script or stylesheet.',
            'how' => 'Send "X-Content-Type-Options: nosniff" on all responses.',
        ],
        'sec-referrer-policy-missing' => [
            'category' => 'Security', 'severity' => 'low', 'title' => 'No Referrer-Policy header',
            'why' => 'Without a policy, older browsers send the full page address (including any tokens or IDs in it) to every site a visitor clicks through to.',
            'how' => 'Send "Referrer-Policy: strict-origin-when-cross-origin" (or stricter).',
        ],
        'sec-permissions-policy-missing' => [
            'category' => 'Security', 'severity' => 'info', 'title' => 'No Permissions-Policy header',
            'why' => 'A Permissions-Policy switches off browser features the site never uses (camera, microphone, location…), so injected or third-party code can\'t ask for them.',
            'how' => 'Send e.g. "Permissions-Policy: camera=(), microphone=(), geolocation=()", allowing only what the site needs.',
        ],
        'sec-version-disclosure' => [
            'category' => 'Security', 'severity' => 'low', 'title' => 'Server software version is disclosed',
            'why' => 'Headers that name exact versions (e.g. "Apache/2.4.41", "PHP/7.4.3") tell attackers which known vulnerabilities to try first.',
            'how' => 'Hide versions: ServerTokens Prod (Apache), server_tokens off (Nginx), expose_php = Off (PHP), and remove X-Powered-By.',
        ],
        'sec-cookie-flags' => [
            'category' => 'Security', 'severity' => 'medium', 'title' => 'Cookie set without security flags',
            'why' => 'Cookies without Secure can leak over plain HTTP, without HttpOnly they can be read by injected scripts (session theft), and without SameSite they are sent with cross-site requests (CSRF).',
            'how' => 'Set cookies with Secure; HttpOnly (unless the page\'s JavaScript must read it); SameSite=Lax (or Strict).',
        ],
    ];

    public static function get(string $rule): array
    {
        return self::RULES[$rule];
    }
}
