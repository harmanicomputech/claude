<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ElectionStats;
use App\Support\SystemStatus;
use Illuminate\View\View;

class OverviewController extends Controller
{
    public function __invoke(SystemStatus $status, ElectionStats $stats): View
    {
        $ready = $status->isReady();

        return view('admin.overview', [
            'checks' => $status->checks(),
            'callbackUrl' => $status->callbackUrl(),
            'ready' => $ready,
            'summary' => $ready ? $stats->summary() : null,
        ]);
    }
}
