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
        Schema::create('receipt_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->foreignId('purchase_allocation_id')->nullable()->constrained('purchase_allocations')->nullOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->string('source')->default('automatic');
            $table->timestamps();

            $table->unique(['receipt_id', 'source_type', 'source_id']);
            $table->index(['source_type', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receipt_applications');
    }
};
