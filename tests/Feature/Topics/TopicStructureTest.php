<?php

namespace Tests\Feature\Topics;

use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Topic;
use App\Services\TopicService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TopicStructureTest extends TopicTestCase
{
    public function test_a_fresh_install_has_no_topics_and_no_roles(): void
    {
        $this->assertSame(0, Topic::count());
        $this->assertSame(0, RoleDefinition::count());
        $this->assertSame(0, RoleAssignment::count());
    }

    public function test_a_root_is_just_a_topic_without_a_parent_and_there_can_be_several(): void
    {
        $hungary = $this->topic('Hungary');
        $programming = $this->topic('Programming');

        $this->assertNull($hungary->parent_id);
        $this->assertNull($programming->parent_id);
        $this->assertSame(0, $hungary->depth);
        $this->assertSame(2, Topic::whereNull('parent_id')->count());
    }

    public function test_topics_nest_to_any_business_depth_up_to_the_explicit_limit(): void
    {
        config(['topics.max_depth' => 40]);

        $topic = $this->topic('Level 0');
        for ($level = 1; $level < 40; $level++) {
            $topic = $this->topic("Level {$level}", $topic);
        }

        $this->assertSame(39, $topic->depth);
        $this->assertSame(40, count($topic->lineageIds()));

        $this->expectException(ValidationException::class);
        $this->topic('Too deep', $topic);
    }

    public function test_the_closure_table_lists_every_ancestor_with_its_distance(): void
    {
        $a = $this->topic('A');
        $b = $this->topic('B', $a);
        $c = $this->topic('C', $b);

        $this->assertSame([$c->id, $b->id, $a->id], $c->lineageIds());
        $this->assertSame(
            [$a->id, $b->id, $c->id],
            Topic::withinBranch($a)->orderBy('depth')->pluck('id')->all(),
        );
        $this->assertSame([$b->id, $c->id], Topic::below($a)->orderBy('depth')->pluck('id')->all());
    }

    public function test_names_may_repeat_in_different_branches(): void
    {
        $one = $this->topic('Database', $this->topic('Pécs'));
        $two = $this->topic('Database', $this->topic('Budapest'));

        $this->assertNotSame($one->id, $two->id);
    }

    public function test_title_is_required_and_trimmed_and_code_and_description_are_optional(): void
    {
        $topic = $this->topic('  Spaced  ', null, null, ['code' => '', 'description' => '   ']);

        $this->assertSame('Spaced', $topic->title);
        $this->assertNull($topic->code);
        $this->assertNull($topic->description);

        $this->expectException(ValidationException::class);
        app(TopicService::class)->create($this->admin, null, ['title' => '   ']);
    }

    public function test_only_a_system_admin_starts_a_new_root(): void
    {
        $teacher = $this->makeUser();

        $this->expectException(AuthorizationException::class);
        app(TopicService::class)->create($teacher, null, ['title' => 'My own root']);
    }

    public function test_a_topic_cannot_become_its_own_ancestor_even_through_the_model(): void
    {
        $a = $this->topic('A');
        $b = $this->topic('B', $a);

        $this->expectException(\LogicException::class);
        $a->update(['parent_id' => $b->id]);
    }

    public function test_the_service_refuses_to_move_a_topic_into_itself_or_a_descendant(): void
    {
        $a = $this->topic('A');
        $b = $this->topic('B', $a);

        foreach ([$a, $b] as $destination) {
            try {
                app(TopicService::class)->move($this->admin, $a, $destination);
                $this->fail('The move should have been refused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('destination', $exception->errors());
            }
        }

        $this->assertNull($a->fresh()->parent_id);
    }

    public function test_moving_a_subtree_rewrites_ancestry_and_depth_in_one_step(): void
    {
        $x = $this->topic('X');
        $y = $this->topic('Y');
        $a = $this->topic('A', $x);
        $b = $this->topic('B', $a);

        app(TopicService::class)->move($this->admin, $a, $y);

        $this->assertSame($y->id, $a->fresh()->parent_id);
        $this->assertSame([$b->id, $a->id, $y->id], $b->fresh()->lineageIds());
        $this->assertSame(1, $a->fresh()->depth);
        $this->assertSame(2, $b->fresh()->depth);
        $this->assertSame(0, DB::table('topic_closure')->where('ancestor_id', $x->id)->where('descendant_id', $b->id)->count());
    }
}
