<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_metrics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('node_id')->index();
            $table->float('cpu_percent')->default(0);
            $table->float('memory_percent')->default(0);
            $table->unsignedBigInteger('memory_used_bytes')->default(0);
            $table->unsignedBigInteger('memory_total_bytes')->default(0);
            $table->float('disk_percent')->default(0);
            $table->unsignedBigInteger('disk_used_bytes')->default(0);
            $table->unsignedBigInteger('disk_total_bytes')->default(0);
            $table->timestamp('collected_at')->index();
            $table->index(['node_id', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_metrics');
    }
};
