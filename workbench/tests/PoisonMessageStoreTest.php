<?php

declare(strict_types=1);

namespace Workbench\Tests;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Saucy\Core\Subscriptions\PoisonMessages\IlluminatePoisonMessageStore;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessage;
use Saucy\Core\Subscriptions\PoisonMessages\PoisonMessageStatus;

/**
 * A subscription in FailureMode::Halt rethrows after poisoning, so the poll
 * aborts without advancing the checkpoint and the next tick re-hits the same
 * event. store() used to INSERT every time, so a stuck projector wrote one row
 * a minute for as long as it stayed stuck. These tests pin the collapse.
 */
final class PoisonMessageStoreTest extends WithDatabaseTestCase
{
    private const TABLE = 'poison_messages';

    public function test_it_records_a_newly_poisoned_event(): void
    {
        $this->store()->store($this->message('sub_a', 'msg-1'));

        $row = DB::table(self::TABLE)->sole();

        $this->assertSame('sub_a', $row->subscription_id);
        $this->assertSame('msg-1', $row->message_id);
        $this->assertSame(PoisonMessageStatus::Poisoned->value, $row->status);
    }

    public function test_re_recording_the_same_event_updates_the_open_row_instead_of_inserting(): void
    {
        $store = $this->store();

        $store->store($this->message('sub_a', 'msg-1', 'boom'));
        $firstPoisonedAt = DB::table(self::TABLE)->value('poisoned_at');

        $store->store($this->message('sub_a', 'msg-1', 'boom again'));
        $store->store($this->message('sub_a', 'msg-1', 'boom once more'));

        $row = DB::table(self::TABLE)->sole();

        $this->assertSame('boom once more', $row->error_message, 'the row carries the latest failure');
        $this->assertSame($firstPoisonedAt, $row->poisoned_at, 'poisoned_at stays the FIRST failure, so the row reads as "stuck since"');
        $this->assertSame(30, (int) $row->retry_count, 'retry_count accumulates handler attempts across cycles, not cycles');
    }

    public function test_a_different_event_or_subscription_gets_its_own_row(): void
    {
        $store = $this->store();

        $store->store($this->message('sub_a', 'msg-1'));
        $store->store($this->message('sub_a', 'msg-2'));
        $store->store($this->message('sub_b', 'msg-1'));

        $this->assertSame(3, DB::table(self::TABLE)->count());
    }

    public function test_an_event_that_poisons_again_after_being_skipped_opens_a_new_row(): void
    {
        $store = $this->store();

        $store->store($this->message('sub_a', 'msg-1'));
        $store->skip((int) DB::table(self::TABLE)->value('id'));

        $store->store($this->message('sub_a', 'msg-1'));

        $this->assertSame(2, DB::table(self::TABLE)->count(), 'a closed row is history; a new failure opens a new one');
        $this->assertSame(1, DB::table(self::TABLE)->where('status', PoisonMessageStatus::Poisoned->value)->count());
    }

    public function test_an_event_that_poisons_again_after_being_resolved_opens_a_new_row(): void
    {
        $store = $this->store();

        $store->store($this->message('sub_a', 'msg-1'));
        $store->resolve((int) DB::table(self::TABLE)->value('id'));

        $store->store($this->message('sub_a', 'msg-1'));

        $this->assertSame(2, DB::table(self::TABLE)->count());
        $this->assertSame(1, DB::table(self::TABLE)->where('status', PoisonMessageStatus::Poisoned->value)->count());
    }

    public function test_the_collapsed_row_is_still_the_one_getUnresolved_returns(): void
    {
        $store = $this->store();

        $store->store($this->message('sub_a', 'msg-1', 'boom'));
        $store->store($this->message('sub_a', 'msg-1', 'boom again'));

        $unresolved = $store->getUnresolved('sub_a');

        $this->assertCount(1, $unresolved);
        $this->assertSame('boom again', $unresolved[0]->errorMessage);
        $this->assertTrue($store->hasUnresolvedForStream('sub_a', 'bank_account###01ABC'));
    }

    public function test_the_dedupe_lookup_is_indexed_and_the_migration_is_re_runnable(): void
    {
        $this->assertTrue(
            Schema::hasIndex('poison_messages', 'poison_messages_sub_msg_status_index'),
            'store() reads by (subscription_id, message_id, status) on every recording',
        );

        // The migration guards on this same check, so an app that already added
        // the index by hand does not get MySQL 1061 on its next deploy.
        $this->artisan('migrate')->assertSuccessful();
    }

    private function store(): IlluminatePoisonMessageStore
    {
        return new IlluminatePoisonMessageStore(DB::connection());
    }

    private function message(string $subscriptionId, string $messageId, string $error = 'boom'): PoisonMessage
    {
        return new PoisonMessage(
            id: null,
            subscriptionId: $subscriptionId,
            globalPosition: 1234,
            messageId: $messageId,
            streamName: 'bank_account###01ABC',
            errorMessage: $error,
            stackTrace: '#0 somewhere',
            retryCount: 10,
            status: PoisonMessageStatus::Poisoned,
            poisonedAt: new DateTimeImmutable(),
        );
    }
}
