<?php

namespace Tests\Feature\Categories;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Task #116: the category columns move from four fixed values to the category list. The upgrade
 * keeps every stored category, the way back is refused once it would lose one, and the ledger
 * keeps its guard against a second credit for the same student and event.
 *
 * Runs on a database file of its own, so the migrations can be run in steps.
 */
class CategoryMigrationTest extends TestCase
{
    private const CONNECTION = 'category_migration';

    /** The two migrations of Task #116. */
    private const NEW = [
        '2026_11_10_100000_create_categories_table.php',
        '2026_11_10_100100_point_category_columns_at_the_list.php',
    ];

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'categories-').'.sqlite';
        touch($this->file);
        config(["database.connections.{$this->connection()}" => [
            'driver' => 'sqlite',
            'database' => $this->file,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    protected function tearDown(): void
    {
        DB::purge($this->connection());
        @unlink($this->file);

        parent::tearDown();
    }

    private function connection(): string
    {
        return self::CONNECTION;
    }

    private function db(): Connection
    {
        return DB::connection($this->connection());
    }

    /**
     * Every migration before the ones of Task #116.
     *
     * @return list<string>
     */
    private function before(): array
    {
        $files = glob(database_path('migrations/*.php'));
        sort($files);

        return array_values(array_filter($files, fn (string $file) => ! in_array(basename($file), self::NEW, true)));
    }

    private function migrate(array $options = []): void
    {
        $this->artisan('migrate', ['--database' => $this->connection(), '--force' => true, ...$options])->assertSuccessful();
    }

    /**
     * One record of each kind with a category, written the way the old code wrote them.
     *
     * @return array<string, int>
     */
    private function oldRecords(): array
    {
        $db = $this->db();
        $now = now();

        $user = $db->table('users')->insertGetId(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'x', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $student = $db->table('users')->insertGetId(['name' => 'Bea', 'email' => 'bea@example.test', 'password' => 'x', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $topic = $db->table('topics')->insertGetId(['title' => 'University', 'depth' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $db->table('topic_closure')->insert(['ancestor_id' => $topic, 'descendant_id' => $topic, 'distance' => 0]);
        $semester = $db->table('semesters')->insertGetId(['name' => 'Autumn', 'starts_at' => '2026-09-01', 'ends_at' => '2027-01-31', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $area = $db->table('subject_areas')->insertGetId(['title' => 'Chess', 'code' => 'CHESS', 'category' => 'sports', 'created_at' => $now, 'updated_at' => $now]);
        $tag = $db->table('topic_tags')->insertGetId(['name' => 'SQL', 'category' => 'scientific', 'created_at' => $now, 'updated_at' => $now]);
        $request = $db->table('topic_tag_requests')->insertGetId(['proposed_name' => 'Choir', 'category' => 'community', 'justification' => 'We sing.', 'requested_by_id' => $user, 'created_at' => $now, 'updated_at' => $now]);
        $event = $db->table('events')->insertGetId([
            'title' => 'SQL night', 'description' => 'Practice.', 'category' => 'scientific', 'topic_tag_id' => $tag,
            'created_by_id' => $user, 'owning_topic_id' => $topic, 'semester_id' => $semester, 'capacity' => 10,
            'application_type' => 'fcfs', 'points_value' => 5, 'event_starts_at' => $now, 'event_ends_at' => $now,
            'application_deadline' => $now, 'completion_deadline' => $now, 'status' => 'published',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $credit = $db->table('point_transactions')->insertGetId([
            'student_id' => $student, 'event_id' => $event, 'semester_id' => $semester, 'credited_by_id' => $user,
            'points' => 5, 'type' => 'credit', 'source' => 'manual', 'category' => 'scientific', 'created_at' => $now,
        ]);

        return compact('user', 'student', 'topic', 'semester', 'area', 'tag', 'request', 'event', 'credit');
    }

    public function test_the_upgrade_keeps_every_stored_category(): void
    {
        $this->migrate(['--path' => $this->before(), '--realpath' => true]);
        $ids = $this->oldRecords();

        $this->migrate();

        $db = $this->db();
        $this->assertSame(['academic', 'scientific', 'sports', 'community'], $db->table('categories')->orderBy('id')->pluck('slug')->all());
        $this->assertSame('sports', $db->table('subject_areas')->where('id', $ids['area'])->value('category'));
        $this->assertSame('scientific', $db->table('topic_tags')->where('id', $ids['tag'])->value('category'));
        $this->assertSame('community', $db->table('topic_tag_requests')->where('id', $ids['request'])->value('category'));
        $this->assertSame('scientific', $db->table('events')->where('id', $ids['event'])->value('category'));
        $this->assertSame('scientific', $db->table('point_transactions')->where('id', $ids['credit'])->value('category'));
        $this->assertSame(1, $db->table('point_transactions')->count());
    }

    public function test_the_ledger_still_refuses_a_second_credit_for_the_same_student_and_event(): void
    {
        $this->migrate(['--path' => $this->before(), '--realpath' => true]);
        $ids = $this->oldRecords();
        $this->migrate();

        $row = fn (string $type, int $points) => [
            'student_id' => $ids['student'], 'event_id' => $ids['event'], 'semester_id' => $ids['semester'],
            'credited_by_id' => $ids['user'], 'points' => $points, 'type' => $type, 'source' => 'manual',
            'category' => null, 'created_at' => now(),
        ];

        // A reversal of the credit is fine; a second credit is not.
        $this->db()->table('point_transactions')->insert([...$row('reversal', -5), 'reversal_of_id' => $ids['credit']]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->db()->table('point_transactions')->insert($row('credit', 5));
    }

    public function test_the_columns_accept_only_categories_of_the_list_and_events_may_have_none(): void
    {
        $this->migrate(['--path' => $this->before(), '--realpath' => true]);
        $ids = $this->oldRecords();
        $this->migrate();

        $db = $this->db();
        $db->table('categories')->insert(['slug' => 'robotics', 'name' => 'Robotics', 'color' => 'teal', 'created_at' => now(), 'updated_at' => now()]);

        $db->table('topic_tags')->where('id', $ids['tag'])->update(['category' => 'robotics']);
        $db->table('events')->where('id', $ids['event'])->update(['category' => null]);
        $this->assertSame('robotics', $db->table('topic_tags')->where('id', $ids['tag'])->value('category'));
        $this->assertNull($db->table('events')->where('id', $ids['event'])->value('category'));

        // A tag still needs a category, and only one of the list.
        try {
            $db->table('topic_tags')->where('id', $ids['tag'])->update(['category' => null]);
            $this->fail('A tag without a category was accepted.');
        } catch (QueryException) {
        }

        try {
            $db->table('topic_tags')->where('id', $ids['tag'])->update(['category' => 'nothing_like_it']);
            $this->fail('A category that is not on the list was accepted.');
        } catch (QueryException) {
        }

        // A category that something uses cannot be removed.
        $this->expectException(QueryException::class);
        $db->table('categories')->where('slug', 'robotics')->delete();
    }

    public function test_the_other_fixed_value_columns_still_refuse_unknown_values(): void
    {
        $this->migrate(['--path' => $this->before(), '--realpath' => true]);
        $ids = $this->oldRecords();
        $this->migrate();

        // SQLite copies a table to change a column; the copy must keep these checks (and so must
        // the copy made on the way back).
        $refused = function (): array {
            $db = $this->db();
            $tries = [
                'events.status' => fn () => $db->table('events')->update(['status' => 'archived']),
                'events.application_type' => fn () => $db->table('events')->update(['application_type' => 'lottery']),
                'point_transactions.type' => fn () => $db->table('point_transactions')->update(['type' => 'bonus']),
                'topic_tag_requests.status' => fn () => $db->table('topic_tag_requests')->update(['status' => 'maybe']),
            ];

            return collect($tries)->map(function (callable $try) {
                try {
                    $try();

                    return false;
                } catch (QueryException) {
                    return true;
                }
            })->all();
        };

        $all = ['events.status' => true, 'events.application_type' => true, 'point_transactions.type' => true, 'topic_tag_requests.status' => true];
        $this->assertSame($all, $refused());

        $this->artisan('migrate:rollback', ['--database' => $this->connection(), '--step' => 2, '--force' => true])->assertSuccessful();
        $this->assertSame($all, $refused());
        $this->assertSame('published', $this->db()->table('events')->where('id', $ids['event'])->value('status'));
    }

    public function test_going_back_restores_the_fixed_columns_and_keeps_the_data(): void
    {
        $this->migrate(['--path' => $this->before(), '--realpath' => true]);
        $ids = $this->oldRecords();
        $this->migrate();

        $this->artisan('migrate:rollback', ['--database' => $this->connection(), '--step' => 2, '--force' => true])->assertSuccessful();

        $db = $this->db();
        $this->assertFalse($db->getSchemaBuilder()->hasTable('categories'));
        $this->assertSame('scientific', $db->table('point_transactions')->where('id', $ids['credit'])->value('category'));
        $this->assertSame('sports', $db->table('subject_areas')->where('id', $ids['area'])->value('category'));

        // The fixed list is back.
        $this->expectException(QueryException::class);
        $db->table('topic_tags')->where('id', $ids['tag'])->update(['category' => 'robotics']);
    }

    public function test_going_back_is_refused_while_it_would_lose_a_category(): void
    {
        $this->migrate(['--path' => $this->before(), '--realpath' => true]);
        $ids = $this->oldRecords();
        $this->migrate();

        // An event without a category, which the fixed columns cannot hold.
        $this->db()->table('events')->where('id', $ids['event'])->update(['category' => null]);

        try {
            $this->artisan('migrate:rollback', ['--database' => $this->connection(), '--step' => 1, '--force' => true]);
            $this->fail('Going back was not refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('events', $e->getMessage());
        }

        // Nothing changed: the list and the empty category are still there.
        $this->assertTrue($this->db()->getSchemaBuilder()->hasTable('categories'));
        $this->assertNull($this->db()->table('events')->where('id', $ids['event'])->value('category'));
    }
}
