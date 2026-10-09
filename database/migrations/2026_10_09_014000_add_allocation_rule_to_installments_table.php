<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installments', function (Blueprint $table): void {
            $table->json('allocation_rule')->nullable()->after('allocation_mode');
        });
    }

    public function down(): void
    {
        Schema::table('installments', function (Blueprint $table): void {
            $table->dropColumn('allocation_rule');
        });
    }
};
