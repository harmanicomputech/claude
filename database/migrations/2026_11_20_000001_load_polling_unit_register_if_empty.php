<?php

use App\Services\PollingUnitRegister;
use App\Support\Audit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loads the bundled INEC register when the polling unit table is empty: the
 * USSD LGA and ward lists, PU numbers and agents' wards all come from it, and
 * a fresh install could go live without anyone pressing "Import polling
 * units". A register that has any rows is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tests build their own small registers.
        if (app()->environment('testing') || ! Schema::hasTable('polling_units') || DB::table('polling_units')->exists()) {
            return;
        }

        $report = app(PollingUnitRegister::class)->import(database_path('data/ebonyi_polling_units.csv'));

        Audit::record('polling_unit.register_loaded', "Loaded INEC's polling unit register automatically ({$report['imported']} PUs): the register was empty");
    }

    public function down(): void
    {
        // Nothing to undo: the register is the reference data the service needs.
    }
};
