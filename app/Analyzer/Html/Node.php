<?php

namespace App\Analyzer\Html;

final class Node
{
    /** @var array<string,string> lower-cased attribute name => decoded value ('' for boolean attributes) */
    public array $attrs = [];

    public int $startEnd;              // offset just after the start tag's '>'

    public ?int $end = null;           // offset just after the end tag

    public ?int $endTagStart = null;   // offset of '</'

    /** @var array<int, Node|string> */
    public array $children = [];

    public bool $implicitClose = false;

    /**
     * @param  array<int, array{0:string,1:?string}>  $attrList
     */
    public function __construct(
        public string $tag,
        public array $attrList,
        public string $raw,            // exact start-tag source
        public int $start,             // offset of '<'
        public int $line,
        public ?Node $parent,
    ) {
        $this->startEnd = $start + strlen($raw);
        foreach ($attrList as [$name, $value]) {
            $this->attrs[$name] ??= $value ?? '';
        }
    }

    public function get(string $name, string $default = ''): string
    {
        return $this->attrs[$name] ?? $default;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->attrs);
    }

    /** @return \Generator<Node> */
    public function iter(): \Generator
    {
        foreach ($this->children as $child) {
            if ($child instanceof Node) {
                yield $child;
                yield from $child->iter();
            }
        }
    }

    public function inside(array $tags): bool
    {
        for ($node = $this->parent; $node !== null; $node = $node->parent) {
            if (in_array($node->tag, $tags, true)) {
                return true;
            }
        }

        return false;
    }

    public function text(): string
    {
        $parts = [];
        self::collectText($this, $parts);
        $text = html_entity_decode(implode('', $parts), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private static function collectText(Node $node, array &$parts): void
    {
        foreach ($node->children as $child) {
            if (is_string($child)) {
                $parts[] = $child;
            } elseif (! in_array($child->tag, ['script', 'style', 'noscript', 'template'], true)) {
                self::collectText($child, $parts);
            }
        }
    }

    public function closedProperly(): bool
    {
        return $this->end !== null && $this->endTagStart !== null && ! $this->implicitClose;
    }

    /** @return array{0:int,1:int}|null whole element, or null if its end is uncertain */
    public function elementRegion(): ?array
    {
        if (in_array($this->tag, HtmlParser::VOID, true) || str_ends_with($this->raw, '/>')) {
            return [$this->start, $this->startEnd];
        }

        return $this->closedProperly() ? [$this->start, $this->end] : null;
    }
}
