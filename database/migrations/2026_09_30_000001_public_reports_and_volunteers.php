<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The USSD service is open to the public:
 * - incidents may come from anyone ("public"), with the caller's number and
 *   the LGA/ward they picked; the polling unit is optional for them;
 * - "How can you help?" sign-ups are kept in volunteers (one per phone
 *   number; signing up again updates it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->foreignId('agent_id')->nullable()->change();
            $table->string('polling_unit_code', 20)->nullable()->change();
            $table->string('source', 10)->default('agent')->index();
            $table->string('reporter_phone', 20)->nullable()->index();
            $table->string('lga')->nullable();
            $table->string('ward')->nullable();
        });

        Schema::create('volunteers', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->string('phone_number', 20)->unique();
            $table->string('contact_phone', 20);
            $table->string('name', 80);
            $table->string('lga');
            $table->string('ward');
            $table->json('roles');
            $table->json('skills')->nullable();
            $table->string('other', 160)->nullable();
            $table->string('channel', 10)->default('ussd');
            $table->boolean('is_agent')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volunteers');

        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropIndex(['reporter_phone']);
            $table->dropColumn(['source', 'reporter_phone', 'lga', 'ward']);
        });
    }
};
