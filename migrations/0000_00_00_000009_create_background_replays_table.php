<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('background_replays', function (Blueprint $table) {
            $table->string('subscription_id')->primary();
            $table->string('status');
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('background_replays');
    }
};
