<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_gaps', function (Blueprint $table) {
            $table->string('subscription_id');
            $table->unsignedBigInteger('position');
            $table->dateTime('first_seen_at');

            $table->primary(['subscription_id', 'position']);
            $table->index(['subscription_id', 'first_seen_at'], 'subscription_gaps_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_gaps');
    }
};
