<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_metrics', function (Blueprint $table): void {
            $table->float('allocated_memory_percent')->nullable()->after('memory_percent');
            $table->float('allocated_disk_percent')->nullable()->after('disk_percent');
        });
    }

    public function down(): void
    {
        Schema::table('node_metrics', function (Blueprint $table): void {
            $table->dropColumn(['allocated_memory_percent', 'allocated_disk_percent']);
        });
    }
};
