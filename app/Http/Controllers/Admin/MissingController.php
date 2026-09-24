<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ElectionStats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The missing-PU lists now live on the Polling units page as filters.
 */
class MissingController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        return redirect()->route('admin.polling-units.index', array_filter([
            'status' => $request->query('type') === ElectionStats::MISSING_PRESENCE ? 'no_presence' : 'no_result',
            'lga' => $request->query('lga'),
        ]));
    }
}
