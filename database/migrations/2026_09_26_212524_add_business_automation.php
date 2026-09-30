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
        // Templates that create an invoice every month, quarter … (subscriptions, retainers).
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('recipient');
            $table->string('subject')->nullable();
            $table->text('intro')->nullable();
            $table->text('closing')->nullable();
            // Lines as JSON: description, quantity, unit, unit_price, discount_percent, vat_rate.
            $table->json('items');
            $table->string('frequency', 16)->default('monthly');
            $table->date('next_run_on');
            $table->date('ends_on')->nullable();
            $table->boolean('auto_issue')->default(true);
            $table->boolean('auto_send')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['active', 'next_run_on']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable()->after('paid_total');
            // 0 = none, 1 = payment reminder, 2 = first dunning letter, 3 = second dunning letter.
            $table->unsignedTinyInteger('reminder_level')->default(0)->after('sent_at');
            $table->date('last_reminder_at')->nullable()->after('reminder_level');
            // Customer portal: the document can be viewed and downloaded with this link.
            $table->string('public_token', 48)->nullable()->unique()->after('last_reminder_at');
            $table->foreignId('recurring_invoice_id')->nullable()->after('related_invoice_id')->constrained()->nullOnDelete();
        });

        Schema::table('customers', function (Blueprint $table) {
            // Leitweg-ID / buyer reference for e-invoices (XRechnung BT-10).
            $table->string('buyer_reference', 64)->nullable()->after('vat_id');
        });

        Schema::table('business_profiles', function (Blueprint $table) {
            $table->decimal('hourly_rate', 10, 2)->nullable()->after('default_vat_rate');
            $table->unsignedSmallInteger('reminder_days')->default(7)->after('payment_days');
            $table->decimal('reminder_fee_1', 8, 2)->default(0)->after('reminder_days');
            $table->decimal('reminder_fee_2', 8, 2)->default(5)->after('reminder_fee_1');
            $table->decimal('reminder_fee_3', 8, 2)->default(10)->after('reminder_fee_2');
        });

        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->timestamp('started_at');
            // Null while the timer is running.
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('minutes')->nullable();
            $table->decimal('hourly_rate', 10, 2)->nullable();
            // Invoice the time was billed with.
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_entries');

        Schema::table('business_profiles', function (Blueprint $table) {
            $table->dropColumn(['hourly_rate', 'reminder_days', 'reminder_fee_1', 'reminder_fee_2', 'reminder_fee_3']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('buyer_reference');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurring_invoice_id');
            $table->dropUnique(['public_token']);
            $table->dropColumn(['sent_at', 'reminder_level', 'last_reminder_at', 'public_token']);
        });

        Schema::dropIfExists('recurring_invoices');
    }
};
