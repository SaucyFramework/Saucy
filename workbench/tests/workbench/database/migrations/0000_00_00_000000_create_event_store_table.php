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
        Schema::create('event_store', function (Blueprint $table) {
            $table->unsignedBigInteger('global_position')->primary();
            $table->ulid('message_id');
            $table->string('message_type');
            $table->string('stream_name');
            $table->unsignedInteger('stream_position');
            $table->json('payload');
            $table->json('metadata')->nullable();
            $table->dateTime('created_at');

            $table->unique(['stream_name', 'message_id'], 'idempotency_index');
            $table->unique(['stream_name', 'stream_position'], 'optimistic_lock_index');
            $table->index(['stream_name', 'stream_position'], 'reconstitution_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_store');
    }
};
