<?php

namespace App\Analyzer\Html;

final class Tree
{
    /**
     * @param  Node[]  $nodes  every element in document order
     * @param  TextSeg[]  $segments
     * @param  array<int, array{0:int,1:int,2:string}>  $stray  end tags with no open element
     */
    public function __construct(
        public Node $root,
        public array $nodes,
        public array $segments,
        public ?string $doctype,
        public array $stray,
    ) {}

    /** @return Node[] */
    public function findAll(string $tag): array
    {
        return array_values(array_filter($this->nodes, fn (Node $n) => $n->tag === $tag));
    }

    public function find(string $tag): ?Node
    {
        foreach ($this->nodes as $node) {
            if ($node->tag === $tag) {
                return $node;
            }
        }

        return null;
    }

    /** True for full documents, false for fragments/partials. */
    public function isPage(): bool
    {
        if ($this->doctype) {
            return true;
        }
        foreach ($this->nodes as $node) {
            if (in_array($node->tag, ['html', 'head', 'body'], true)) {
                return true;
            }
        }

        return false;
    }
}
