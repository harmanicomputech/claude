<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ResultStatus;
use App\Http\Controllers\Controller;
use App\Models\Result;
use App\Services\CorrectionReviewer;
use App\Support\Audit;
use App\Support\CsvExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CorrectionController extends Controller
{
    public function index(): View
    {
        return view('admin.corrections', [
            'pending' => Result::with('agent', 'pollingUnit', 'votes', 'corrects.votes', 'corrects.agent')
                ->where('status', ResultStatus::Pending)
                ->oldest()
                ->get(),
            'reviewed' => Result::with('agent')
                ->whereNotNull('corrects_result_id')
                ->whereIn('status', [ResultStatus::Accepted, ResultStatus::Rejected, ResultStatus::Superseded])
                ->whereNotNull('reviewed_at')
                ->latest('reviewed_at')
                ->limit(20)
                ->get(),
        ]);
    }

    public function export(): StreamedResponse
    {
        $timezone = config('election.timezone');
        $parties = config('election.parties');
        Audit::record('correction.exported', 'Exported corrections');

        $rows = function () use ($timezone, $parties) {
            $corrections = Result::with('agent', 'pollingUnit', 'votes', 'corrects.votes')
                ->whereNotNull('corrects_result_id')
                ->oldest()
                ->lazy(500);

            foreach ($corrections as $correction) {
                $proposed = $correction->votesByParty();
                $previous = $correction->corrects?->votesByParty() ?? [];

                yield [
                    $correction->reference,
                    $correction->status === ResultStatus::Pending ? 'pending' : ($correction->status === ResultStatus::Rejected ? 'rejected' : 'approved'),
                    $correction->polling_unit_code,
                    $correction->pollingUnit?->name,
                    $correction->pollingUnit?->lga,
                    $correction->corrects?->reference,
                    ...array_merge(...array_map(fn ($party) => [$previous[$party] ?? '', $proposed[$party] ?? 0], $parties)),
                    $correction->agent->name,
                    $correction->agent->phone_number,
                    $correction->created_at->timezone($timezone)->format('Y-m-d H:i'),
                    $correction->reviewed_by,
                    $correction->reviewed_at?->timezone($timezone)->format('Y-m-d H:i'),
                    $correction->review_note,
                ];
            }
        };

        return CsvExport::download(CsvExport::filename('corrections'), [
            'Reference', 'Decision', 'PU code', 'Polling unit', 'LGA', 'Replaces',
            ...array_merge(...array_map(fn ($party) => ["{$party} before", "{$party} proposed"], $parties)),
            'Agent', 'Agent phone', 'Requested', 'Reviewed by', 'Reviewed at', 'Note',
        ], $rows());
    }

    public function approve(Request $request, Result $result, CorrectionReviewer $reviewer): RedirectResponse
    {
        return $this->review($request, $result, 'approved', fn (?string $by, ?string $note) => $reviewer->approve($result, $by, $note));
    }

    public function reject(Request $request, Result $result, CorrectionReviewer $reviewer): RedirectResponse
    {
        return $this->review($request, $result, 'rejected', fn (?string $by, ?string $note) => $reviewer->reject($result, $by, $note));
    }

    private function review(Request $request, Result $result, string $decision, callable $decide): RedirectResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        try {
            $decide($request->user()->name, $validated['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $result->load('corrects.votes', 'votes');

        Audit::record("correction.{$decision}", ucfirst($decision)." correction {$result->reference} for PU {$result->polling_unit_code}", $result, [
            'note' => $validated['note'] ?? null,
            'previous' => $result->corrects ? ['reference' => $result->corrects->reference, 'votes' => $result->corrects->votesByParty()] : null,
            'proposed' => $result->votesByParty(),
        ]);

        return back()->with('status', ucfirst($decision)." {$result->reference}.");
    }
}
