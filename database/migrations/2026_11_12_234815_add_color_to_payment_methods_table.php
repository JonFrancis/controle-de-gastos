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
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->string('color', 7)->default('#64748B');
        });

        DB::table('payment_methods')->whereRaw('LOWER(name) LIKE ?', ['%picpay%'])->update(['color' => '#21C25E']);
        DB::table('payment_methods')->whereRaw('LOWER(name) LIKE ?', ['%sofisa%'])->update(['color' => '#D97B36']);
        DB::table('payment_methods')->whereRaw('LOWER(name) LIKE ?', ['%nubank%'])->update(['color' => '#A77BEA']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropColumn('color');
        });
    }
};
