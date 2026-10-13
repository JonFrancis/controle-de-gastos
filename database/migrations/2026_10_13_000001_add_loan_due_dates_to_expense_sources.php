<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            $table->date('due_date')->nullable()->after('purchased_at');
        });

        Schema::table('installments', function (Blueprint $table): void {
            $table->date('due_date')->nullable()->after('start_date');
        });

        Schema::table('installment_occurrences', function (Blueprint $table): void {
            $table->date('due_date')->nullable()->after('purchased_at');
        });

        Schema::table('recurrences', function (Blueprint $table): void {
            $table->date('due_date')->nullable()->after('start_date');
        });

        Schema::table('recurrence_occurrences', function (Blueprint $table): void {
            $table->date('due_date')->nullable()->after('purchased_at');
        });
    }

    public function down(): void
    {
        Schema::table('recurrence_occurrences', function (Blueprint $table): void {
            $table->dropColumn('due_date');
        });

        Schema::table('recurrences', function (Blueprint $table): void {
            $table->dropColumn('due_date');
        });

        Schema::table('installment_occurrences', function (Blueprint $table): void {
            $table->dropColumn('due_date');
        });

        Schema::table('installments', function (Blueprint $table): void {
            $table->dropColumn('due_date');
        });

        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropColumn('due_date');
        });
    }
};
