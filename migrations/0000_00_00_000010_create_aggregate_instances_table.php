<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aggregate_instances', function (Blueprint $table) {
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->unsignedInteger('stream_position')->default(0);
            $table->primary(['aggregate_type', 'aggregate_id']);
            $table->index('aggregate_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aggregate_instances');
    }
};
