<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            // Assigned when the invoice is issued, so numbers stay gapless; drafts have none.
            $table->string('number', 40)->nullable();
            $table->string('status', 16)->default('draft');
            $table->date('issued_on')->nullable();
            $table->string('service_period')->nullable();
            $table->date('due_on')->nullable();
            $table->date('paid_on')->nullable();
            // Recipient as printed on the invoice (customers may change later).
            $table->text('recipient');
            $table->string('subject')->nullable();
            $table->text('intro')->nullable();
            $table->text('closing')->nullable();
            $table->boolean('small_business')->default(true);
            $table->decimal('net_total', 12, 2)->default(0);
            $table->decimal('vat_total', 12, 2)->default(0);
            $table->decimal('gross_total', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'number']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
