<?php

namespace App\Analyzer\Html;

use App\Analyzer\LineIndex;

/**
 * Position-aware, error-tolerant HTML tokenizer.
 *
 * DOMDocument discards source positions, but region-based fixes need the exact
 * byte offsets of every start tag, end tag and text run — so this builds a
 * lightweight element tree that remembers them.
 */
final class HtmlParser
{
    public const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param',
        'source', 'track', 'wbr'];

    public const OPTIONAL_CLOSE = ['p', 'li', 'dt', 'dd', 'option', 'optgroup', 'tr', 'td', 'th', 'thead', 'tbody',
        'tfoot', 'colgroup', 'caption', 'rb', 'rt', 'rp', 'html', 'head', 'body'];

    public const NON_TEXT = ['script', 'style', 'noscript', 'template', 'head'];

    private const RAW_TEXT = ['script', 'style'];

    // Possessive/atomic groups keep this linear on malformed input.
    private const START_TAG = '/\G<([a-zA-Z][^\s\/>\x00]*+)((?>[\s\/]*+(?>[^\s\/>=][^\s\/=>]*+)(?>\s*+=++\s*+(?>\'[^\']*+\'|"[^"]*+"|(?![\'"])[^>\s]*+))?)*+)[\s\/]*+>/';

    private const ATTR = '/([^\s\/>=][^\s\/=>]*)(?:\s*=+\s*(\'[^\']*\'|"[^"]*"|(?![\'"])[^>\s]*))?/';

    public static function parse(string $src, ?LineIndex $index = null): Tree
    {
        $index ??= new LineIndex($src);
        $root = new Node('#root', [], '', 0, 1, null);
        $stack = [$root];
        $nodes = [];
        $segments = [];
        $stray = [];
        $doctype = null;
        $n = strlen($src);
        $pos = 0;

        $addText = function (int $offset, string $data) use (&$stack, &$segments) {
            if ($data === '') {
                return;
            }
            $parent = end($stack);
            $parent->children[] = $data;
            $segments[] = new TextSeg($offset, $data, $parent);
        };

        while ($pos < $n) {
            $lt = strpos($src, '<', $pos);
            if ($lt === false) {
                $addText($pos, substr($src, $pos));
                break;
            }
            if ($lt > $pos) {
                $addText($pos, substr($src, $pos, $lt - $pos));
            }
            $pos = $lt;

            if (substr_compare($src, '<!--', $pos, 4) === 0) {
                $close = strpos($src, '-->', $pos + 4);
                $pos = $close === false ? $n : $close + 3;

                continue;
            }
            if (preg_match('/\G<!([^>]*)>/', $src, $m, 0, $pos)) {
                if (stripos(ltrim($m[1]), 'doctype') === 0) {
                    $doctype = $m[1];
                }
                $pos += strlen($m[0]);

                continue;
            }
            if (preg_match('/\G<\?[^>]*>/', $src, $m, 0, $pos)) {
                $pos += strlen($m[0]);

                continue;
            }
            if (preg_match('/\G<\/([a-zA-Z][^\s\/>]*)[^>]*>/', $src, $m, 0, $pos)) {
                self::closeTag(strtolower($m[1]), $pos, $pos + strlen($m[0]), $stack, $stray);
                $pos += strlen($m[0]);

                continue;
            }
            if (preg_match(self::START_TAG, $src, $m, 0, $pos)) {
                $raw = $m[0];
                $tag = strtolower($m[1]);
                $node = new Node($tag, self::attributes($m[2]), $raw, $pos, $index->lineOf($pos), end($stack));
                end($stack)->children[] = $node;
                $nodes[] = $node;
                $pos += strlen($raw);
                if (str_ends_with($raw, '/>') || in_array($tag, self::VOID, true)) {
                    $node->end = $node->startEnd;

                    continue;
                }
                $stack[] = $node;
                if (in_array($tag, self::RAW_TEXT, true)) {
                    $close = preg_match('/<\/'.$tag.'[\s>\/]/i', $src, $cm, PREG_OFFSET_CAPTURE, $pos) ? $cm[0][1] : $n;
                    $addText($pos, substr($src, $pos, $close - $pos));
                    $pos = $close;
                }

                continue;
            }
            $addText($pos, '<');   // a literal '<' that doesn't open a tag
            $pos++;
        }

        foreach (array_slice($stack, 1) as $node) {
            $node->end = $n;
            $node->implicitClose = true;
        }

        return new Tree($root, $nodes, $segments, $doctype, $stray);
    }

    private static function closeTag(string $tag, int $start, int $end, array &$stack, array &$stray): void
    {
        for ($i = count($stack) - 1; $i > 0; $i--) {
            if ($stack[$i]->tag === $tag) {
                foreach (array_slice($stack, $i + 1) as $inner) {
                    $inner->end = $start;
                    $inner->implicitClose = true;
                }
                $stack[$i]->end = $end;
                $stack[$i]->endTagStart = $start;
                array_splice($stack, $i);

                return;
            }
        }
        if (! in_array($tag, self::VOID, true)) {
            $stray[] = [$start, $end, $tag];
        }
    }

    /** @return array<int, array{0:string,1:?string}> */
    private static function attributes(string $source): array
    {
        $out = [];
        preg_match_all(self::ATTR, $source, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $value = null;
            if (isset($m[2])) {
                $value = $m[2];
                if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                    $value = substr($value, 1, -1);
                }
                $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            $out[] = [strtolower($m[1]), $value];
        }

        return $out;
    }
}
