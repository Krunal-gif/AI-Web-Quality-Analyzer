<?php

namespace Tests\Unit;

use App\Analyzer\Html\HtmlEdit;
use App\Analyzer\Html\HtmlParser;
use PHPUnit\Framework\TestCase;

class HtmlParserTest extends TestCase
{
    public function test_offsets_point_at_exact_source(): void
    {
        $src = "<html>\n<body>\n  <p>Hi &amp; bye</p>\r\n  <img src='a.png'>\n  <p>Café ünïcode</p>\n</body></html>";
        $tree = HtmlParser::parse($src);
        foreach ($tree->nodes as $node) {
            $this->assertSame($node->raw, substr($src, $node->start, $node->startEnd - $node->start));
        }
        $p = $tree->find('p');
        $this->assertSame('<p>Hi &amp; bye</p>', substr($src, $p->start, $p->end - $p->start));
        $this->assertSame('Hi & bye', $p->text());
        $this->assertSame('Café ünïcode', $tree->findAll('p')[1]->text());
    }

    public function test_implicit_close_and_stray_tags(): void
    {
        $tree = HtmlParser::parse('<div><span>text</div></i>');
        $this->assertTrue($tree->find('span')->implicitClose);
        $this->assertSame('i', $tree->stray[0][2]);
    }

    public function test_script_content_is_raw_text(): void
    {
        $tree = HtmlParser::parse('<script>if (a < b) { x = "</div>"; }</script><p>after</p>');
        $this->assertCount(2, $tree->nodes);
        $this->assertTrue($tree->find('script')->closedProperly());
    }

    public function test_malformed_tag_does_not_hang(): void
    {
        $src = '<div '.str_repeat('a="b" c ', 5000).'"unterminated';
        $start = microtime(true);
        HtmlParser::parse($src);
        $this->assertLessThan(2, microtime(true) - $start);
    }

    public function test_set_attr_adds_and_replaces(): void
    {
        $this->assertSame('<img src="a.png" alt="A">', HtmlEdit::setAttr('<img src="a.png">', 'alt', 'A'));
        $this->assertSame('<img src="a.png" alt="A" />', HtmlEdit::setAttr('<img src="a.png" />', 'alt', 'A'));
        $this->assertSame('<p tabindex="0">', HtmlEdit::setAttr('<p tabindex="3">', 'tabindex', '0'));
        $this->assertSame('<a href=x title="say &quot;hi&quot;">', HtmlEdit::setAttr('<a href=x>', 'title', 'say "hi"'));
    }

    public function test_humanize_and_truncate(): void
    {
        $this->assertSame('Hero banner', HtmlEdit::humanize('images/hero-banner@2x.jpg'));
        $this->assertSame('', HtmlEdit::humanize('IMG_2024.jpg'));
        $this->assertSame('Emergency plumbers…', HtmlEdit::truncate('Emergency plumbers in Manchester', 20));
    }
}
