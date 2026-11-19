<?php

namespace Tests\Feature;

use App\Analyzer\Analysis;
use App\Analyzer\Fixer;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    private string $tmp;

    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = storage_path('framework/testing/webqa-'.uniqid());
        $this->site = "{$this->tmp}/site";
        File::copyDirectory(base_path('samples/demo-site'), $this->site);
        config(['webqa.storage' => "{$this->tmp}/analyses"]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function approveRuleFixes(Analysis $a): void
    {
        foreach ($a->issues as $item) {
            if ($item->ruleFix !== null && ! $item->written) {
                $a->decide($item->id, 'approved');
            }
        }
    }

    public function test_patch_mode_leaves_originals_and_verifies(): void
    {
        $original = file_get_contents("{$this->site}/index.html");
        $a = Analysis::create($this->site);
        $this->approveRuleFixes($a);
        $after = Fixer::apply($a, 'patch');

        $this->assertGreaterThan(20, $after['applied']);
        $this->assertSame(0, $after['conflicts']);
        $this->assertSame($after['applied'], $after['resolved']);
        $this->assertGreaterThan($a->scoresBefore['overall'], $after['scores']['overall']);
        $this->assertSame($original, file_get_contents("{$this->site}/index.html"));
        $this->assertFileExists("{$after['fixed_dir']}/index.html");
        $this->assertGreaterThan(0, filesize($after['patch']));
        $this->assertFileExists($a->dir().'/report.html');
        $this->assertSame([], $after['new_issues']);
    }

    public function test_rejected_fixes_are_not_applied(): void
    {
        $a = Analysis::create($this->site);
        $this->approveRuleFixes($a);
        $viewport = collect($a->issues)->firstWhere('rule', 'seo-viewport-missing');
        $a->decide($viewport->id, 'rejected');
        Fixer::apply($a, 'inplace');

        $this->assertStringNotContainsString('name="viewport"', file_get_contents("{$this->site}/index.html"));
        $this->assertSame('rejected', $viewport->status);
    }

    public function test_inplace_mode_backs_up_and_locks(): void
    {
        $a = Analysis::create($this->site);
        $this->approveRuleFixes($a);
        $after = Fixer::apply($a, 'inplace');

        $this->assertStringStartsWith('<!DOCTYPE html>', file_get_contents("{$this->site}/index.html"));
        $this->assertStringNotContainsString('<!DOCTYPE', file_get_contents("{$after['backup_dir']}/index.html"));
        $this->assertTrue($a->locked);
        $this->expectException(\DomainException::class);
        Fixer::apply($a, 'inplace');
    }

    public function test_file_changed_on_disk_is_not_overwritten(): void
    {
        $a = Analysis::create($this->site);
        $this->approveRuleFixes($a);
        file_put_contents("{$this->site}/about.html", "<!-- edited meanwhile -->\n", FILE_APPEND);
        Fixer::apply($a, 'inplace');

        foreach ($a->issues as $i) {
            if ($i->file === 'about.html' && $i->status !== 'pending') {
                $this->assertSame('failed', $i->status);
            }
        }
        $this->assertStringEndsWith("<!-- edited meanwhile -->\n", file_get_contents("{$this->site}/about.html"));
    }

    public function test_user_edit_is_what_gets_applied(): void
    {
        $a = Analysis::create($this->site);
        $img = collect($a->issues)->first(fn ($i) => $i->rule === 'a11y-img-alt-missing' && str_contains($i->message, 'boiler'));
        $edited = str_replace('>', ' alt="Engineer repairing a boiler">', $img->original);
        $a->decide($img->id, 'approved', $edited);
        Fixer::apply($a, 'inplace');

        $this->assertStringContainsString('alt="Engineer repairing a boiler"', file_get_contents("{$this->site}/index.html"));
        $this->assertTrue($img->resolved);
    }

    public function test_write_one_fix_into_the_file(): void
    {
        $original = file_get_contents("{$this->site}/index.html");
        $a = Analysis::create($this->site);
        $imgs = collect($a->issues)->filter(fn ($i) => $i->rule === 'a11y-img-alt-missing' && $i->file === 'index.html')->sortBy('start')->values();
        [$first, $later] = [$imgs[0], $imgs[1]];
        $laterOriginal = $later->original;
        $laterLine = $later->line;

        Fixer::writeOne($a, $first->id);
        $disk = file_get_contents("{$this->site}/index.html");

        $this->assertStringContainsString($first->proposed, $disk);
        $this->assertSame($original, file_get_contents($a->dir().'/backup/index.html'));
        $this->assertTrue($first->written);
        $this->assertSame('applied', $first->status);
        $this->assertTrue($first->resolved);
        $this->assertFalse($a->locked);
        // the next issue in the same file was moved to its new position
        $this->assertSame($laterOriginal, substr($disk, $later->start, $later->end - $later->start));
        $this->assertSame($laterLine, $later->line);

        // a second fix in the same file can be written right after
        Fixer::writeOne($a, $later->id);
        $disk = file_get_contents("{$this->site}/index.html");
        $this->assertStringContainsString($first->proposed, $disk);
        $this->assertStringContainsString($later->proposed, $disk);
        $this->assertSame($original, file_get_contents($a->dir().'/backup/index.html'));

        // the bulk Apply never applies a written fix a second time
        $this->approveRuleFixes($a);
        $after = Fixer::apply($a, 'patch');
        $this->assertSame(0, $after['failed']);
        $this->assertSame(0, $after['conflicts']);
        $this->assertSame('applied', $first->status);
        $this->assertSame(1, substr_count(file_get_contents("{$after['fixed_dir']}/index.html"), $first->proposed));
    }

    public function test_written_fix_survives_reload_and_cannot_be_changed(): void
    {
        $a = Analysis::create($this->site);
        $viewport = collect($a->issues)->firstWhere('rule', 'seo-viewport-missing');
        Analysis::mutate($a->id, fn (Analysis $x) => Fixer::writeOne($x, $viewport->id));
        $reloaded = Analysis::load($a->id);

        $this->assertSame(file_get_contents("{$this->site}/index.html"), $reloaded->doc('index.html')->text);
        $this->assertTrue($reloaded->issue($viewport->id)->written);
        $this->expectException(\DomainException::class);
        $reloaded->decide($viewport->id, 'rejected');
    }

    public function test_write_one_refuses_a_file_changed_outside_the_tool(): void
    {
        $a = Analysis::create($this->site);
        $viewport = collect($a->issues)->firstWhere('rule', 'seo-viewport-missing');
        file_put_contents("{$this->site}/index.html", "<!-- edited meanwhile -->\n", FILE_APPEND);

        try {
            Fixer::writeOne($a, $viewport->id);
            $this->fail('Expected the write to be refused');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('changed outside this tool', $e->getMessage());
        }
        $this->assertFalse($viewport->written);
        $this->assertStringNotContainsString('name="viewport"', file_get_contents("{$this->site}/index.html"));
    }

    public function test_state_survives_reload(): void
    {
        $a = Analysis::create($this->site);
        $first = $a->issues[0];
        Analysis::mutate($a->id, fn (Analysis $x) => $x->decide($x->issues[1]->id, 'rejected'));
        $reloaded = Analysis::load($a->id);

        $this->assertSame($first->original, $reloaded->issues[0]->original);
        $this->assertSame('rejected', $reloaded->issues[1]->status);
        $this->assertSame(array_keys($a->docs), array_keys($reloaded->docs));
    }
}
