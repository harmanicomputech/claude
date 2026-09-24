<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Outbox of events for the external dashboard. A row stays undelivered
        // until the dashboard answers 2xx, and `dashboard:sync` resends those.
        Schema::create('dashboard_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('event', 50);
            $table->string('idempotency_key', 100)->unique();
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_deliveries');
    }
};
