<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spreadsheet_imports', function (Blueprint $table): void {
            $table->json('sheet_headers')->nullable()->after('headers');
        });
    }

    public function down(): void
    {
        Schema::table('spreadsheet_imports', function (Blueprint $table): void {
            $table->dropColumn('sheet_headers');
        });
    }
};
