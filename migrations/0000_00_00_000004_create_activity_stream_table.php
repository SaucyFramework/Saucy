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
        Schema::create('subscription_activity_stream_log', function (Blueprint $table) {
            $table->id();
            $table->string('stream_id');
            $table->string('type');
            $table->string('message');
            $table->dateTime('occurred_at');
            $table->json('data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paused_subscriptions');
        Schema::table('running_processes', function (Blueprint $table) {
            $table->dropColumn('status');
            $table->dropColumn('last_status_change_at');
        });
    }
};
