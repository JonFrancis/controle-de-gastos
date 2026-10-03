<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participants', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->index();
        });

        $self = DB::table('participants')->where('name', 'Eu')->first();
        $selfId = $self?->id ?? DB::table('participants')->insertGetId([
            'name' => 'Eu',
            'active' => true,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('participants')->where('id', $selfId)->update(['active' => true, 'is_default' => true]);
        DB::table('purchases')->whereNull('payer_id')->update(['payer_id' => $selfId]);
        DB::table('purchases')->whereNull('participant_id')->update(['participant_id' => $selfId]);
        DB::table('purchase_allocations')->whereNull('participant_id')->update(['participant_id' => $selfId]);
    }

    public function down(): void
    {
        Schema::table('participants', function (Blueprint $table): void {
            $table->dropColumn('is_default');
        });
    }
};
