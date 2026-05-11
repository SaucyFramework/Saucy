<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bumps `event_store.created_at` from DATETIME (second precision) to
 * DATETIME(3) (millisecond precision) so the visibility-delay filter
 * works at sub-second granularity.
 *
 * Online DDL on MySQL 8 / InnoDB — INSTANT for adding fractional
 * precision; no table copy. Existing rows are padded with `.000`.
 *
 * On PlanetScale / Vitess this is also non-blocking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_store', function (Blueprint $table) {
            $table->dateTime('created_at', 3)->change();
        });
    }

    public function down(): void
    {
        Schema::table('event_store', function (Blueprint $table) {
            $table->dateTime('created_at')->change();
        });
    }
};
