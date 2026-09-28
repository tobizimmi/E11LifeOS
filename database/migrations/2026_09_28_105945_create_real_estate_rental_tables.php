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
        Schema::table('real_estates', function (Blueprint $table) {
            // Public link of the applicant form (Selbstauskunft); null = closed.
            $table->string('applicant_token', 64)->nullable()->unique();
            $table->text('applicant_intro')->nullable();
            // Total rent of the advertised flat, to compare it with the applicants' income.
            $table->decimal('applicant_rent', 10, 2)->nullable();
            // Landlord details for the lease and the utility statements.
            $table->string('landlord_name')->nullable();
            $table->string('landlord_address')->nullable();
            $table->string('landlord_iban', 34)->nullable();
        });

        // Tenants with their lease: rent, advance payment for utilities, deposit, term.
        Schema::create('real_estate_tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('real_estate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('unit')->nullable();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('previous_address')->nullable();
            $table->unsignedTinyInteger('persons')->default(1);
            $table->decimal('area', 8, 2)->nullable();
            $table->date('move_in_on');
            $table->date('move_out_on')->nullable();
            $table->decimal('cold_rent', 10, 2)->default(0);
            $table->decimal('utilities_advance', 10, 2)->default(0);
            $table->decimal('deposit', 10, 2)->default(0);
            $table->date('deposit_paid_on')->nullable();
            // Text in the bank transfer that identifies the tenant (defaults to the name).
            $table->string('payment_reference')->nullable();
            $table->date('lease_end_on')->nullable();
            $table->unsignedTinyInteger('notice_months')->default(3);
            // "none", "index" (Indexmiete) or "graduated" (Staffelmiete).
            $table->string('rent_adjustment', 10)->default('none');
            $table->unsignedTinyInteger('due_day')->default(3);
            $table->text('special_terms')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Rent received per month (from a bank booking or entered by hand).
        Schema::create('real_estate_rent_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('real_estate_tenants')->cascadeOnDelete();
            $table->date('month');
            $table->decimal('amount', 10, 2);
            $table->date('paid_on');
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'month']);
        });

        // Yearly utility statement (Nebenkostenabrechnung) with the allocatable cost lines.
        Schema::create('real_estate_utility_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('real_estate_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->date('period_start');
            $table->date('period_end');
            // list of {type, label, amount, key (area|persons|units|equal)}
            $table->json('costs')->nullable();
            $table->decimal('total_area', 8, 2)->nullable();
            $table->unsignedSmallInteger('total_units')->nullable();
            $table->date('sent_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['real_estate_id', 'year']);
        });

        // Value development: estimates, appraisals, offers.
        Schema::create('real_estate_valuations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('real_estate_id')->constrained()->cascadeOnDelete();
            $table->date('valued_on');
            $table->decimal('value', 12, 2);
            $table->string('source', 20)->default('estimate');
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        // Applicants from the public form incl. their self-disclosure (Selbstauskunft).
        Schema::create('real_estate_applicants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('real_estate_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('new');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 50)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('current_address')->nullable();
            $table->string('occupation')->nullable();
            $table->string('employer')->nullable();
            $table->date('employed_since')->nullable();
            $table->decimal('net_income', 10, 2)->nullable();
            $table->unsignedTinyInteger('persons')->default(1);
            $table->unsignedTinyInteger('children')->default(0);
            $table->string('pets')->nullable();
            $table->boolean('smoker')->default(false);
            $table->string('current_landlord')->nullable();
            $table->boolean('rent_arrears')->default(false);
            $table->boolean('insolvency')->default(false);
            $table->boolean('eviction')->default(false);
            $table->boolean('credit_report')->default(false);
            $table->date('desired_move_in')->nullable();
            $table->text('message')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('real_estate_applicants');
        Schema::dropIfExists('real_estate_valuations');
        Schema::dropIfExists('real_estate_utility_statements');
        Schema::dropIfExists('real_estate_rent_payments');
        Schema::dropIfExists('real_estate_tenants');

        Schema::table('real_estates', function (Blueprint $table) {
            $table->dropUnique(['applicant_token']);
            $table->dropColumn(['applicant_token', 'applicant_intro', 'applicant_rent', 'landlord_name', 'landlord_address', 'landlord_iban']);
        });
    }
};
