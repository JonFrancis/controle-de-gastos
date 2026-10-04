<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spreadsheet_imports', function (Blueprint $table): void {
            $table->boolean('uses_excel_1904_date_system')->default(false)->after('sheet_headers');
        });
    }

    public function down(): void
    {
        Schema::table('spreadsheet_imports', function (Blueprint $table): void {
            $table->dropColumn('uses_excel_1904_date_system');
        });
    }
};
