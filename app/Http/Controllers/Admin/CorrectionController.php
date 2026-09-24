<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ResultStatus;
use App\Http\Controllers\Controller;
use App\Models\Result;
use App\Services\CorrectionReviewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

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
