<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One volunteer per contact number (not per phone dialled from), so one
 * phone can sign up several people, each with their own number.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Two existing sign-ups with one contact number: keep both, just index it.
        $clash = DB::table('volunteers')->select('contact_phone')->groupBy('contact_phone')->havingRaw('count(*) > 1')->exists();

        Schema::table('volunteers', function (Blueprint $table) use ($clash) {
            $table->dropUnique(['phone_number']);
            $table->index('phone_number');
            $clash ? $table->index('contact_phone') : $table->unique('contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('volunteers', function (Blueprint $table) {
            Schema::hasIndex('volunteers', 'volunteers_contact_phone_unique') ? $table->dropUnique(['contact_phone']) : $table->dropIndex(['contact_phone']);
            $table->dropIndex(['phone_number']);
            $table->unique('phone_number');
        });
    }
};
