<?php

namespace Tests\Unit;

use App\Analyzer\Document;
use App\Analyzer\Rules\RuleEngine;

class RulesTest extends AnalyzerTestCase
{
    public function test_page_level_rules(): void
    {
        $found = $this->rulesFor("<html><head></head><body><img src='x.jpg'></body></html>");
        foreach (['html-doctype-missing', 'a11y-lang-missing', 'seo-viewport-missing', 'seo-title-missing',
            'seo-h1-missing', 'a11y-img-alt-missing', 'html-charset-missing'] as $rule) {
            $this->assertContains($rule, $found);
        }
    }

    public function test_fragments_skip_page_rules(): void
    {
        $found = $this->rulesFor("<div><img src='x.jpg' alt=''></div>", 'partial.html');
        $this->assertNotContains('seo-title-missing', $found);
        $this->assertNotContains('html-doctype-missing', $found);
    }

    public function test_code_rules(): void
    {
        $js = "var a = 1;\nif (a == '1') { console.log(a); }\nconst b = x == null;\n// TODO later\n";
        $found = $this->rulesFor($js, 'a.js', 'js');
        foreach (['code-var', 'code-loose-equality', 'code-console-log', 'code-todo'] as $rule) {
            $this->assertContains($rule, $found);
        }
        $loose = array_filter(RuleEngine::analyze([new Document('a.js', 'js', $js)]), fn ($i) => $i->rule === 'code-loose-equality');
        $this->assertCount(1, $loose, '`== null` is an accepted idiom and must not be flagged');
        $this->assertContains('code-todo', $this->rulesFor("<?php\n\$a = 1; // TODO later\n", 'a.php', 'php'));
        $this->assertNotContains('code-todo', $this->rulesFor("<?php\n\$s = \"x // TODO not a comment\";\n", 'b.php', 'php'));
        $this->assertContains('sec-eval', $this->rulesFor("<?php\neval(\$code);\n", 'c.php', 'php'));
        $this->assertNotContains('sec-eval', $this->rulesFor("<?php\n\$t = 'Use of eval()';\n", 'd.php', 'php'));
        $this->assertContains('code-syntax-error', $this->rulesFor('{"a": 1,}', 'c.json', 'json'));
        $this->assertContains('css-empty-rule', $this->rulesFor(".a {}\n.b { color: red; }", 's.css', 'css'));
    }

    public function test_json_error_position(): void
    {
        $issue = RuleEngine::analyze([new Document('c.json', 'json', "{\n  \"a\": [1, 2,]\n}")])[0];
        $this->assertSame(2, $issue->line);
        $this->assertSame('Invalid JSON: Expecting value (column 14).', $issue->message);
    }

    public function test_strings_and_comments_are_not_code(): void
    {
        $found = $this->rulesFor('const s = "a == b; var x; console.log(1)";'."\n", 'a.js', 'js');
        $this->assertEmpty(array_intersect(['code-var', 'code-loose-equality', 'code-console-log'], $found));
    }

    public function test_secret_detection_ignores_placeholders(): void
    {
        $fake = 'sk_'.'live_51HxQabcdefghijklmnop';   // split so the scanner doesn't flag this test file itself
        $this->assertContains('sec-hardcoded-secret', $this->rulesFor("api_key = \"{$fake}\"", 'a.py', 'python'));
        $this->assertNotContains('sec-hardcoded-secret', $this->rulesFor('api_key = "your_api_key_here"', 'a.py', 'python'));
    }

    public function test_noisy_rules_are_capped(): void
    {
        $js = str_repeat("var x = 1;\n", 40);
        $vars = array_filter(RuleEngine::analyze([new Document('a.js', 'js', $js)]), fn ($i) => $i->rule === 'code-var');
        $this->assertCount(25, $vars);
        $this->assertStringContainsString('+15 more', end($vars)->message);
    }
}
