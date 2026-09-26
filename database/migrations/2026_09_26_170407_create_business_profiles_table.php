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
        Schema::create('business_profiles', function (Blueprint $table) {
            $table->id();
            // The small business belongs to one person; other household members never see it.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('company_name');
            $table->string('owner_name')->nullable();
            $table->string('street')->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('city')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('website')->nullable();
            $table->string('tax_number', 32)->nullable();
            $table->string('vat_id', 32)->nullable();
            // Kleinunternehmerregelung (§ 19 UStG): invoices without VAT.
            $table->boolean('small_business')->default(true);
            $table->decimal('default_vat_rate', 5, 2)->default(19);
            $table->string('bank_name')->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('bic', 11)->nullable();
            $table->string('invoice_prefix', 16)->default('RE-');
            $table->unsignedSmallInteger('payment_days')->default(14);
            $table->text('invoice_footer')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_profiles');
    }
};
