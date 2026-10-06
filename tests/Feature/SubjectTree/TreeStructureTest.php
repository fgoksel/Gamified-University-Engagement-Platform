<?php

namespace Tests\Feature\SubjectTree;

use App\Models\Course;
use App\Models\TreeUnit;

/**
 * The tree itself: paths, depth and the course of each subject.
 */
class TreeStructureTest extends SubjectTreeTestCase
{
    public function test_each_unit_stores_its_path_from_the_root(): void
    {
        $this->assertSame("/{$this->root->id}/", $this->root->path);
        $this->assertSame("/{$this->root->id}/{$this->database->id}/", $this->database->path);
        $this->assertSame("/{$this->root->id}/{$this->database->id}/{$this->sql->id}/", $this->sql->path);
    }

    public function test_subtopics_can_go_to_any_depth_and_know_their_subject(): void
    {
        $joins = $this->tree->addSubtopic($this->teacher, $this->sql, 'Joins');
        $outerJoins = $this->tree->addSubtopic($this->teacher, $joins, 'Outer joins');

        $this->assertTrue($outerJoins->isWithin($this->database));
        $this->assertFalse($outerJoins->isWithin($this->networks));
        $this->assertSame($this->database->id, $outerJoins->subjectUnit()->id);
        $this->assertNull($this->root->subjectUnit());
        $this->assertSame(4, TreeUnit::within($this->database)->count());
    }

    public function test_a_course_is_the_subject_of_only_one_tree(): void
    {
        $otherRoot = $this->tree->createTree($this->admin, 'Faculty of Engineering', $this->teacherAccount());

        $this->assertRejected(fn () => $this->tree->addSubject($this->admin, $otherRoot, $this->database->course), 'course_id');
        $this->assertSame('Database', $this->database->title);
        $this->assertInstanceOf(Course::class, $this->database->course);
    }
}
