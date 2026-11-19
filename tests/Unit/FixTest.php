<?php

namespace Tests\Unit;

use App\Analyzer\Diff;
use App\Analyzer\Document;
use App\Analyzer\Ollama;
use App\Analyzer\Rules\RuleEngine;

class FixTest extends AnalyzerTestCase
{
    public function test_merge_two_attribute_fixes_on_same_tag(): void
    {
        $base = '<a href="/x" target="_blank">';
        $merged = Diff::mergeEdits($base, ['<a href="/x" target="_blank" aria-label="X">', '<a href="/x" target="_blank" rel="noopener">']);
        $this->assertSame('<a href="/x" target="_blank" aria-label="X" rel="noopener">', $merged);
    }

    public function test_overlapping_edits_conflict(): void
    {
        $this->assertNull(Diff::mergeEdits('<h1>A</h1>', ['<h2>A</h2>', '<h3>A</h3>']));
    }

    public function test_unified_diff_format(): void
    {
        $patch = Diff::unified('a.txt', "one\ntwo\nthree\n", "one\n2\nthree\n");
        $this->assertSame("--- a/a.txt\n+++ b/a.txt\n@@ -1,3 +1,3 @@\n one\n-two\n+2\n three\n", $patch);
    }

    public function test_validate_fix_guards(): void
    {
        $issue = RuleEngine::analyze([new Document('p.html', 'html', "<div><img src='hero.png'></div>")])[0];
        $this->assertNull(Ollama::validateFix($issue, '')[0]);
        $this->assertNull(Ollama::validateFix($issue, $issue->original)[0]);
        $this->assertNull(Ollama::validateFix($issue, str_repeat('x', 5000))[0]);
        $this->assertNull(Ollama::validateFix($issue, 'Hero image')[0]);
        [$fixed, $warning] = Ollama::validateFix($issue, "```html\n<img src='hero.png' alt='Hero'>\n```");
        $this->assertSame("<img src='hero.png' alt='Hero'>", $fixed);
        $this->assertSame('', $warning);
    }

    public function test_duplicate_id_renames_the_later_one_to_a_free_id(): void
    {
        $html = '<p id="a">1</p><p id="a">2</p><p id="a-2">3</p>';
        $issue = collect(RuleEngine::analyze([new Document('p.html', 'html', $html)]))->firstWhere('rule', 'html-duplicate-id');
        $this->assertSame('<p id="a">', $issue->original);
        $this->assertSame('<p id="a-3">', $issue->ruleFix);
    }

    public function test_ai_insert_is_reindented_to_match_file(): void
    {
        $html = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n    <meta charset=\"utf-8\">\n    <title>Plumbers in Manchester | Acme</title>\n</head>\n<body><h1>x</h1></body></html>";
        $issue = collect(RuleEngine::analyze([new Document('p.html', 'html', $html)]))->firstWhere('rule', 'seo-meta-description-missing');
        [$fixed] = Ollama::validateFix($issue, '<meta name="description" content="Fast local plumbers.">');
        $this->assertSame("    <meta name=\"description\" content=\"Fast local plumbers.\">\n", $fixed);
    }
}
