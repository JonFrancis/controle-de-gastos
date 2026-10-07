<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            $table->boolean('automatic_backup_enabled')->default(false);
            $table->string('backup_path')->nullable();
            $table->string('last_backup_path')->nullable();
            $table->timestamp('last_backup_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table): void {
            $table->dropColumn(['automatic_backup_enabled', 'backup_path', 'last_backup_path', 'last_backup_at']);
        });
    }
};
