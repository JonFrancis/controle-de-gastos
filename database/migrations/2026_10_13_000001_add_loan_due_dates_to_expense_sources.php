<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            $table->date('due_date')->nullable()->after('purchased_at')->index();
        });

        Schema::table('installments', function (Blueprint $table): void {
            $table->date('due_date')->nullable()->after('start_date')->index();
        });

        Schema::table('installment_occurrences', function (Blueprint $table): void {
            $table->date('due_date')->nullable()->after('purchased_at')->index();
        });

        Schema::table('recurrences', function (Blueprint $table): void {
            $table->date('due_date')->nullable()->after('start_date')->index();
        });

        Schema::table('recurrence_occurrences', function (Blueprint $table): void {
            $table->date('due_date')->nullable()->after('purchased_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('recurrence_occurrences', fn (Blueprint $table): Blueprint => $table->dropColumn('due_date'));
        Schema::table('recurrences', fn (Blueprint $table): Blueprint => $table->dropColumn('due_date'));
        Schema::table('installment_occurrences', fn (Blueprint $table): Blueprint => $table->dropColumn('due_date'));
        Schema::table('installments', fn (Blueprint $table): Blueprint => $table->dropColumn('due_date'));
        Schema::table('purchases', fn (Blueprint $table): Blueprint => $table->dropColumn('due_date'));
    }
};
