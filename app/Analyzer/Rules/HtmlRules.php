<?php

namespace App\Analyzer\Rules;

use App\Analyzer\Document;
use App\Analyzer\Html\HtmlEdit;
use App\Analyzer\Html\HtmlParser;
use App\Analyzer\Html\Node;
use App\Analyzer\Html\Tree;
use App\Analyzer\Issue;

/**
 * HTML rules: SEO, accessibility, structure, content and HTML-level security checks.
 */
final class HtmlRules
{
    private const GENERIC_LINK_TEXT = ['click here', 'here', 'read more', 'more', 'learn more', 'click', 'link',
        'this link', 'more info', 'details', 'go', 'continue', 'view more'];

    // tag => [replacement tag, replacement attributes or null to keep them]
    private const DEPRECATED = [
        'center' => ['div', ' style="text-align: center"'],
        'font' => ['span', null],
        'big' => ['span', ' style="font-size: larger"'],
        'strike' => ['s', null],
        'tt' => ['code', null],
        'acronym' => ['abbr', null],
        'marquee' => [null, null],
        'blink' => ['span', ''],
    ];

    private const FONT_SIZES = ['1' => 'x-small', '2' => 'small', '3' => 'medium', '4' => 'large', '5' => 'x-large',
        '6' => 'xx-large', '7' => 'xxx-large'];

    private const SOCIAL = ['twitter.com' => 'Twitter', 'x.com' => 'X', 'facebook.com' => 'Facebook',
        'instagram.com' => 'Instagram', 'linkedin.com' => 'LinkedIn', 'youtube.com' => 'YouTube',
        'github.com' => 'GitHub', 'tiktok.com' => 'TikTok', 'pinterest.com' => 'Pinterest'];

    private const LABEL_EXEMPT_INPUTS = ['hidden', 'submit', 'button', 'reset', 'image'];

