<?php

namespace Tests\Feature;

use App\Analyzer\Document;
use App\Analyzer\Rules\CodeRules;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteCodeTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = storage_path('framework/testing/webqa-code-'.uniqid());
        config(['webqa.storage' => $this->tmp]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_site_files_are_analysed_and_broken_ones_reported(): void
    {
        $page = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Shop home page title</title>'
            .'<link rel="stylesheet" href="/css/site.css"><link rel="stylesheet" href="/css/gone.css">'
            .'<script src="/js/app.js"></script><script src="https://cdn.other.test/lib.js"></script></head>'
            .'<body><h1>Shop</h1><img src="/img/logo.png" alt="Logo"><img src="/img/missing.png" alt="Missing"></body></html>';
        Http::fake([
            'shop.test/css/site.css' => Http::response(".a { color: red; }\n.b { color: blue; }\n}\n", 200, ['Content-Type' => 'text/css']),
            'shop.test/css/gone.css' => Http::response('Not found', 404),
            'shop.test/js/app.js' => Http::response("const total = 1;\ndebugger;\nconsole.log(total);\n", 200, ['Content-Type' => 'application/javascript']),
            'cdn.other.test/lib.js' => Http::response('window.lib = 1;', 200),
            'shop.test/img/logo.png' => Http::response('', 200),
            'shop.test/img/missing.png' => Http::response('', 404),
            'shop.test/*' => Http::response($page, 200, ['Content-Type' => 'text/html']),
        ]);

        $data = $this->postJson('/api/analyses', ['target' => 'https://shop.test/'])->assertCreated()->json();
        $files = array_column($data['target']['files'], 'kind', 'rel');
        $this->assertEqualsCanonicalizing(['shop.test/index.html' => 'html', 'shop.test/js/app.js' => 'js', 'shop.test/css/site.css' => 'css'], $files);   // not the CDN's file

        $by = fn (string $rule) => collect($data['issues'])->where('rule', $rule)->values();
        $broken = $by('code-broken-resource');
        $this->assertSame(['Image “/img/missing.png” returned HTTP 404.', 'Stylesheet “/css/gone.css” returned HTTP 404.'],
            $broken->pluck('message')->sort()->values()->all());
        $this->assertSame(['high', 'medium'], $broken->pluck('severity')->sort()->values()->all());
        $this->assertStringStartsWith('<link rel="stylesheet" href="/css/gone.css">', $broken->firstWhere('severity', 'high')['original']);

        $this->assertSame('shop.test/css/site.css', $by('code-syntax-error')->first()['file']);
        $this->assertSame(3, $by('code-syntax-error')->first()['line']);
        $this->assertSame(['shop.test/js/app.js'], $by('code-debugger')->pluck('file')->all());
        $this->assertSame(['shop.test/js/app.js'], $by('code-console-log')->pluck('file')->all());
    }

    public function test_fetched_javascript_gets_a_real_syntax_check(): void
    {
        $bad = new Document('x.test/app.js', 'js', "function go() {\n  return 1;\n", null, 'https://x.test/app.js');
        $module = new Document('x.test/mod.js', 'js', "import a from './a.js';\nexport const b = a;\n", null, 'https://x.test/mod.js');

        $this->assertNotEmpty(collect(CodeRules::check($bad))->where('rule', 'code-syntax-error'));
        $this->assertEmpty(collect(CodeRules::check($module))->where('rule', 'code-syntax-error'));
        $this->assertSame([], glob(storage_path('app/webqa/tmp/*')) ?: []);   // temporary copies are removed
    }

    public function test_minified_code_reports_only_serious_problems(): void
    {
        $min = str_repeat('var a=1;if(a==2){console.log(a)}', 40)."eval('x');debugger;";
        $rules = collect(CodeRules::check(new Document('x.test/app.min.js', 'js', $min, null, 'https://x.test/app.min.js')))->pluck('rule')->unique()->sort()->values()->all();

        $this->assertSame(['code-debugger', 'sec-eval'], $rules);
    }

    public function test_tailwind_escapes_are_not_css_errors(): void
    {
        $css = ".content-\\[\\'\\'\\]{--tw-content:''}.w-1\\/2{width:50%}.hover\\:bg-red:hover{color:red}\n.x{}\n";
        $syntax = collect(CodeRules::check(new Document('x.test/app.css', 'css', $css, null, 'https://x.test/app.css')))->where('rule', 'code-syntax-error');

        $this->assertCount(0, $syntax);
    }
}
