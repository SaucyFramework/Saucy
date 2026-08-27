<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index the lookup IlluminatePoisonMessageStore::store() now does to collapse
 * repeat poisonings of the same event onto one row. Neither existing index
 * leads with message_id, so without this the dedupe read scans the table on
 * every recording.
 *
 * Guarded on existence: an app that hit the duplicate-row problem before this
 * shipped may already have added this exact index by hand, and package
 * migrations run inside the app — an unguarded CREATE would fail its next
 * deploy with MySQL 1061 (duplicate key name). The `0000_00_00_` prefix sorts
 * this ahead of every dated app migration, so the app cannot drop the index
 * first to get out of the way.
 *
 * Short explicit name to stay under MySQL's 64-character index-name limit.
 */
return new class extends Migration
{
    private const INDEX = 'poison_messages_sub_msg_status_index';

    public function up(): void
    {
        if (Schema::hasIndex('poison_messages', self::INDEX)) {
            return;
        }

        Schema::table('poison_messages', function (Blueprint $table) {
            $table->index(['subscription_id', 'message_id', 'status'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (!Schema::hasIndex('poison_messages', self::INDEX)) {
            return;
        }

        Schema::table('poison_messages', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};
