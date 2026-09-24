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
            $table->string('polling_unit_code', 20)->index();
            // accepted | pending (correction awaiting review) | rejected | superseded
            $table->string('status', 20)->index();
            // Equals polling_unit_code while the result is the accepted one and
            // is null otherwise, so the unique index allows exactly one accepted
            // result per PU (MySQL has no partial indexes).
            $table->string('accepted_polling_unit_code', 20)->nullable()->unique();
            $table->foreignId('corrects_result_id')->nullable()->constrained('results')->nullOnDelete();
            $table->unsignedInteger('accredited_voters');
            $table->unsignedInteger('rejected_votes');
            $table->unsignedInteger('total_valid_votes');
            $table->unsignedInteger('total_votes_cast');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();
        });

        Schema::create('result_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('result_id')->constrained()->cascadeOnDelete();
            $table->string('party', 20);
            $table->unsignedInteger('votes');
            $table->unique(['result_id', 'party']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_votes');
        Schema::dropIfExists('results');
    }
};
