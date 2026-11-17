<?php

namespace App\Analyzer;

use App\Analyzer\Rules\RuleEngine;

/**
 * Apply user-approved fixes, write a patch, then re-scan to verify each fix.
 */
final class Fixer
{
    private const CONFLICT_NOTE = 'Overlaps another approved fix at the same place — apply, then re-run the analysis to handle it.';

    /**
     * mode 'patch':   write fixed copies + changes.patch, leave originals untouched.
     * mode 'inplace': overwrite the project files (originals backed up to the analysis folder).
     */
    public static function apply(Analysis $a, string $mode = 'patch'): array
    {
        if (! in_array($mode, ['patch', 'inplace'], true)) {
            throw new \DomainException("Unknown apply mode {$mode}");
        }
        if ($mode === 'inplace' && $a->target['kind'] === 'url') {
            throw new \DomainException("A live website can't be edited in place — use 'patch' mode to prepare the changes.");
        }
        if ($a->locked) {
            throw new \DomainException('Fixes were already written in place. Re-run the analysis to continue on the updated files.');
        }

        foreach ($a->issues as $item) {   // patch mode can be re-run: rebuild from scratch each time
            if ($item->written) {         // already in the file (and in $a->docs) — never apply twice
                continue;
            }
            if (in_array($item->status, ['applied', 'conflict', 'failed'], true)) {
                $item->status = 'approved';
                $item->resultNote = '';
            }
            $item->resolved = null;
        }

        $byFile = [];
        foreach ($a->issues as $item) {
            if ($item->status === 'approved' && $item->editable() && $item->proposed !== null && ! $item->written) {
                $byFile[$item->file][] = $item;
            }
        }

        $fixedDir = $a->dir().'/fixed';
        $backupDir = $a->dir().'/backup';
        Analysis::removeDir($fixedDir);
        $newTexts = [];
        $patch = '';
        $files = [];

        foreach ($byFile as $rel => $items) {
            $doc = $a->doc($rel);
            $text = $doc->text;
            if ($mode === 'inplace') {
                $disk = @file_get_contents($doc->path);
                if ($disk === false || $disk !== $doc->encode($text)) {
                    foreach ($items as $item) {
                        $item->status = 'failed';
                        $item->resultNote = 'File changed on disk since the analysis — re-run it.';
                    }

                    continue;
                }
            }
            $new = self::applyToText($text, $items);
            if ($new === $text) {
                continue;
            }
            $newTexts[$rel] = $new;
            $patch .= Diff::unified($rel, $text, $new);
            $target = $mode === 'patch' ? "{$fixedDir}/{$rel}" : $doc->path;
            if ($mode === 'inplace') {
                @mkdir(dirname("{$backupDir}/{$rel}"), 0775, true);
                copy($doc->path, "{$backupDir}/{$rel}");
            }
            @mkdir(dirname($target), 0775, true);
            file_put_contents($target, $doc->encode($new));
            $files[] = ['file' => $rel, 'written_to' => $target,
                'fixes' => count(array_filter($items, fn ($i) => $i->status === 'applied'))];
        }

        $patchPath = $a->dir().'/changes.patch';
        file_put_contents($patchPath, $patch);

        // Re-scan the fixed documents to verify every applied fix really resolved its issue.
        $afterDocs = array_map(fn (Document $d) => isset($newTexts[$d->rel]) ? $d->withText($newTexts[$d->rel]) : $d, array_values($a->docs));
        $afterIssues = RuleEngine::analyze($afterDocs, $a->target['site']);
        $remaining = array_flip(array_map(fn (Issue $i) => $i->fingerprint(), $afterIssues));
        foreach ($a->issues as $item) {
            if ($item->status === 'applied') {
                $item->resolved = ! isset($remaining[$item->fingerprint()]);
            }
        }
        $before = array_flip(array_map(fn (Issue $i) => $i->fingerprint(), $a->issues));
        $newIssues = array_values(array_filter($afterIssues, fn (Issue $i) => ! isset($before[$i->fingerprint()])));

        if ($mode === 'inplace' && $files) {
            $a->locked = true;
        }
        $count = fn (callable $f) => count(array_filter($a->issues, $f));
        $a->after = [
            'mode' => $mode,
            'files' => $files,
            'patch' => $patch !== '' ? $patchPath : null,
            'fixed_dir' => ($mode === 'patch' && $files) ? $fixedDir : null,
            'backup_dir' => ($mode === 'inplace' && $files) ? $backupDir : null,
            'applied' => $count(fn ($i) => $i->status === 'applied'),
            'conflicts' => $count(fn ($i) => $i->status === 'conflict'),
            'failed' => $count(fn ($i) => $i->status === 'failed'),
            'resolved' => $count(fn ($i) => $i->resolved === true),
            'unresolved' => $count(fn ($i) => $i->resolved === false),
            'scores' => Scoring::score($afterIssues),
            'remaining' => array_map(fn (Issue $i) => $i->toArray(), $afterIssues),
            'new_issues' => array_map(fn (Issue $i) => $i->toArray(), $newIssues),
        ];
        $a->writeReports();

        return $a->after;
    }

