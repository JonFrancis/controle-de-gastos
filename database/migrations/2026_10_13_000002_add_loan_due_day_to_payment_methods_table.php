<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->unsignedTinyInteger('loan_due_day')->nullable()->after('closing_day');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropColumn('loan_due_day');
        });
    }
};
