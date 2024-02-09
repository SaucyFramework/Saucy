<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('running_processes', function (Blueprint $table) {
            $table->string('subscription_id')->primary();
            $table->ulid('process_id');
            $table->dateTime('expires_at');
            $table->index(['subscription_id', 'process_id', 'expires_at'], 'running_processes_subscription_id_process_id_expires_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('running_processes');
    }
};
