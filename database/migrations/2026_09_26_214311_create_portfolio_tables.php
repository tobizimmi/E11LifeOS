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
        // Master data of shares, ETFs, crypto … shared by all users (Yahoo Finance symbols).
        Schema::create('securities', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 32)->unique();
            $table->string('name')->default('');
            $table->string('isin', 12)->nullable()->index();
            $table->string('wkn', 10)->nullable()->index();
            $table->string('exchange', 64)->nullable();
            $table->string('asset_type', 16)->default('stock');
            $table->string('currency', 3)->nullable();
            $table->string('sector', 100)->nullable();
            $table->string('country', 64)->nullable();
            $table->unsignedBigInteger('market_cap')->nullable();
            $table->decimal('week_52_high', 15, 4)->nullable();
            $table->decimal('week_52_low', 15, 4)->nullable();
            $table->decimal('dividend_yield', 8, 4)->nullable();
            $table->decimal('beta', 8, 4)->nullable();
            // Latest quote in the security's own currency.
            $table->decimal('last_price', 15, 4)->nullable();
            $table->decimal('previous_close', 15, 4)->nullable();
            $table->timestamp('price_updated_at')->nullable();
            $table->timestamps();
        });

        // Daily closing prices (chart history, performance).
        Schema::create('security_prices', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 32);
            $table->date('date');
            $table->decimal('open', 15, 4)->nullable();
            $table->decimal('high', 15, 4)->nullable();
            $table->decimal('low', 15, 4)->nullable();
            $table->decimal('close', 15, 4);
            $table->unsignedBigInteger('volume')->nullable();
            $table->unique(['symbol', 'date']);
        });

        // Cache ISIN/WKN → Yahoo symbol (OpenFIGI).
        Schema::create('security_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('input_key', 20)->unique();
            $table->string('symbol', 32)->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });

        Schema::create('portfolios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('broker', 50)->nullable();
            $table->string('color', 7)->default('#4f46e5');
            $table->unsignedInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('portfolio_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 32);
            $table->string('name')->default('');
            $table->string('isin', 12)->nullable();
            $table->decimal('quantity', 18, 6)->default(0);
            // Average purchase price incl. fees, in the transaction currency.
            $table->decimal('avg_buy_price', 15, 4)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->string('asset_type', 16)->default('stock');
            $table->text('notes')->nullable();
            $table->json('labels')->nullable();
            $table->decimal('stop_loss_price', 15, 4)->nullable();
            $table->decimal('take_profit_price', 15, 4)->nullable();
            // Entered by hand (no transactions): kept when positions are recalculated.
            $table->boolean('manual')->default(false);
            $table->timestamps();
            $table->unique(['portfolio_id', 'symbol']);
        });

        Schema::create('portfolio_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 32)->index();
            $table->string('name')->default('');
            $table->string('isin', 12)->nullable()->index();
            $table->string('wkn', 10)->nullable();
            // buy, sell, dividend, split, fee, transfer_in, transfer_out
            $table->string('type', 16);
            $table->decimal('quantity', 18, 6)->default(0);
            // Price per unit; for splits the split factor, for dividends the total amount.
            $table->decimal('price', 15, 4)->default(0);
            $table->decimal('fees', 10, 4)->default(0);
            $table->decimal('taxes', 10, 4)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->decimal('exchange_rate', 12, 6)->default(1);
            $table->date('executed_on')->index();
            $table->string('broker', 50)->nullable();
            $table->text('notes')->nullable();
            $table->string('import_source', 50)->nullable();
            $table->timestamps();
        });

        // Value over time (chart), several per day while markets are open.
        Schema::create('portfolio_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();
            $table->timestamp('taken_at');
            $table->decimal('total_value', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 4)->default(0);
            $table->index(['portfolio_id', 'taken_at']);
        });

        Schema::create('watchlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 32);
            $table->string('name')->default('');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'symbol']);
        });

        Schema::create('portfolio_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('default_portfolio_id')->nullable()->constrained('portfolios')->nullOnDelete();
            // Recommendation strategy: conservative, moderate, aggressive, custom, thieme.
            $table->string('strategy', 16)->default('moderate');
            $table->decimal('drop_threshold', 5, 2)->default(10);
            $table->unsignedSmallInteger('drop_period_days')->default(30);
            $table->decimal('min_stability_score', 5, 2)->default(40);
            $table->decimal('min_market_cap_bn', 10, 3)->default(0);
            $table->decimal('rsi_threshold', 5, 2)->default(45);
            $table->decimal('max_pe_ratio', 8, 2)->default(999);
            $table->decimal('max_debt_equity', 8, 2)->default(999);
            $table->boolean('check_200ma')->default(false);
            $table->boolean('prefer_dividends')->default(false);
            $table->string('benchmark_symbol', 20)->default('^GDAXI');
            // Tax-free allowance (Sparerpauschbetrag / Freistellungsauftrag).
            $table->decimal('tax_allowance', 10, 2)->default(1000);
            $table->text('pushover_user_key')->nullable();
            $table->text('pushover_app_token')->nullable();
            $table->boolean('news_push_enabled')->default(false);
            $table->boolean('news_push_portfolio_only')->default(true);
            $table->timestamps();
        });

        Schema::create('savings_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 32);
            $table->string('name')->nullable();
            $table->decimal('amount', 12, 2);
            // weekly, monthly, quarterly
            $table->string('frequency', 16)->default('monthly');
            $table->unsignedTinyInteger('day_of_month')->default(1);
            $table->date('next_date');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('target_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 32);
            $table->string('name')->nullable();
            $table->decimal('target_percent', 5, 2)->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'symbol']);
        });

        Schema::create('price_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 32);
            $table->string('name')->nullable();
            // above or below
            $table->string('condition', 8);
            $table->decimal('target_price', 15, 4);
            $table->string('currency', 3)->default('EUR');
            $table->timestamp('triggered_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['active', 'triggered_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['price_alerts', 'target_allocations', 'savings_plans', 'portfolio_settings', 'watchlist_items', 'portfolio_snapshots', 'portfolio_transactions', 'portfolio_positions', 'portfolios', 'security_mappings', 'security_prices', 'securities'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
