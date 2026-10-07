<?php

namespace Tests\Feature\Topics;

use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Models\User;
use App\Services\TopicAccess;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\TopicDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopicDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_normal_seeder_leaves_topics_and_roles_blank(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame([0, 0, 0], [Topic::count(), RoleDefinition::count(), RoleAssignment::count()]);
    }

    public function test_the_demo_seeder_builds_a_tree_roles_and_delegated_assignments_and_is_repeatable(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(TopicDemoSeeder::class);
        $topics = Topic::count();
        $assignments = RoleAssignment::count();

        $this->seed(TopicDemoSeeder::class);

        $this->assertSame($topics, Topic::count());
        $this->assertSame($assignments, RoleAssignment::count());
        $this->assertSame(2, Topic::whereNull('parent_id')->count());
        $this->assertGreaterThanOrEqual(6, Topic::max('depth') + 3);

        $access = app(TopicAccess::class);
        $reader = User::where('email', 'demo.reader@example.test')->first();
        $sql = Topic::where('title', 'SQL')->first();
        $this->assertTrue($access->canView($reader, $sql));
        $this->assertFalse($access->canView($reader, Topic::where('title', 'Pécs')->first()));

        $delegate = RoleAssignment::where('user_id', User::where('email', 'demo.delegate@example.test')->value('id'))->first();
        $this->assertSame('Dora Lead', $delegate->grantedBy->name);
        $this->assertSame('assignment', $delegate->authority_snapshot['via']);

        $this->assertSame(['topic.view'], RoleDefinition::where('name', 'Admin')->first()->capabilityList());
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->app->detectEnvironment(fn () => 'production');

        (new TopicDemoSeeder)->setContainer($this->app)->run();

        $this->assertSame(0, Topic::count());
    }
}
