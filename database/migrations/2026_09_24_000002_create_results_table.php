<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('results', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            // One result per polling unit; the unique index is the last line of
            // defence against duplicate submissions racing each other.
            $table->string('polling_unit_code', 20)->unique();
            $table->unsignedInteger('candidate_votes');
            $table->unsignedInteger('total_votes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('results');
    }
};
