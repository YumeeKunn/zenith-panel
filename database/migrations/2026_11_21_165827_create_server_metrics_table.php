<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_metrics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('server_id')->index();
            $table->float('cpu_percent')->default(0);
            $table->unsignedBigInteger('memory_bytes')->default(0);
            $table->unsignedBigInteger('memory_limit_bytes')->default(0);
            $table->unsignedBigInteger('disk_bytes')->default(0);
            $table->unsignedBigInteger('network_rx_bytes')->default(0);
            $table->unsignedBigInteger('network_tx_bytes')->default(0);
            $table->string('state', 24)->default('offline');
            $table->timestamp('collected_at')->index();
            // composite for range queries per server
            $table->index(['server_id', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_metrics');
    }
};
