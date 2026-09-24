<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PollingUnit;
use App\Services\ElectionStats;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MissingController extends Controller
{
    public function __invoke(Request $request, ElectionStats $stats): View
    {
        $type = $request->query('type') === ElectionStats::MISSING_PRESENCE ? ElectionStats::MISSING_PRESENCE : ElectionStats::MISSING_RESULTS;
        $lga = $request->query('lga');

        $missing = $stats->missing($type)
            ->when($lga, fn ($units) => $units->where('lga', $lga))
            ->values();

        return view('admin.missing', [
            'type' => $type,
            'lga' => $lga,
            'lgas' => PollingUnit::distinct()->orderBy('lga')->pluck('lga'),
            'missing' => $missing,
        ]);
    }
}
