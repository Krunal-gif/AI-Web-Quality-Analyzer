<?php

namespace App\Http\Controllers;

use App\Analyzer\Analysis;
use App\Analyzer\Fixer;
use App\Analyzer\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AnalysisController extends Controller
{
    public const MAX_PAGES = 500;

    /** Recent analyses + data for the start screen. */
    public function index(): JsonResponse
    {
        $demo = config('webqa.demo_path');

        return response()->json([
            'history' => Analysis::history(),
            'demo' => is_dir($demo) ? $demo : null,
            'settings' => Settings::get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target' => ['required', 'string', 'max:2048'],
            // websites: how many pages to analyse — the sitemap's pages first, then links (1 = only the URL entered)
            'pages' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PAGES],
        ]);
        $pages = (int) ($data['pages'] ?? 1);
        set_time_limit(max(300, $pages * 25));
        $analysis = Analysis::create($data['target'], $pages);

        return response()->json($analysis->toArray(), 201);
    }

    /** Remove a saved analysis from the list (deletes its folder in storage, not your project files). */
    public function destroy(string $id): JsonResponse
    {
        Analysis::delete($id);

        return response()->json(['deleted' => $id, 'history' => Analysis::history()]);
    }

    /** Remove every saved analysis; one with an AI review still running is kept. */
    public function destroyAll(): JsonResponse
    {
        $removed = 0;
        $kept = 0;
        foreach (Analysis::history(PHP_INT_MAX) as $h) {
            try {
                Analysis::delete($h['id']);
                $removed++;
            } catch (\DomainException) {
                $kept++;
            }
        }

        return response()->json(['removed' => $removed, 'kept' => $kept, 'history' => Analysis::history()]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(Analysis::load($id)->toArray());
    }

    /** Approve / reject / reset an issue, or save an edited fix. */
    public function updateIssue(Request $request, string $id, string $issue): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'proposed' => ['nullable', 'string'],
            'source' => ['nullable', 'in:rule,ai,user'],
        ]);
        $state = Analysis::mutate($id, function (Analysis $a) use ($issue, $data, $request) {
            $a->decide($issue, $data['status'] ?? null,
                $request->has('proposed') ? (string) $request->input('proposed', '') : null, $data['source'] ?? null);

            return $a->toArray();
        });

        return response()->json($state);
    }

    public function bulk(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['string'],
            'status' => ['required', 'in:pending,approved,rejected'],
        ]);
        [$changed, $state] = Analysis::mutate($id, function (Analysis $a) use ($data) {
            $changed = 0;
            foreach ($data['ids'] as $issueId) {
                $item = $a->issue($issueId);
                if ($item && $item->editable() && $item->proposed !== null && in_array($item->status, ['pending', 'approved', 'rejected'], true)) {
                    $a->decide($issueId, $data['status']);
                    $changed++;
                }
            }

            return [$changed, $a->toArray()];
        });

        return response()->json(['changed' => $changed] + $state);
    }

    public function apply(Request $request, string $id): JsonResponse
    {
        $mode = $request->validate(['mode' => ['required', 'in:patch,inplace']])['mode'];
        $state = Analysis::mutate($id, function (Analysis $a) use ($mode) {
            Fixer::apply($a, $mode);

            return $a->toArray();
        });

        return response()->json($state);
    }

    /** Write one issue's fix straight into its file on disk. */
    public function write(string $id, string $issue): JsonResponse
    {
        $state = Analysis::mutate($id, function (Analysis $a) use ($issue) {
            Fixer::writeOne($a, $issue);

            return $a->toArray();
        });

        return response()->json($state);
    }

    public function report(string $id): BinaryFileResponse
    {
        $paths = Analysis::mutate($id, fn (Analysis $a) => $a->writeReports());

        return response()->file($paths['html'], ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function reportJson(string $id): BinaryFileResponse
    {
        $paths = Analysis::mutate($id, fn (Analysis $a) => $a->writeReports());

        return response()->download($paths['json'], "webqa-report-{$id}.json");
    }

    /** The same report as a standalone .html file, for sharing (it has no external assets). */
    public function reportHtml(string $id): BinaryFileResponse
    {
        $paths = Analysis::mutate($id, fn (Analysis $a) => $a->writeReports());

        return response()->download($paths['html'], "webqa-report-{$id}.html", ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function patch(string $id): BinaryFileResponse
    {
        $a = Analysis::load($id);
        abort_unless($a->after && $a->after['patch'] && is_file($a->after['patch']), 404, 'No patch yet — apply some fixes first.');

        return response()->download($a->after['patch'], "changes-{$id}.patch", ['Content-Type' => 'text/x-diff; charset=utf-8']);
    }
}
