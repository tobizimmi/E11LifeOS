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
        // A property that is bought, renovated, lived in or let (addon "real_estate").
        Schema::create('real_estates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visibility', 10)->default('shared');
            $table->string('name');
            $table->string('street')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('city')->nullable();
            // German state (two-letter code) for the real estate transfer tax.
            $table->string('state', 2)->nullable();
            // "owner" (lived in), "rental" or "mixed" (rental_share % let).
            $table->string('usage', 10)->default('owner');
            $table->unsignedTinyInteger('rental_share')->default(0);
            $table->string('status', 20)->default('planning');
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->date('purchase_date')->nullable();
            // Share of the price for the land (not depreciable), in %.
            $table->decimal('land_share', 5, 2)->default(20);
            $table->decimal('transfer_tax_rate', 5, 2)->nullable();
            $table->decimal('notary_rate', 5, 2)->default(1.5);
            $table->decimal('land_registry_rate', 5, 2)->default(0.5);
            $table->decimal('broker_rate', 5, 2)->default(0);
            $table->decimal('living_area', 8, 2)->nullable();
            $table->decimal('plot_area', 10, 2)->nullable();
            $table->unsignedSmallInteger('construction_year')->nullable();
            $table->decimal('equity', 12, 2)->nullable();
            $table->decimal('monthly_rent', 10, 2)->nullable();
            $table->decimal('monthly_costs', 10, 2)->nullable();
            // Personal marginal income tax rate for the estimates, in %.
            $table->decimal('tax_rate', 5, 2)->default(35);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Budget lines: purchase items and renovation trades with plan and schedule.
        Schema::create('real_estate_budget_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('real_estate_id')->constrained()->cascadeOnDelete();
            $table->string('phase', 20)->default('renovation');
            $table->string('trade', 30)->default('other');
            $table->string('title');
            $table->decimal('planned_amount', 12, 2)->default(0);
            $table->string('status', 20)->default('planned');
            $table->date('start_on')->nullable();
            $table->date('end_on')->nullable();
            $table->string('contractor')->nullable();
            // Energy-efficiency measure (relevant for BEG subsidies and § 35c EStG).
            $table->boolean('energy_measure')->default(false);
            $table->text('notes')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('real_estate_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('real_estate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_item_id')->nullable()->constrained('real_estate_budget_items')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('vendor');
            $table->string('number')->nullable();
            $table->date('invoice_date');
            $table->date('due_on')->nullable();
            $table->decimal('amount', 12, 2);
            // Labour and travel costs (for § 35a EStG), part of the amount.
            $table->decimal('labor_amount', 12, 2)->default(0);
            $table->date('paid_on')->nullable();
            // "auto" or a fixed treatment, see App\Support\RealEstate\RealEstateTax.
            $table->string('tax_treatment', 20)->default('auto');
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('receipt_path')->nullable();
            $table->string('receipt_name')->nullable();
            $table->string('receipt_mime')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('real_estate_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('real_estate_id')->constrained()->cascadeOnDelete();
            $table->string('lender');
            $table->string('kind', 20)->default('annuity');
            $table->decimal('amount', 12, 2);
            $table->decimal('interest_rate', 5, 3);
            // Initial repayment in % per year.
            $table->decimal('repayment_rate', 5, 3)->default(2);
            $table->unsignedTinyInteger('fixed_years')->default(10);
            $table->date('start_on')->nullable();
            $table->timestamps();
        });

        Schema::create('real_estate_subsidies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('real_estate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_item_id')->nullable()->constrained('real_estate_budget_items')->nullOnDelete();
            $table->string('program', 40)->default('other');
            $table->string('title');
            // "grant" (money you keep), "loan" (cheap loan) or "tax" (tax reduction).
            $table->string('kind', 10)->default('grant');
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('status', 20)->default('idea');
            $table->date('deadline_on')->nullable();
            $table->date('applied_on')->nullable();
            $table->date('approved_on')->nullable();
            $table->date('paid_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('real_estate_subsidies');
        Schema::dropIfExists('real_estate_loans');
        Schema::dropIfExists('real_estate_invoices');
        Schema::dropIfExists('real_estate_budget_items');
        Schema::dropIfExists('real_estates');
    }
};
