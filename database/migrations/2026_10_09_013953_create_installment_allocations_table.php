<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('installment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('installment_occurrence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained('participants')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedInteger('percentage_basis_points')->nullable();
            $table->timestamps();

            $table->unique(['installment_occurrence_id', 'participant_id']);
        });

        DB::table('installments')->orderBy('id')->eachById(function (object $installment): void {
            $occurrences = DB::table('installment_occurrences')
                ->where('installment_id', $installment->id)
                ->orderBy('id')
                ->get();

            DB::table('installments')->where('id', $installment->id)->update(['allocation_mode' => 'equal']);

            foreach ($occurrences as $occurrence) {
                $allocationId = DB::table('installment_allocations')->insertGetId([
                    'installment_occurrence_id' => $occurrence->id,
                    'participant_id' => $occurrence->participant_id,
                    'category_id' => $occurrence->category_id,
                    'amount_cents' => $occurrence->amount_cents,
                    'percentage_basis_points' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('receipt_applications')
                    ->where('source_type', 'installment_occurrence')
                    ->where('source_id', $occurrence->id)
                    ->update([
                        'source_type' => 'installment_allocation',
                        'source_id' => $allocationId,
                    ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $allocations = DB::table('installment_allocations')->get(['id', 'installment_occurrence_id']);

        foreach ($allocations as $allocation) {
            DB::table('receipt_applications')
                ->where('source_type', 'installment_allocation')
                ->where('source_id', $allocation->id)
                ->update([
                    'source_type' => 'installment_occurrence',
                    'source_id' => $allocation->installment_occurrence_id,
                ]);
        }

        Schema::dropIfExists('installment_allocations');
    }
};
