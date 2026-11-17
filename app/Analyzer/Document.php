<?php

namespace App\Analyzer;

use App\Analyzer\Html\HtmlParser;
use App\Analyzer\Html\Tree;

/**
 * One analysed file or fetched page. Text is always held as UTF-8; `encoding`
 * records the original encoding so fixes are written back unchanged.
 */
final class Document
{
    private ?LineIndex $lineIndex = null;

    private ?Tree $tree = null;

    public function __construct(
        public string $rel,            // display path: relative to project root, or derived from URL
        public string $kind,           // html | css | js | json | python | php
        public string $text,
        public ?string $path = null,   // file on disk (null for fetched pages)
        public ?string $url = null,    // source URL for fetched pages
        public string $encoding = 'UTF-8',
    ) {}

    public function lines(): LineIndex
    {
        return $this->lineIndex ??= new LineIndex($this->text);
    }

    public function newline(): string
    {
        return str_contains(substr($this->text, 0, 5000), "\r\n") ? "\r\n" : "\n";
    }

    public function tree(): Tree
    {
        return $this->tree ??= HtmlParser::parse($this->text, $this->lines());
    }

    public function withText(string $text): self
    {
        return new self($this->rel, $this->kind, $text, $this->path, $this->url, $this->encoding);
    }

    public function toStorage(): array
    {
        return ['rel' => $this->rel, 'kind' => $this->kind, 'text' => $this->text, 'path' => $this->path,
            'url' => $this->url, 'encoding' => $this->encoding];
    }

    public static function fromStorage(array $d): self
    {
        return new self($d['rel'], $d['kind'], $d['text'], $d['path'] ?? null, $d['url'] ?? null, $d['encoding'] ?? 'UTF-8');
    }

    /** Text in the file's original encoding, ready to be written to disk. */
    public function encode(string $text): string
    {
        return strtoupper($this->encoding) === 'UTF-8' ? $text : mb_convert_encoding($text, $this->encoding, 'UTF-8');
    }
}
