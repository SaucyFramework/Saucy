<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projection_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('stream_id');
            $table->unsignedBigInteger('position');
            $table->unsignedBigInteger('max_position');
            $table->dateTime('recorded_at');

            $table->index(['stream_id', 'recorded_at'], 'projection_snapshots_stream_recorded_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projection_snapshots');
    }
};
