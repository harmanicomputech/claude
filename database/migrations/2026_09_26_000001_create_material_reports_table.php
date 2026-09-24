<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Election materials status per PU; the latest report is the current status.
        Schema::create('material_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('polling_unit_code', 20)->index();
            $table->string('status', 20); // arrived | incomplete | not_arrived
            $table->timestamp('reported_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_reports');
    }
};
