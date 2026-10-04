<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

            $table->unique(['spreadsheet_import_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spreadsheet_import_rows');
    }
};
