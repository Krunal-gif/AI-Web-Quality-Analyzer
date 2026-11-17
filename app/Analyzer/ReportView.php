<?php

namespace App\Analyzer;

/**
 * Small HTML helpers for the Blade report (resources/views/report.blade.php).
 */
final class ReportView
{
    public const SEV_LABEL = ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low', 'info' => 'Info'];

    public const GROUPS = [
        ['applied', 'Fixed', 'Approved fixes that were applied.'],
        ['approved', 'Approved — not yet applied', 'Approved by the reviewer, waiting for “Apply”.'],
        ['conflict', 'Conflicts', 'Approved fixes that overlapped another fix — re-run the analysis and apply again.'],
        ['failed', 'Failed', 'Fixes that could not be written.'],
        ['pending', 'Awaiting review', 'Fix proposed, not yet approved or rejected.'],
        ['rejected', 'Rejected', 'The reviewer chose not to apply these fixes.'],
        ['manual', 'Manual action needed', "Issues that can't be fixed by editing one place automatically."],
    ];

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Code block: context with the region marked as removed (before) or inserted (after). */
    public static function code(?array $ctx, ?string $replacement = null, bool $insert = false): string
    {
        if (! $ctx) {
            return '<div class="nocode">No source location (site-level finding)</div>';
        }
        $region = $replacement ?? $ctx['original'];
        $cls = $replacement === null ? 'del' : 'ins';
        if ($insert && $replacement === null) {
            $mid = '<span class="caret" title="Missing code belongs here">⟨ missing ⟩</span>';
        } elseif ($region === '') {
            $mid = '<span class="caret">⟨ removed ⟩</span>';
        } else {
            $mid = '<mark class="'.$cls.'">'.self::e($region).'</mark>';
        }

        return '<div class="code"><div class="ln">from line '.$ctx['first_line'].'</div><pre>'
            .self::e($ctx['prefix']).$mid.self::e($ctx['suffix']).'</pre></div>';
    }

    public static function groups(array $issues): array
    {
        $out = [];
        foreach (self::GROUPS as [$key, $label, $desc]) {
            $group = array_values(array_filter($issues, fn ($i) => $key === 'manual'
                ? ! $i['editable'] : ($i['editable'] && $i['status'] === $key)));
            if ($group) {
                $out[] = compact('key', 'label', 'desc', 'group');
            }
        }

        return $out;
    }
}