    /** @return Issue[] */
    public static function check(Document $doc): array
    {
        $tree = $doc->tree();
        $out = [];
        if ($tree->isPage()) {
            array_push($out, ...self::head($doc, $tree));
        }
        array_push($out, ...self::headings($doc, $tree));
        array_push($out, ...self::structure($doc, $tree));
        array_push($out, ...self::accessibility($doc, $tree));
        array_push($out, ...self::linksAndResources($doc, $tree));
        array_push($out, ...self::content($doc, $tree));
        foreach ($tree->findAll('script') as $script) {
            if (! $script->has('src') && $script->closedProperly()) {
                array_push($out, ...CodeRules::scanJs($doc, $script->startEnd, $script->endTagStart));
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------------------- <head>

    private static function indentOf(Document $doc, int $offset): string
    {
        $lineStart = strrpos(substr($doc->text, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        preg_match('/[ \t]*/A', $doc->text, $m, 0, $lineStart);

        return $m[0];
    }

    /** Insert just inside `node`'s start tag: [offset, lead, trail] whitespace matching the file. */
    private static function insertionPoint(Document $doc, Node $node): array
    {
        $at = $node->startEnd;
        if (preg_match('/\G\r?\n([ \t]*)/', $doc->text, $m, 0, $at) && $m[1] !== '') {
            return [$at, $m[0], ''];
        }

        return [$at, $doc->newline().self::indentOf($doc, $node->start).'    ', ''];
    }

    /** Insert just before </head>, on its own line when possible. */
    private static function appendPoint(Document $doc, ?Node $node): ?array
    {
        if ($node === null || $node->tag !== 'head' || ! $node->closedProperly()) {
            return null;
        }
        $at = $node->endTagStart;
        $nl = strrpos(substr($doc->text, 0, $at), "\n");
        $lineStart = $nl === false ? 0 : $nl + 1;
        if (trim(substr($doc->text, $lineStart, $at - $lineStart)) !== '') {
            return null;
        }
        $prevEnd = max($lineStart - 1, 0);
        $prevNl = strrpos(substr($doc->text, 0, max($prevEnd - 1, 0)), "\n");
        $prevStart = $prevNl === false ? 0 : $prevNl + 1;
        preg_match('/[ \t]*/A', $doc->text, $m, 0, $prevStart);
        $indent = $m[0];
        if ($prevStart <= $node->start) {
            $indent .= '    ';
        }

        return [$lineStart, $indent, $doc->newline()];
    }

    private static function wrap(array $lines, string $lead, string $trail): ?string
    {
        if (! $lines) {
            return null;
        }
        $pos = strrpos($lead, "\n");
        $indent = $pos === false ? $lead : substr($lead, $pos + 1);
        $sep = $trail !== '' ? $trail.$indent : $lead;

        return $lead.implode($sep, $lines).$trail;
    }

    /** @return Issue[] */
    private static function head(Document $doc, Tree $tree): array
    {
        $out = [];
        $text = $doc->text;
        $head = $tree->find('head');
        $htmlEl = $tree->find('html');
        $anchor = $head ?? $htmlEl;
        $metas = $tree->findAll('meta');

        $meta = function (?string $name = null, ?string $prop = null) use ($metas): ?Node {
            foreach ($metas as $m) {
                if ($name && strtolower($m->get('name')) === $name) {
                    return $m;
                }
                if ($prop && strtolower($m->get('property')) === $prop) {
                    return $m;
                }
            }

            return null;
        };

        if (! $tree->doctype) {
            $out[] = Make::issue($doc, 'html-doctype-missing', 'The document has no <!DOCTYPE html> declaration.',
                start: 0, end: 0, line: 1, fix: '<!DOCTYPE html>'.$doc->newline());
        }
        if ($anchor === null) {
            return $out;
        }
        $top = self::insertionPoint($doc, $anchor);
        $bottom = self::appendPoint($doc, $head) ?? $top;

        $insert = function (string $rule, string $message, array|string $lines, string $note = '', bool $atEnd = false) use (&$out, $doc, $top, $bottom) {
            [$at, $lead, $trail] = $atEnd ? $bottom : $top;
            $lines = array_values(array_filter(is_string($lines) ? [$lines] : $lines, fn ($l) => $l !== ''));
            $out[] = Make::issue($doc, $rule, $message, start: $at, end: $at, fix: self::wrap($lines, $lead, $trail),
                note: $lines ? $note : '', wrap: [$lead, $trail]);
        };

        if ($htmlEl !== null && trim($htmlEl->get('lang')) === '') {
            $out[] = Make::issue($doc, 'a11y-lang-missing', 'The <html> element has no lang attribute.',
                start: $htmlEl->start, end: $htmlEl->startEnd, fix: HtmlEdit::setAttr($htmlEl->raw, 'lang', 'en'),
                note: 'Assumes English — change the code if the page is in another language.');
        }

        $hasCharset = false;
        foreach ($metas as $m) {
            if ($m->has('charset') || strtolower($m->get('http-equiv')) === 'content-type') {
                $hasCharset = true;
            }
        }
        if (! $hasCharset) {
            $insert('html-charset-missing', 'No <meta charset> declaration found.', '<meta charset="utf-8">');
        }

        if ($meta('viewport') === null) {
            $insert('seo-viewport-missing', 'No responsive viewport meta tag found.',
                '<meta name="viewport" content="width=device-width, initial-scale=1">');
        }

        $h1 = $tree->find('h1');
        $h1Text = $h1?->text() ?? '';
        $titles = $tree->findAll('title');
        $titleText = '';
        if (! $titles) {
            $guess = $h1Text !== '' ? $h1Text : HtmlEdit::humanize($doc->rel);
            $insert('seo-title-missing', 'The page has no <title> element.',
                $guess !== '' ? '<title>'.HtmlEdit::escapeText(HtmlEdit::truncate($guess, 60, false)).'</title>' : '',
                "Generated from the page's main heading — review the wording.");
        } else {
            $t = $titles[0];
            $titleText = $t->text();
            $n = mb_strlen($titleText);
            if ($n < 10 || $n > 60) {
                $region = $t->elementRegion();
                $fix = null;
                if ($n > 60 && $region && $t->closedProperly()) {
                    $fix = substr($text, $t->start, $t->startEnd - $t->start)
                        .HtmlEdit::escapeText(HtmlEdit::truncate($titleText, 60, false))
                        .substr($text, $t->endTagStart, $t->end - $t->endTagStart);
                }
                $msg = $n === 0 ? 'The <title> is empty.'
                    : "The title is {$n} characters (recommended 30–60): “".HtmlEdit::truncate($titleText, 80).'”.';
                $out[] = Make::issue($doc, 'seo-title-length', $msg, start: $region[0] ?? -1, end: $region[1] ?? -1,
                    line: $t->line, fix: $fix,
                    note: $fix ? 'Truncated at a word boundary — an AI rewrite usually reads better.' : '');
            }
        }

        $desc = $meta('description');
        $descText = $desc ? trim($desc->get('content')) : '';
        if ($desc === null) {
            $firstP = '';
            foreach ($tree->findAll('p') as $p) {
                if (mb_strlen($p->text()) >= 50) {
                    $firstP = $p->text();
                    break;
                }
            }
            $snippet = $firstP !== '' ? '<meta name="description" content="'.HtmlEdit::escape(HtmlEdit::truncate($firstP, 155)).'">' : '';
            $insert('seo-meta-description-missing', 'No <meta name="description"> found.', $snippet,
                'Built from the first paragraph — ask AI for a purpose-written summary.', true);
        } else {
            $n = mb_strlen($descText);
            if ($n < 50 || $n > 160) {
                $fix = $n > 160 ? HtmlEdit::setAttr($desc->raw, 'content', HtmlEdit::truncate($descText, 158)) : null;
                $out[] = Make::issue($doc, 'seo-meta-description-length',
                    "The meta description is {$n} characters (recommended 120–160).",
                    start: $desc->start, end: $desc->startEnd, fix: $fix, note: $fix ? 'Truncated at a word boundary.' : '');
            }
        }

        $robots = $meta('robots');
        if ($robots !== null && str_contains(strtolower($robots->get('content')), 'noindex')) {
            $out[] = Make::issue($doc, 'seo-noindex', 'meta robots is “'.$robots->get('content').'”.',
                start: $robots->start, end: $robots->startEnd);
        }

        if ($meta(prop: 'og:title') === null) {
            $ogTitle = $titleText !== '' ? $titleText : $h1Text;
            $lines = [];
            if ($ogTitle !== '') {
                $lines[] = '<meta property="og:title" content="'.HtmlEdit::escape($ogTitle).'">';
            }
            if ($descText !== '' && $meta(prop: 'og:description') === null) {
                $lines[] = '<meta property="og:description" content="'.HtmlEdit::escape($descText).'">';
            }
            $insert('seo-og-missing', 'No Open Graph (og:title / og:description) tags found.', $lines,
                'Copied from the existing title/description.', true);
        }

        $canonical = false;
        foreach ($tree->findAll('link') as $l) {
            if (in_array('canonical', preg_split('/\s+/', strtolower($l->get('rel'))), true)) {
                $canonical = true;
            }
        }
        if (! $canonical) {
            if ($doc->url) {
                $insert('seo-canonical-missing', 'No <link rel="canonical"> found.',
                    '<link rel="canonical" href="'.HtmlEdit::escape(explode('#', $doc->url)[0]).'">',
                    "Uses the analysed URL — confirm it's the preferred version.", true);
            } else {
                $insert('seo-canonical-missing', "No <link rel=\"canonical\"> found (the site's domain is unknown for local files).", '', '', true);
            }
        }

        if (! $tree->findAll('h1')) {
            $body = $tree->find('body');
            $out[] = Make::issue($doc, 'seo-h1-missing', 'The page has no <h1> heading.', line: $body?->line ?? 1);
        }

        return $out;
    }

    /** @return Issue[] */
    private static function headings(Document $doc, Tree $tree): array
    {
        $out = [];
        foreach (array_slice($tree->findAll('h1'), 1) as $extra) {
            $region = $extra->elementRegion();
            $fix = $region ? HtmlEdit::renameElement(substr($doc->text, $region[0], $region[1] - $region[0]), 'h1', 'h2') : null;
            $out[] = Make::issue($doc, 'seo-h1-multiple', 'Additional <h1>: “'.HtmlEdit::truncate($extra->text(), 60).'”.',
                start: $region[0] ?? -1, end: $region[1] ?? -1, line: $extra->line, fix: $fix,
                note: $fix ? 'Demoted to <h2>; check any CSS that styled it as h1.' : '');
        }
        $prev = 0;
        foreach ($tree->nodes as $node) {
            if (preg_match('/^h([1-6])$/', $node->tag, $m)) {
                $level = (int) $m[1];
                if ($prev && $level > $prev + 1) {
                    $out[] = Make::issue($doc, 'html-heading-skip',
                        "<h{$level}> “".HtmlEdit::truncate($node->text(), 50)."” follows <h{$prev}> — level h".($prev + 1).' is skipped.',
                        line: $node->line);
                }
                $prev = $level;
            }
        }

        return $out;
    }

    private static function fontStyle(Node $node): string
    {
        $css = [];
        if ($node->get('color') !== '') {
            $css[] = 'color: '.$node->get('color');
        }
        if ($node->get('face') !== '') {
            $css[] = 'font-family: '.$node->get('face');
        }
        $size = trim($node->get('size'));
        if (isset(self::FONT_SIZES[$size])) {
            $css[] = 'font-size: '.self::FONT_SIZES[$size];
        }

        return $css ? ' style="'.HtmlEdit::escape(implode('; ', $css)).'"' : '';
    }

    /** @return Issue[] */
    private static function structure(Document $doc, Tree $tree): array
    {
        $out = [];
        $seen = [];
        $taken = [];    // every id in the file, so a renamed one can't collide with a later id
        foreach ($tree->nodes as $node) {
            if ($node->get('id') !== '') {
                $taken[$node->get('id')] = true;
            }
        }
        $len = strlen($doc->text);
        foreach ($tree->nodes as $node) {
            $id = $node->get('id');
            if ($id !== '') {
                if (isset($seen[$id])) {
                    // rename the later one: #id, getElementById and anchors already resolve to the first
                    for ($n = 2; isset($taken["{$id}-{$n}"]); $n++);
                    $taken["{$id}-{$n}"] = true;
                    $fix = HtmlEdit::setAttr(substr($doc->text, $node->start, $node->startEnd - $node->start), 'id', "{$id}-{$n}");
                    $out[] = Make::issue($doc, 'html-duplicate-id', "id=\"{$id}\" is already used on line {$seen[$id]}.",
                        start: $node->start, end: $node->startEnd, fix: $fix,
                        note: "Renamed to \"{$id}-{$n}\". Links, CSS and JS using #{$id} keep pointing at line {$seen[$id]}, as before — pick a more meaningful name if you like.");
                } else {
                    $seen[$id] = $node->line;
                }
            }

            if (isset(self::DEPRECATED[$node->tag])) {
                [$newTag, $attrs] = self::DEPRECATED[$node->tag];
                if ($node->tag === 'font') {
                    $attrs = self::fontStyle($node);
                }
                $region = $node->elementRegion();
                $fix = ($newTag && $region)
                    ? HtmlEdit::renameElement(substr($doc->text, $region[0], $region[1] - $region[0]), $node->tag, $newTag, $attrs)
                    : null;
                $out[] = Make::issue($doc, 'html-deprecated-tag', "<{$node->tag}> is obsolete in HTML5.",
                    start: $region[0] ?? -1, end: $region[1] ?? -1, line: $node->line, fix: $fix,
                    note: ($fix && $attrs) ? 'Styling moved to an inline style — consider a CSS class instead.' : '');
            }

            if ($node->implicitClose && ! in_array($node->tag, HtmlParser::OPTIONAL_CLOSE, true) && ! in_array($node->tag, HtmlParser::VOID, true)) {
                if ($node->end >= $len) {
                    $out[] = Make::issue($doc, 'html-unclosed-element', "<{$node->tag}> opened here is never closed.", line: $node->line);
                } else {
                    $out[] = Make::issue($doc, 'html-unclosed-element',
                        "<{$node->tag}> (opened on line {$node->line}) is not closed before its parent ends.",
                        start: $node->end, end: $node->end, fix: "</{$node->tag}>",
                        note: "Closes <{$node->tag}> right before its parent's end tag — verify that is where it should end.");
                }
            }
        }

        foreach ($tree->stray as [$start, $end, $tag]) {
            $out[] = Make::issue($doc, 'html-stray-end-tag', "</{$tag}> has no matching opening tag.", start: $start, end: $end, fix: '');
        }

        $styled = array_values(array_filter($tree->nodes, fn (Node $n) => $n->get('style') !== ''));
        if (count($styled) >= 3) {
            $out[] = Make::issue($doc, 'html-inline-styles', count($styled).' elements use inline style attributes.', line: $styled[0]->line);
        }

        return $out;
    }

    private static function accessibleName(Node $node): bool
    {
        if (trim($node->get('aria-label')) !== '' || trim($node->get('aria-labelledby')) !== '' || trim($node->get('title')) !== '') {
            return true;
        }
        if ($node->text() !== '') {
            return true;
        }
        foreach ($node->iter() as $child) {
            if ($child->tag === 'img' && trim($child->get('alt')) !== '') {
                return true;
            }
            if ($child->tag === 'svg') {
                if ($child->get('aria-label') !== '') {
                    return true;
                }
                foreach ($child->iter() as $c) {
                    if ($c->tag === 'title') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function hrefLabel(string $href): string
    {
        if (str_starts_with($href, 'mailto:')) {
            return 'Email '.explode('?', substr($href, 7))[0];
        }
        if (str_starts_with($href, 'tel:')) {
            return 'Call '.substr($href, 4);
        }
        $parts = parse_url($href) ?: [];
        $host = preg_replace('/^www\./', '', strtolower($parts['host'] ?? ''));
        $path = $parts['path'] ?? '';
        if (isset(self::SOCIAL[$host])) {
            return self::SOCIAL[$host].' profile';
        }
        if (in_array($path, ['', '/'], true) && $host === '') {
            return 'Home';
        }
        $words = trim($path, '/') !== '' ? HtmlEdit::humanize($path) : '';

        return $words !== '' ? $words : $host;
    }

    /** @return Issue[] */
    private static function accessibility(Document $doc, Tree $tree): array
    {
        $out = [];
        $labelFor = [];
        foreach ($tree->findAll('label') as $l) {
            if ($l->get('for') !== '') {
                $labelFor[$l->get('for')] = true;
            }
        }

        foreach ($tree->findAll('img') as $img) {
            if (! $img->has('alt') && $img->get('role') !== 'presentation' && $img->get('aria-hidden') !== 'true') {
                $src = $img->get('src');
                $alt = HtmlEdit::humanize($src);
                $out[] = Make::issue($doc, 'a11y-img-alt-missing', 'Image “'.($src !== '' ? $src : '(no src)').'” has no alt attribute.',
                    start: $img->start, end: $img->startEnd, fix: HtmlEdit::setAttr($img->raw, 'alt', $alt),
                    note: $alt !== '' ? 'alt text guessed from the file name — describe what the image shows.'
                        : "File name gave no hint, so it's marked decorative (alt=\"\") — add a description if it conveys meaning.");
            }
        }

        foreach ($tree->findAll('iframe') as $iframe) {
            if (trim($iframe->get('title')) === '') {
                $src = $iframe->get('src');
                $host = preg_replace('/^www\./', '', parse_url($src, PHP_URL_HOST) ?? '');
                $guess = str_contains($src, 'map') ? 'Embedded map'
                    : (str_contains($host, 'youtube') || str_contains($host, 'vimeo') ? 'Embedded video' : 'Embedded content');
                $out[] = Make::issue($doc, 'a11y-iframe-title', '<iframe src="'.HtmlEdit::truncate($src, 60).'"> has no title.',
                    start: $iframe->start, end: $iframe->startEnd, fix: HtmlEdit::setAttr($iframe->raw, 'title', $guess),
                    note: 'Generic title — make it specific to the content.');
            }
        }

        foreach ($tree->nodes as $field) {
            if (! in_array($field->tag, ['input', 'select', 'textarea'], true)) {
                continue;
            }
            if ($field->tag === 'input' && in_array(strtolower($field->get('type', 'text')), self::LABEL_EXEMPT_INPUTS, true)) {
                continue;
            }
            if (trim($field->get('aria-label')) !== '' || $field->get('aria-labelledby') !== '' || $field->get('title') !== ''
                || ($field->get('id') !== '' && isset($labelFor[$field->get('id')])) || $field->inside(['label'])) {
                continue;
            }
            $hint = $field->get('placeholder');
            if ($hint === '') {
                $hint = HtmlEdit::humanize($field->get('name') !== '' ? $field->get('name') : $field->get('id'));
            }
            $out[] = Make::issue($doc, 'a11y-input-no-label', "<{$field->tag} name=\"{$field->get('name')}\"> has no associated label.",
                start: $field->start, end: $field->startEnd,
                fix: $hint !== '' ? HtmlEdit::setAttr($field->raw, 'aria-label', $hint) : null,
                note: $hint !== '' ? 'Adds an aria-label; a visible <label> is better for all users.' : '');
        }

        foreach ($tree->findAll('button') as $button) {
            if (! self::accessibleName($button)) {
                $out[] = Make::issue($doc, 'a11y-button-no-name', 'A <button> has no text or aria-label.',
                    start: $button->start, end: $button->startEnd);
            }
        }

        foreach ($tree->findAll('a') as $a) {
            if ($a->has('href') && ! self::accessibleName($a)) {
                $label = self::hrefLabel($a->get('href'));
                $out[] = Make::issue($doc, 'a11y-link-no-text', 'Link to “'.HtmlEdit::truncate($a->get('href'), 60).'” has no accessible text.',
                    start: $a->start, end: $a->startEnd,
                    fix: $label !== '' ? HtmlEdit::setAttr($a->raw, 'aria-label', $label) : null,
                    note: $label !== '' ? 'Label derived from the URL — check it describes the destination.' : '');
            }
        }

        foreach ($tree->nodes as $node) {
            $tab = trim($node->get('tabindex'));
            if (preg_match('/^\+?\d+$/', $tab) && (int) $tab > 0) {
                $out[] = Make::issue($doc, 'a11y-positive-tabindex', "<{$node->tag}> has tabindex=\"{$tab}\".",
                    start: $node->start, end: $node->startEnd, fix: HtmlEdit::setAttr($node->raw, 'tabindex', '0'));
            }
        }

        return $out;
    }

    /** @return Issue[] */
    private static function linksAndResources(Document $doc, Tree $tree): array
    {
        $out = [];
        $httpsPage = $doc->url !== null && str_starts_with($doc->url, 'https://');
        foreach ($tree->findAll('a') as $a) {
            $href = $a->has('href') ? $a->get('href') : null;
            $text = preg_replace('/^[ .!:→»>]+|[ .!:→»>]+$/u', '', mb_strtolower($a->text()));
            if ($href !== null && in_array(trim($href), ['', '#'], true) && $a->get('role') === '' && ! $a->has('onclick')) {
                $label = HtmlEdit::truncate($a->text(), 40);
                $out[] = Make::issue($doc, 'html-empty-link', 'Link “'.($label !== '' ? $label : '(no text)')."” has href=\"{$href}\".",
                    start: $a->start, end: $a->startEnd);
            }
            if (strtolower($a->get('target')) === '_blank') {
                $rel = preg_split('/\s+/', strtolower($a->get('rel')), -1, PREG_SPLIT_NO_EMPTY);
                if (! in_array('noopener', $rel, true) && ! in_array('noreferrer', $rel, true)) {
                    $newRel = implode(' ', array_unique([...preg_split('/\s+/', $a->get('rel'), -1, PREG_SPLIT_NO_EMPTY), 'noopener', 'noreferrer']));
                    $out[] = Make::issue($doc, 'sec-target-blank', 'Link to “'.HtmlEdit::truncate($href ?? '', 60).'” opens a new tab without rel="noopener".',
                        start: $a->start, end: $a->startEnd, fix: HtmlEdit::setAttr($a->raw, 'rel', $newRel));
                }
            }
            if (in_array($text, self::GENERIC_LINK_TEXT, true)) {
                $region = $a->elementRegion();
                $out[] = Make::issue($doc, 'a11y-generic-link-text',
                    'Link text “'.$a->text()."” doesn't describe the destination (".HtmlEdit::truncate($href ?? '', 50).').',
                    start: $region[0] ?? -1, end: $region[1] ?? -1, line: $a->line);
            }
        }

        $resourceAttrs = ['script' => 'src', 'img' => 'src', 'iframe' => 'src', 'source' => 'src', 'video' => 'src',
            'audio' => 'src', 'embed' => 'src', 'link' => 'href'];
        foreach ($tree->nodes as $node) {
            $attr = $resourceAttrs[$node->tag] ?? null;
            if (! $attr) {
                continue;
            }
            if ($node->tag === 'link' && ! array_intersect(['stylesheet', 'icon', 'preload', 'manifest'], preg_split('/\s+/', strtolower($node->get('rel'))))) {
                continue;
            }
            $url = $node->get($attr);
            if (str_starts_with(strtolower($url), 'http://') && ! preg_match('#^http://(localhost|127\.0\.0\.1|0\.0\.0\.0)#i', $url)) {
                $severe = in_array($node->tag, ['script', 'iframe', 'link'], true);
                $out[] = Make::issue($doc, 'sec-insecure-resource',
                    "<{$node->tag}> loads “".HtmlEdit::truncate($url, 70).'” over plain HTTP'.($httpsPage ? ' on an HTTPS page (mixed content).' : '.'),
                    start: $node->start, end: $node->startEnd, fix: HtmlEdit::setAttr($node->raw, $attr, 'https://'.substr($url, 7)),
                    severity: ($severe && $httpsPage) ? 'high' : null, note: 'Confirm the resource is available over HTTPS.');
            }
        }

        return $out;
    }

    /** @return Issue[] */
    private static function content(Document $doc, Tree $tree): array
    {
        $out = [];
        $visible = array_values(array_filter($tree->segments, fn ($s) => $s->visible()));
        if ($tree->isPage()) {
            $all = html_entity_decode(implode(' ', array_map(fn ($s) => $s->data, $visible)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $words = preg_match_all('/\b\w+\b/u', $all);
            if ($words < 250) {
                $body = $tree->find('body');
                $out[] = Make::issue($doc, 'content-thin', "The page contains only about {$words} words of visible text.", line: $body?->line ?? 1);
            }
        }

        $seenPlaceholder = false;
        foreach ($visible as $seg) {
            $data = $seg->data;
            if (! $seenPlaceholder && preg_match('/lorem ipsum|dolor sit amet/i', $data)) {
                $lead = strlen($data) - strlen(ltrim($data));
                $body = trim($data);
                $out[] = Make::issue($doc, 'content-placeholder', 'Placeholder text: “'.HtmlEdit::truncate($body, 70).'”.',
                    start: $seg->offset + $lead, end: $seg->offset + $lead + strlen($body));
                $seenPlaceholder = true;
            }
            preg_match_all('/\b([A-Za-z]{2,})(\s+)\1\b/i', $data, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $m) {
                if (in_array(strtolower($m[1][0]), ['that', 'had', 'is'], true)) {
                    continue;
                }
                $out[] = Make::issue($doc, 'content-repeated-word', '“'.$m[0][0].'” — word repeated.',
                    start: $seg->offset + $m[0][1], end: $seg->offset + $m[0][1] + strlen($m[0][0]), fix: $m[1][0]);
            }
            preg_match_all('/[^.!?]+[.!?]?/u', $data, $sentences, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($sentences as $m) {
                $sentence = $m[0][0];
                $count = count(preg_split('/\s+/u', trim($sentence), -1, PREG_SPLIT_NO_EMPTY));
                if ($count > 40) {
                    $lead = strlen($sentence) - strlen(ltrim($sentence));
                    $out[] = Make::issue($doc, 'content-long-sentence',
                        "A {$count}-word sentence: “".HtmlEdit::truncate(trim($sentence), 70).'”.',
                        start: $seg->offset + $m[0][1] + $lead, end: $seg->offset + $m[0][1] + strlen($sentence));
                }
            }
        }

        return $out;
    }
}
