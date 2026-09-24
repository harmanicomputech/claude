<?php

namespace App\Http\Controllers\Api;

use App\Enums\ResultStatus;
use App\Http\Controllers\Controller;
use App\Models\Result;
use App\Services\CorrectionReviewer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class CorrectionController extends Controller
{
    /**
     * List corrections (pending by default), newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(ResultStatus::class)->only([ResultStatus::Pending, ResultStatus::Accepted, ResultStatus::Rejected])],
        ]);

        $corrections = Result::whereNotNull('corrects_result_id')
            ->where('status', $validated['status'] ?? ResultStatus::Pending->value)
            ->latest()
            ->limit(500)
            ->get();

        return response()->json([
            'data' => $corrections->map(fn (Result $result) => $result->toDashboardArray())->all(),
        ]);
    }

    public function approve(Request $request, string $reference, CorrectionReviewer $reviewer): JsonResponse
    {
        return $this->review($request, $reference, fn (Result $result, array $input) => $reviewer->approve($result, ...$input));
    }

    public function reject(Request $request, string $reference, CorrectionReviewer $reviewer): JsonResponse
    {
        return $this->review($request, $reference, fn (Result $result, array $input) => $reviewer->reject($result, ...$input));
    }

    /**
     * @param  callable(Result, array{reviewedBy: ?string, note: ?string}): Result  $decide
     */
    private function review(Request $request, string $reference, callable $decide): JsonResponse
    {
        $validated = $request->validate([
            'reviewed_by' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $result = Result::where('reference', $reference)->firstOrFail();

        try {
            $result = $decide($result, ['reviewedBy' => $validated['reviewed_by'] ?? null, 'note' => $validated['note'] ?? null]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $result->toDashboardArray()]);
    }
}
