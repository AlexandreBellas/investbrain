<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `source` marks provider rows versus hand-entered rows.
     * Per-share amounts move from FLOAT(12, 4) to DECIMAL(20, 8) so a B3
     * provento (often more than four decimal places) is not rounded before
     * it is multiplied by the number of shares.
     * Exclusions remember symbol/dates the provider must not insert again.
     */
    public function up(): void
    {
        Schema::table('dividends', function (Blueprint $table) {
            $table->string('source', 16)->default('sync')->after('dividend_amount');
        });

        Schema::table('dividends', function (Blueprint $table) {
            $table->decimal('dividend_amount', 20, 8)->nullable(false)->change();
            $table->decimal('dividend_amount_base', 20, 8)->nullable(false)->change();
        });

        Schema::create('dividend_sync_exclusions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('symbol', 25);
            $table->date('date');
            $table->timestamps();

            $table->unique(['symbol', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Reverting the amount columns back to FLOAT(12, 4) rounds stored values.
     */
    public function down(): void
    {
        Schema::dropIfExists('dividend_sync_exclusions');

        Schema::table('dividends', function (Blueprint $table) {
            $table->float('dividend_amount', 12, 4)->nullable(false)->change();
            $table->float('dividend_amount_base', 12, 4)->nullable(false)->change();
            $table->dropColumn('source');
        });
    }
};
