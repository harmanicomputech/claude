<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each submission came from: "ussd", or "web" for the Election Shield
 * web app's agent pages. Web incident notes can be longer than USSD's.
 */
return new class extends Migration
{
    private const TABLES = ['results', 'incidents', 'presences', 'material_reports'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('channel', 10)->default('ussd');
            });
        }

        Schema::table('incidents', function (Blueprint $table) {
            $table->text('note')->change();
        });
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('channel');
            });
        }
    }
};
