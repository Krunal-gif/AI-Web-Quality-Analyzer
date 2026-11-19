<?php

namespace App\Analyzer\Html;

final class TextSeg
{
    public function __construct(public int $offset, public string $data, public Node $parent) {}

    public function visible(): bool
    {
        return ! in_array($this->parent->tag, HtmlParser::NON_TEXT, true) && ! $this->parent->inside(HtmlParser::NON_TEXT);
    }
}