    /**
     * Write one issue's fix straight into its file on disk (local folders/files only).
     *
     * The stored copy of the file is updated to match, other issues in the file are moved to
     * their new positions, and the file is re-scanned to verify the fix. The original is backed
     * up once, before the first write to that file. The analysis stays open for more fixes.
     */
    public static function writeOne(Analysis $a, string $issueId): Issue
    {
        $item = $a->issue($issueId) ?? throw new \OutOfBoundsException("Unknown issue {$issueId}");
        if ($a->target['kind'] === 'url') {
            throw new \DomainException("A live website can't be edited directly — use 'patch' mode to prepare the changes.");
        }
        if ($a->locked) {
            throw new \DomainException('Fixes were already written in place. Re-run the analysis to continue on the updated files.');
        }
        if ($item->written) {
            throw new \DomainException('This fix is already written to the file.');
        }
        if (! $item->editable() || $item->proposed === null || ! in_array($item->status, ['pending', 'approved'], true)) {
            throw new \DomainException('This issue has no fix that can be written to the file.');
        }
        $doc = $a->doc($item->file);
        if (! $doc?->path) {
            throw new \DomainException("{$item->file} has no file on disk to write to.");
        }
        $disk = @file_get_contents($doc->path);
        if ($disk === false || $disk !== $doc->encode($doc->text)) {
            throw new \DomainException("{$item->file} was changed outside this tool since the analysis — re-run the analysis first.");
        }
        $text = $doc->text;
        if (substr($text, $item->start, $item->end - $item->start) !== $item->original) {
            throw new \DomainException('The code at this location no longer matches the analysis — re-run it.');
        }

        $new = substr($text, 0, $item->start).$item->proposed.substr($text, $item->end);
        $backup = $a->dir()."/backup/{$item->file}";
        if (! is_file($backup)) {   // keep the version from before the first change
            @mkdir(dirname($backup), 0775, true);
            copy($doc->path, $backup);
        }
        file_put_contents($doc->path, $doc->encode($new));

        // move every other issue in this file to where its code is now
        $delta = strlen($item->proposed) - ($item->end - $item->start);
        $lineDelta = substr_count($item->proposed, "\n") - substr_count($item->original, "\n");
        $endLine = $doc->lines()->lineOf($item->end);
        foreach ($a->issues as $other) {
            if ($other === $item || $other->file !== $item->file || $other->written) {
                continue;
            }
            if (! $other->editable()) {
                if ($other->line > $endLine) {
                    $other->line += $lineDelta;
                }
            } elseif ($other->start >= $item->end) {
                $other->start += $delta;
                $other->end += $delta;
                $other->line += $lineDelta;
            } elseif ($other->end > $item->start) {
                // its code was just rewritten: the old fix can't be applied safely any more
                $other->start = $other->end = -1;
                $other->status = in_array($other->status, ['approved', 'pending'], true) ? 'pending' : $other->status;
                $other->resultNote = 'This code was changed by another fix written to the file — re-run the analysis for an up-to-date fix.';
            }
        }

        $before = count(array_filter($a->issues, fn (Issue $i) => $i->fingerprint() === $item->fingerprint() && ! ($i->written && $i->resolved)));
        $a->docs[$item->file] = $doc->withText($new);
        $item->end = $item->start + strlen($item->proposed);
        $item->status = 'applied';
        $item->written = true;
        $item->resultNote = '';
        $after = count(array_filter(RuleEngine::analyze([$a->docs[$item->file]], null), fn (Issue $i) => $i->fingerprint() === $item->fingerprint()));
        $item->resolved = $after < $before;

        $a->saveDocs();
        $a->writeReports();

        return $item;
    }

    /**
     * Apply fixes to one file. Fixes whose regions overlap (e.g. two attributes added to the
     * same tag) are merged when their individual edits don't collide; otherwise the later one
     * is marked as a conflict.
     *
     * @param  Issue[]  $items
     */
    public static function applyToText(string $text, array $items): string
    {
        usort($items, fn (Issue $x, Issue $y) => [$x->start, $x->end, $x->id] <=> [$y->start, $y->end, $y->id]);
        $valid = [];
        foreach ($items as $item) {
            if (substr($text, $item->start, $item->end - $item->start) !== $item->original) {
                $item->status = 'failed';
                $item->resultNote = 'Source no longer matches the analysed text.';
            } else {
                $valid[] = $item;
            }
        }

        $clusters = [];
        foreach ($valid as $item) {
            $last = count($clusters) - 1;
            if ($last >= 0 && ($item->start < $clusters[$last]['end']
                || ($item->start === $clusters[$last]['end'] && $item->start === $item->end))) {
                $clusters[$last]['items'][] = $item;
                $clusters[$last]['end'] = max($clusters[$last]['end'], $item->end);

                continue;
            }
            $clusters[] = ['start' => $item->start, 'end' => $item->end, 'items' => [$item]];
        }

        $out = '';
        $pos = 0;
        foreach ($clusters as $c) {
            [$s, $e] = [$c['start'], $c['end']];
            $base = substr($text, $s, $e - $s);
            $accepted = [];
            $merged = $base;
            $members = $c['items'];
            usort($members, fn (Issue $x, Issue $y) => strcmp($x->id, $y->id));
            foreach ($members as $item) {
                $whole = substr($text, $s, $item->start - $s).$item->proposed.substr($text, $item->end, $e - $item->end);
                $attempt = Diff::mergeEdits($base, [...$accepted, $whole]);
                if ($attempt === null) {
                    $item->status = 'conflict';
                    $item->resultNote = self::CONFLICT_NOTE;

                    continue;
                }
                $accepted[] = $whole;
                $merged = $attempt;
                $item->status = 'applied';
            }
            $out .= substr($text, $pos, $s - $pos).$merged;
            $pos = $e;
        }

        return $out.substr($text, $pos);
    }
}
