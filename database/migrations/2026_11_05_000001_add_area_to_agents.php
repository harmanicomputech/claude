<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An agent's LGA and ward: from the ward lists they were registered with,
 * or their assigned PU. Agents already assigned to a PU get them now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('lga', 100)->nullable()->after('polling_unit_code');
            $table->string('ward', 150)->nullable()->after('lga');
            $table->index(['lga', 'ward']);
        });

        DB::table('agents')->whereNotNull('polling_unit_code')->orderBy('id')->chunkById(500, function ($agents) {
            $units = DB::table('polling_units')->whereIn('code', $agents->pluck('polling_unit_code'))->get(['code', 'lga', 'ward'])->keyBy('code');
            foreach ($agents as $agent) {
                if ($unit = $units->get($agent->polling_unit_code)) {
                    DB::table('agents')->where('id', $agent->id)->update(['lga' => $unit->lga, 'ward' => $unit->ward]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropIndex(['lga', 'ward']);
            $table->dropColumn(['lga', 'ward']);
        });
    }
};
