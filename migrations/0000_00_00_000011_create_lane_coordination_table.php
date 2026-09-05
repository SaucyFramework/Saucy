<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lane_coordination', function (Blueprint $table) {
            $table->string('lane', 64)->primary();
            $table->unsignedBigInteger('membership_version')->default(0);
            $table->unsignedBigInteger('acknowledged_version')->default(0);
            $table->boolean('structural_pending')->default(false);
            $table->json('claimed_members')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lane_coordination');
    }
};
