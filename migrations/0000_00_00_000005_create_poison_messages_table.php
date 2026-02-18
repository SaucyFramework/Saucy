<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poison_messages', function (Blueprint $table) {
            $table->id();
            $table->string('subscription_id');
            $table->unsignedBigInteger('global_position');
            $table->string('message_id');
            $table->string('stream_name');
            $table->text('error_message');
            $table->text('stack_trace');
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('status')->default('poisoned');
            $table->timestamp('poisoned_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'status']);
            $table->index(['subscription_id', 'stream_name', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poison_messages');
    }
};
