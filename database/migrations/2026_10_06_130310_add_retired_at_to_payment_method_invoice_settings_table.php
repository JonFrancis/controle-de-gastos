<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_method_invoice_settings', function (Blueprint $table): void {
            $table->timestamp('retired_at')->nullable()->after('updated_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('payment_method_invoice_settings', function (Blueprint $table): void {
            $table->dropColumn('retired_at');
        });
    }
};
