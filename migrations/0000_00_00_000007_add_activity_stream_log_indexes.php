<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_activity_stream_log', function (Blueprint $table) {
            $table->index(['stream_id', 'occurred_at'], 'activity_stream_id_occurred_at_index');
            $table->index(['stream_id', 'type', 'occurred_at'], 'activity_stream_id_type_occurred_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_activity_stream_log', function (Blueprint $table) {
            $table->dropIndex('activity_stream_id_occurred_at_index');
            $table->dropIndex('activity_stream_id_type_occurred_at_index');
        });
    }
};
