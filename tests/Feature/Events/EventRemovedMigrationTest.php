<?php

namespace Tests\Feature\Events;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Task #58: an event can be removed by a system admin. The upgrade adds the status and the three
 * columns that say who removed it, when and why, and keeps every event as it was. The way back is
 * refused while an event is removed, so going back never loses the fact that it was.
 *
 * Runs on a database file of its own, so the migration can be run in steps.
 */
class EventRemovedMigrationTest extends TestCase
{
    private const CONNECTION = 'removed_migration';

    private const NEW = '2026_11_21_100000_add_removed_to_events.php';

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'removed-').'.sqlite';
        touch($this->file);
        config(['database.connections.'.self::CONNECTION => [
            'driver' => 'sqlite',
            'database' => $this->file,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    protected function tearDown(): void
    {
        DB::purge(self::CONNECTION);
        @unlink($this->file);

        parent::tearDown();
    }

    private function db(): Connection
    {
        return DB::connection(self::CONNECTION);
    }

    /**
     * Every migration before the one of Task #58.
     *
     * @return list<string>
     */
    private function before(): array
    {
        $files = glob(database_path('migrations/*.php'));
        sort($files);

        return array_values(array_filter($files, fn (string $file) => basename($file) !== self::NEW));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function migrate(array $options = []): void
    {
        $this->artisan('migrate', ['--database' => self::CONNECTION, '--force' => true, ...$options])->assertSuccessful();
    }

    private function rollBack(): void
    {
        $this->artisan('migrate:rollback', ['--database' => self::CONNECTION, '--step' => 1, '--force' => true])->assertSuccessful();
    }

    /**
     * One event and the two people around it, written the way the old code wrote them.
     *
     * @return array{event: int, organizer: int, admin: int}
     */
    private function oldEvent(): array
    {
        $db = $this->db();
        $now = now();

        $organizer = $db->table('users')->insertGetId(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'x', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $admin = $db->table('users')->insertGetId(['name' => 'Bea', 'email' => 'bea@example.test', 'password' => 'x', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $topic = $db->table('topics')->insertGetId(['title' => 'University', 'depth' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $db->table('topic_closure')->insert(['ancestor_id' => $topic, 'descendant_id' => $topic, 'distance' => 0]);
        $semester = $db->table('semesters')->insertGetId(['name' => 'Autumn', 'starts_at' => '2026-09-01', 'ends_at' => '2027-01-31', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $event = $db->table('events')->insertGetId([
            'title' => 'SQL night', 'description' => 'Practice.', 'category' => null,
            'created_by_id' => $organizer, 'owning_topic_id' => $topic, 'semester_id' => $semester, 'capacity' => 10,
            'application_type' => 'fcfs', 'points_value' => 5, 'event_starts_at' => $now, 'event_ends_at' => $now,
            'application_deadline' => $now, 'completion_deadline' => $now, 'status' => 'published',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return compact('event', 'organizer', 'admin');
    }

    /** @return array<string, bool> whether each unknown value was refused */
    private function refusals(): array
    {
        $db = $this->db();
        $tries = [
            'events.status' => fn () => $db->table('events')->update(['status' => 'archived']),
            'events.application_type' => fn () => $db->table('events')->update(['application_type' => 'lottery']),
        ];

        return collect($tries)->map(function (callable $try) {
            try {
                $try();

                return false;
            } catch (QueryException) {
                return true;
            }
        })->all();
    }

    public function test_the_upgrade_keeps_the_event_and_allows_the_removed_status(): void
    {
        $this->migrate(['--path' => $this->before(), '--realpath' => true]);
        $ids = $this->oldEvent();

        $this->migrate();

        $db = $this->db();
        $this->assertSame('published', $db->table('events')->where('id', $ids['event'])->value('status'));
        $this->assertTrue($db->getSchemaBuilder()->hasColumns('events', ['removed_at', 'removed_by_id', 'removal_reason']));
        $this->assertNull($db->table('events')->where('id', $ids['event'])->value('removed_at'));

        $db->table('events')->where('id', $ids['event'])->update([
            'status' => 'removed', 'removed_at' => now(), 'removed_by_id' => $ids['admin'], 'removal_reason' => 'Spam',
        ]);
        $this->assertSame('removed', $db->table('events')->where('id', $ids['event'])->value('status'));

        // Nothing else is accepted, and the other fixed-value column still refuses its unknown values:
        // SQLite copies the table to change a column, and the copy must keep that check.
        $this->assertSame(['events.status' => true, 'events.application_type' => true], $this->refusals());
    }

    public function test_the_person_who_removed_it_may_be_deleted_without_deleting_the_event(): void
    {
        $this->migrate(['--path' => $this->before(), '--realpath' => true]);
        $ids = $this->oldEvent();
        $this->migrate();

        $db = $this->db();
        $db->table('events')->where('id', $ids['event'])->update(['status' => 'removed', 'removed_at' => now(), 'removed_by_id' => $ids['admin'], 'removal_reason' => 'Spam']);

        $db->table('users')->where('id', $ids['admin'])->delete();

        $this->assertNull($db->table('events')->where('id', $ids['event'])->value('removed_by_id'));
        $this->assertSame('Spam', $db->table('events')->where('id', $ids['event'])->value('removal_reason'));
    }

    public function test_the_copy_of_the_table_keeps_its_foreign_keys_and_indexes(): void
    {
        $this->migrate(['--path' => $this->before(), '--realpath' => true]);
        $db = $this->db();
        $count = fn (string $what): int => count($db->select("PRAGMA {$what}('events')"));

        $keys = $count('foreign_key_list');
        $indexes = $count('index_list');

        $this->migrate();
        $this->assertSame($keys + 1, $count('foreign_key_list'), 'the one new key is the person who removed it');
        $this->assertSame($indexes, $count('index_list'));

        $this->rollBack();
        $this->assertSame($keys, $count('foreign_key_list'));
        $this->assertSame($indexes, $count('index_list'));
    }

    public function test_going_back_restores_the_five_statuses_and_keeps_the_data(): void
    {
        $this->migrate(['--path' => $this->before(), '--realpath' => true]);
        $ids = $this->oldEvent();
        $this->migrate();

        $this->rollBack();

        $db = $this->db();
        $this->assertFalse($db->getSchemaBuilder()->hasColumn('events', 'removed_at'));
        $this->assertFalse($db->getSchemaBuilder()->hasColumn('events', 'removed_by_id'));
        $this->assertFalse($db->getSchemaBuilder()->hasColumn('events', 'removal_reason'));
        $this->assertSame('published', $db->table('events')->where('id', $ids['event'])->value('status'));
        $this->assertSame('SQL night', $db->table('events')->where('id', $ids['event'])->value('title'));

        // The five statuses are back, and "removed" is not one of them.
        $this->assertSame(['events.status' => true, 'events.application_type' => true], $this->refusals());
        $this->expectException(QueryException::class);
        $db->table('events')->where('id', $ids['event'])->update(['status' => 'removed']);
    }

    public function test_going_back_is_refused_while_an_event_is_removed(): void
    {
        $this->migrate(['--path' => $this->before(), '--realpath' => true]);
        $ids = $this->oldEvent();
        $this->migrate();

        $db = $this->db();
        $db->table('events')->where('id', $ids['event'])->update(['status' => 'removed', 'removed_at' => now(), 'removed_by_id' => $ids['admin'], 'removal_reason' => 'Spam']);

        try {
            $this->artisan('migrate:rollback', ['--database' => self::CONNECTION, '--step' => 1, '--force' => true]);
            $this->fail('Going back was not refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 events are removed', $e->getMessage());
        }

        // Nothing changed: the event is still removed, with its reason.
        $this->assertTrue($db->getSchemaBuilder()->hasColumn('events', 'removal_reason'));
        $this->assertSame('removed', $db->table('events')->where('id', $ids['event'])->value('status'));
        $this->assertSame('Spam', $db->table('events')->where('id', $ids['event'])->value('removal_reason'));
    }
}
