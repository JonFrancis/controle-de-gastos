<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spreadsheet_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('original_filename', 255);
            $table->string('stored_path', 255);
            $table->string('file_hash', 64)->index();
            $table->string('status', 20)->index();
            $table->json('headers');
            $table->json('column_mapping')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spreadsheet_imports');
    }
};
