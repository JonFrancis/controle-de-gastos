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
        Schema::table('receipt_applications', function (Blueprint $table): void {
            $table->dropUnique(['receipt_id', 'source_type', 'source_id']);
            $table->timestamp('superseded_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('receipt_applications', function (Blueprint $table): void {
            $table->dropColumn('superseded_at');
            $table->unique(['receipt_id', 'source_type', 'source_id']);
        });
    }
};
