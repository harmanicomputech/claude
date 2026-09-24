<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ResultStatus;
use App\Http\Controllers\Controller;
use App\Models\Result;
use App\Services\CorrectionReviewer;
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
        return $this->review($request, fn (?string $by, ?string $note) => $reviewer->approve($result, $by, $note), "Approved {$result->reference}.");
    }

    public function reject(Request $request, Result $result, CorrectionReviewer $reviewer): RedirectResponse
    {
        return $this->review($request, fn (?string $by, ?string $note) => $reviewer->reject($result, $by, $note), "Rejected {$result->reference}.");
    }

    private function review(Request $request, callable $decide, string $message): RedirectResponse
    {
        $validated = $request->validate([
            'reviewed_by' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $decide($validated['reviewed_by'] ?? 'Admin console', $validated['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $message);
    }
}
