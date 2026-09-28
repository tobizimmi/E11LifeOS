<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index rent, the tenant portal link and handover protocols (meters, keys, rooms, photos).
     */
    public function up(): void
    {
        Schema::table('real_estate_tenants', function (Blueprint $table) {
            $table->decimal('index_base', 8, 1)->nullable()->after('rent_adjustment');
            $table->string('index_base_month', 7)->nullable()->after('index_base');
            $table->date('last_rent_change_on')->nullable()->after('index_base_month');
            $table->string('portal_token', 64)->nullable()->unique()->after('last_rent_change_on');
        });

        Schema::create('real_estate_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('real_estate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('real_estate_tenants')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20);
            $table->date('handed_over_on');
            $table->json('meters')->nullable();
            $table->json('keys')->nullable();
            $table->json('rooms')->nullable();
            $table->json('photos')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('real_estate_handovers');

        Schema::table('real_estate_tenants', function (Blueprint $table) {
            $table->dropUnique(['portal_token']);
            $table->dropColumn(['index_base', 'index_base_month', 'last_rent_change_on', 'portal_token']);
        });
    }
};
