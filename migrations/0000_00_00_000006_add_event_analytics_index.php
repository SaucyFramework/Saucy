<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_store', function (Blueprint $table) {
            $table->index(['created_at', 'message_type'], 'event_analytics_index');
        });
    }

    public function down(): void
    {
        Schema::table('event_store', function (Blueprint $table) {
            $table->dropIndex('event_analytics_index');
        });
    }
};
