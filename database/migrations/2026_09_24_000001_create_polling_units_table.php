<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // INEC polling unit register, imported with `php artisan pu:import`.
        Schema::create('polling_units', function (Blueprint $table) {
            $table->id();
            // Digits only, e.g. INEC 11-05-03-004 is stored as 110503004.
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('ward', 100)->index();
            $table->string('lga', 100)->index();
            $table->unsignedInteger('registered_voters')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polling_units');
    }
};
