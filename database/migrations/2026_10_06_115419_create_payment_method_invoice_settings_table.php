<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_method_invoice_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_method_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('closing_day');
            $table->unsignedTinyInteger('due_day')->nullable();
            $table->date('effective_from');
            $table->timestamps();

            $table->unique(['payment_method_id', 'effective_from']);
        });

        $paymentMethods = DB::table('payment_methods')
            ->where('type', 'credit')
            ->whereNotNull('closing_day')
            ->get(['id', 'closing_day', 'created_at']);

        foreach ($paymentMethods as $paymentMethod) {
            $createdAt = $paymentMethod->created_at ?? now()->toDateTimeString();

            DB::table('payment_method_invoice_settings')->insert([
                'payment_method_id' => $paymentMethod->id,
                'closing_day' => $paymentMethod->closing_day,
                'due_day' => null,
                'effective_from' => substr($createdAt, 0, 10),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_method_invoice_settings');
    }
};
