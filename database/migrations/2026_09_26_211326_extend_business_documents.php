<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // invoice, quote or credit_note (credit notes and cancellation invoices).
            $table->string('type', 16)->default('invoice')->after('household_id');
            // Quote → invoice made from it, credit note → invoice it corrects.
            $table->foreignId('related_invoice_id')->nullable()->after('customer_id')->constrained('invoices')->nullOnDelete();
            $table->date('valid_until')->nullable()->after('due_on');
            $table->decimal('paid_total', 12, 2)->default(0)->after('gross_total');
            $table->index(['user_id', 'type']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->default(0)->after('unit_price');
        });

        Schema::table('business_profiles', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('invoice_footer');
            $table->string('accent_color', 7)->default('#4338ca')->after('logo_path');
            $table->string('quote_prefix', 16)->default('AN-')->after('invoice_prefix');
            $table->string('credit_note_prefix', 16)->default('GS-')->after('quote_prefix');
        });

        // Paid invoices so far had only a date: they become one payment of the full amount.
        DB::table('invoices')->where('status', 'paid')->orderBy('id')->each(function (object $invoice) {
            DB::table('invoice_payments')->insert([
                'invoice_id' => $invoice->id,
                'paid_on' => $invoice->paid_on ?? substr((string) $invoice->updated_at, 0, 10),
                'amount' => $invoice->gross_total,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('invoices')->where('id', $invoice->id)->update(['paid_total' => $invoice->gross_total]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'accent_color', 'quote_prefix', 'credit_note_prefix']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('discount_percent');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'type']);
            $table->dropConstrainedForeignId('related_invoice_id');
            $table->dropColumn(['type', 'valid_until', 'paid_total']);
        });
    }
};
