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
        Schema::create('purchase_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained('participants')->nullOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedInteger('percentage_basis_points')->nullable();
            $table->timestamps();

            $table->unique(['purchase_id', 'participant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_allocations');
    }
};
