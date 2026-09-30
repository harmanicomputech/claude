<?php

use App\Services\PollingUnitRegister;
use App\Support\Audit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The first bundled register was not INEC's: made-up PU and ward names, codes
 * like EB/212/02633/007 and voter numbers far above Ebonyi's real total. It
 * is replaced by INEC's list (codes like 11/01/01/001, typed as 110101001).
 * Only a database still holding the old codes is changed; agents assigned to
 * an old code are unassigned and listed in the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('polling_units')) {
            return;
        }

        $old = DB::table('polling_units')->where('code', 'like', '2%')->whereRaw('length(code) = 11')->exists();
        if (! $old) {
            return;
        }

        $report = app(PollingUnitRegister::class)->import(database_path('data/ebonyi_polling_units.csv'), replace: true);

        Audit::record('polling_unit.register_replaced', "Replaced the polling unit register with INEC's ({$report['imported']} PUs; {$report['removed']} old PUs removed; ".count($report['unassigned']).' agent(s) need a new PU)', details: [
            'unassigned_agents' => $report['unassigned'],
        ]);
    }

    public function down(): void
    {
        // The old register was not real; there is nothing to go back to.
    }
};
