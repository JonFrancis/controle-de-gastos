<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('mode', 20);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->longText('content');
            $table->timestamps();

            $table->index(['mode', 'start_date', 'end_date', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_versions');
    }
};
