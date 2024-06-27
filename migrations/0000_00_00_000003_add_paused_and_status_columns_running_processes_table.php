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
        Schema::create('paused_subscriptions', function (Blueprint $table) {
            $table->string('subscription_id')->primary();
            $table->string('reason')->nullable();
        });

        Schema::table('running_processes', function (Blueprint $table) {
            $table->string('status');
            $table->dateTime('last_status_change_at')->nullable();
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
