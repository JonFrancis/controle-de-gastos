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
        Schema::create('installment_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('installment_number');
            $table->date('purchased_at')->index();
            $table->string('description', 160);
            $table->string('card_name', 160)->nullable();
            $table->unsignedBigInteger('amount_cents');
            $table->foreignId('payer_id')->nullable()->constrained('participants')->nullOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained('participants')->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_adjusted')->default(false);
            $table->timestamp('archived_at')->nullable()->index();
            $table->unique(['installment_id', 'installment_number']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('installment_occurrences');
    }
};
