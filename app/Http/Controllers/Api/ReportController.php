<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ElectionStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function summary(ElectionStats $stats): JsonResponse
    {
        return response()->json(['data' => $stats->summary()]);
    }

    /**
     * PUs with no presence check-in today, or no accepted result.
     */
    public function missing(Request $request, ElectionStats $stats): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in([ElectionStats::MISSING_PRESENCE, ElectionStats::MISSING_RESULTS])],
            'lga' => ['nullable', 'string'],
        ]);

        $missing = $stats->missing($validated['type'])
            ->when($validated['lga'] ?? null, fn ($units, $lga) => $units->where('lga', $lga))
            ->values();

        return response()->json(['count' => $missing->count(), 'data' => $missing->all()]);
    }
}
