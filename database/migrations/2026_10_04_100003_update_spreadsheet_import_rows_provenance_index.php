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
        $indexes = Schema::getIndexes('spreadsheet_import_rows');
        if (collect($indexes)->contains(fn (array $index): bool => $index['columns'] === ['spreadsheet_import_id', 'sheet_name', 'row_number'])) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::disableForeignKeyConstraints();
            Schema::rename('spreadsheet_import_rows', 'spreadsheet_import_rows_legacy');
            Schema::create('spreadsheet_import_rows', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('spreadsheet_import_id')->constrained()->cascadeOnDelete();
                $table->string('sheet_name', 160);
                $table->unsignedInteger('row_number');
                $table->json('raw_data');
                $table->json('mapped_data')->nullable();
                $table->json('issues')->nullable();
                $table->string('duplicate_fingerprint', 64)->nullable()->index();
                $table->string('status', 20)->default('pending_review')->index();
                $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->unique(['spreadsheet_import_id', 'sheet_name', 'row_number']);
            });
            DB::statement('INSERT INTO spreadsheet_import_rows (id, spreadsheet_import_id, sheet_name, row_number, raw_data, mapped_data, issues, duplicate_fingerprint, status, purchase_id, reviewed_at, created_at, updated_at) SELECT id, spreadsheet_import_id, sheet_name, row_number, raw_data, mapped_data, issues, duplicate_fingerprint, status, purchase_id, reviewed_at, created_at, updated_at FROM spreadsheet_import_rows_legacy');
            Schema::drop('spreadsheet_import_rows_legacy');
            Schema::enableForeignKeyConstraints();

            return;
        }

        Schema::table('spreadsheet_import_rows', function (Blueprint $table): void {
            $table->dropUnique('spreadsheet_import_rows_spreadsheet_import_id_row_number_unique');
            $table->unique(['spreadsheet_import_id', 'sheet_name', 'row_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('spreadsheet_import_rows', function (Blueprint $table): void {
            $table->dropUnique(['spreadsheet_import_id', 'sheet_name', 'row_number']);
            $table->unique(['spreadsheet_import_id', 'row_number']);
        });
    }
};
