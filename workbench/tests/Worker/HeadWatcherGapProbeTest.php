<?php

namespace Workbench\Tests\Worker;

use Saucy\Core\Subscriptions\Worker\HeadWatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;

/**
 * the gap probe against real InnoDB locks. A second connection holds a real open
 * transaction, so this test commits to its own scratch table instead of using the per-test
 * transaction.
 */
final class HeadWatcherGapProbeTest extends TestCase
{
    private const TABLE = 'head_watcher_probe_events';

    private float $now = 1_000.0;

    private Connection $writer;

    protected function defineEnvironment($app): void
    {
        // A real InnoDB server: the probe is MySQL behaviour. No per-test transaction either, since
        // a second connection has to see what the first committed.
        $host = getenv('SAUCY_TEST_MYSQL_HOST');
        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => is_string($host) ? $host : '',
            'port' => getenv('SAUCY_TEST_MYSQL_PORT') ?: '3306',
            'database' => getenv('SAUCY_TEST_MYSQL_DATABASE') ?: 'saucy_test',
            'username' => getenv('SAUCY_TEST_MYSQL_USERNAME') ?: 'root',
            'password' => getenv('SAUCY_TEST_MYSQL_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
        $app['config']->set('database.default', 'mysql');
    }

    protected function setUp(): void
    {
        $host = getenv('SAUCY_TEST_MYSQL_HOST');
        if (!is_string($host) || $host === '') {
            $this->markTestSkipped('Set SAUCY_TEST_MYSQL_HOST to run the gap-probe tests against MySQL.');
        }
        parent::setUp();
        config(['database.connections.probe_writer' => config('database.connections.mysql')]);
        $this->writer = DB::connection('probe_writer');

        DB::connection('mysql')->getSchemaBuilder()->dropIfExists(self::TABLE);
        DB::connection('mysql')->getSchemaBuilder()->create(self::TABLE, function (Blueprint $table) {
            $table->unsignedBigInteger('global_position', true);
            $table->string('message_type');
            $table->string('stream_name')->unique();
            $table->unsignedInteger('stream_position')->default(1);
            $table->dateTime('created_at');
        });
    }

    protected function tearDown(): void
    {
        if (!isset($this->writer)) {
            parent::tearDown();

            return;
        }
        if ($this->writer->transactionLevel() > 0) {
            $this->writer->rollBack();
        }
        DB::connection('mysql')->getSchemaBuilder()->dropIfExists(self::TABLE);
        DB::purge('probe_writer');
        parent::tearDown();
    }

    public function test_it_waits_for_an_open_transaction_and_skips_a_hole_nobody_holds(): void
    {
        $watcher = $this->watcher();
        $this->insert('a', 'one');

        // Position 2 belongs to an open transaction; position 3 burned by a failed insert.
        $this->writer->beginTransaction();
        $this->insert('slow', 'two');
        try {
            DB::connection('mysql')->table(self::TABLE)->insert(['message_type' => 'dup', 'stream_name' => 'one', 'created_at' => now()]);
            $this->fail('expected a duplicate key');
        } catch (QueryException) {
        }
        $this->insert('b', 'four', DB::connection('mysql'));

        $this->assertSame(1, $watcher->poll()->to);

        // Well past the probe delay, and past the 5 s no-probe timeout: still waiting, the
        // transaction holds its row.
        $this->now += 10;
        $this->assertNull($watcher->poll());
        $this->assertSame(0, $watcher->skippedGapCount());

        $this->writer->commit();
        $window = $watcher->poll();

        // 2 committed, 3 nobody holds: skipped at once, without a timeout.
        $this->assertSame(4, $window->to);
        $this->assertSame(['slow', 'b'], $window->eventTypes);
        $this->assertSame(1, $watcher->skippedGapCount());
        $this->assertSame(0, $watcher->timedOutGapCount());
    }

    public function test_a_rolled_back_transaction_costs_only_the_probe_delay(): void
    {
        $watcher = $this->watcher();
        $this->writer->beginTransaction();
        $this->insert('gone', 'two');
        $this->writer->rollBack();
        $this->insert('b', 'three', DB::connection('mysql'));

        // The hole's age counts from when the watcher first saw it.
        $this->assertNull($watcher->poll());
        $this->now += 0.2;
        $this->assertNull($watcher->poll());

        $this->now += 0.1;
        $this->assertSame(['b'], $watcher->poll()->eventTypes);
        $this->assertSame(0, $watcher->timedOutGapCount());
    }

    public function test_a_transaction_open_past_the_in_flight_timeout_is_skipped_and_reported_when_it_commits(): void
    {
        $watcher = $this->watcher();
        $this->writer->beginTransaction();
        $this->insert('slow', 'one');
        $this->insert('b', 'two', DB::connection('mysql'));

        $this->assertNull($watcher->poll());
        $this->now += 31;
        $this->assertSame(['b'], $watcher->poll()->eventTypes);
        $this->assertSame(1, $watcher->timedOutGapCount());

        $this->writer->commit();
        $this->assertSame('slow', $watcher->lateFills()[0]['eventType']);
    }

    private function watcher(): HeadWatcher
    {
        $watcher = new HeadWatcher(
            connection: DB::connection('mysql'),
            gapTimeoutSeconds: 5.0,
            bootLookback: 0,
            table: self::TABLE,
            clock: fn() => $this->now,
            primary: DB::connection('mysql'),
            probeAfterSeconds: 0.25,
            inFlightTimeoutSeconds: 30.0,
        );
        $watcher->boot();

        return $watcher;
    }

    private function insert(string $type, string $stream, ?Connection $connection = null): void
    {
        ($connection ?? $this->writer)->table(self::TABLE)->insert([
            'message_type' => $type,
            'stream_name' => $stream,
            'created_at' => now(),
        ]);
    }
}
