<?php

namespace Tests\Feature\FacultyTree;

use App\Enums\TreeUnitKind;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\TreeUnit;
use Illuminate\Database\QueryException;

/**
 * The tree itself: paths, depth, and the faculty's courses in it.
 */
class TreeStructureTest extends FacultyTreeTestCase
{
    public function test_each_unit_stores_its_path_from_the_root(): void
    {
        $this->assertSame("/{$this->root->id}/", $this->root->path);
        $this->assertSame("/{$this->root->id}/{$this->database->id}/", $this->database->path);
        $this->assertSame("/{$this->root->id}/{$this->database->id}/{$this->sql->id}/", $this->sql->path);
    }

    public function test_subtopics_can_go_to_any_depth_and_know_their_course(): void
    {
        $joins = $this->tree->addSubtopic($this->teacher, $this->sql, 'Joins');
        $outerJoins = $this->tree->addSubtopic($this->teacher, $joins, 'Outer joins');

        $this->assertTrue($outerJoins->isWithin($this->database));
        $this->assertFalse($outerJoins->isWithin($this->networks));
        $this->assertSame($this->database->id, $outerJoins->courseUnit()->id);
        $this->assertNull($this->root->courseUnit());
        $this->assertSame(4, TreeUnit::within($this->database)->count());
    }

    public function test_every_course_of_the_faculty_appears_in_its_tree(): void
    {
        $this->assertEqualsCanonicalizing(
            ['Database', 'Networks'],
            $this->root->children()->pluck('title')->all(),
        );
        $this->assertSame('Faculty of Informatics', $this->root->title);
        $this->assertSame($this->faculty->id, $this->root->faculty_id);
    }

    public function test_a_course_added_to_the_faculty_later_appears_in_the_tree_by_itself(): void
    {
        $course = $this->course('Operating Systems');

        $unit = $course->courseUnit;
        $this->assertNotNull($unit);
        $this->assertSame($this->root->id, $unit->parent_id);
        $this->assertSame(TreeUnitKind::Course, $unit->kind);
        $this->assertSame('Operating Systems', $unit->title);
    }

    public function test_courses_created_before_the_tree_appear_when_the_tree_is_created(): void
    {
        $faculty = Faculty::factory()->create();
        Course::factory()->for($faculty)->count(2)->create();

        $root = $this->tree->createTree($this->admin, $faculty, $this->teacherAccount());

        $this->assertSame(2, $root->children()->where('kind', TreeUnitKind::Course)->count());
    }

    public function test_renaming_the_faculty_or_a_course_renames_it_in_the_tree(): void
    {
        $this->faculty->update(['name' => 'Faculty of Computing']);
        $this->database->course->update(['name' => 'Database Systems']);

        $this->assertSame('Faculty of Computing', $this->root->fresh()->title);
        $this->assertSame('Database Systems', $this->database->fresh()->title);
    }

    public function test_a_faculty_has_only_one_tree(): void
    {
        $this->assertRejected(fn () => $this->tree->createTree($this->admin, $this->faculty, $this->teacherAccount()), 'faculty_id');

        $this->expectException(QueryException::class);
        TreeUnit::create(['kind' => TreeUnitKind::Root, 'title' => 'Copy', 'faculty_id' => $this->faculty->id]);
    }
}
