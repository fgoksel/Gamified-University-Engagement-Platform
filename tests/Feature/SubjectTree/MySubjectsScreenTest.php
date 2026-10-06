<?php

namespace Tests\Feature\SubjectTree;

use App\Enums\TreeRole;
use App\Enums\TutorPermission;
use App\Models\UnitMembership;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * "My subjects" (build step 3): deans, teachers and co-teachers see their
 * units, add people, set tutor rights and end roles from the Vue app.
 */
class MySubjectsScreenTest extends SubjectTreeTestCase
{
    public function test_a_teacher_sees_the_subjects_they_are_on(): void
    {
        $this->actingAs($this->teacher)
            ->get('/my-subjects')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tree/Index')
                ->has('units', 1)
                ->where('units.0.title', 'Database')
                ->where('units.0.role', 'Teacher')
                ->where('units.0.unitsBelow', 1));
    }

    public function test_the_dean_sees_their_tree(): void
    {
        $this->actingAs($this->dean)
            ->get('/my-subjects')
            ->assertInertia(fn (Assert $page) => $page
                ->where('units.0.title', 'Faculty of Informatics')
                ->where('units.0.role', 'Dean'));
    }

    public function test_the_unit_page_shows_people_subtopics_and_allowed_actions(): void
    {
        $this->add($this->teacher, $this->database, TreeRole::Student);

        $this->actingAs($this->teacher)
            ->get("/my-subjects/{$this->database->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tree/Show')
                ->where('unit.title', 'Database')
                ->where('unit.myRole', 'Teacher')
                ->has('breadcrumbs', 0)
                ->has('children', 1)
                ->where('children.0.title', 'SQL')
                ->has('members', 2)
                ->where('members.0.isMe', true)
                ->where('members.0.canEnd', false)
                ->where('members.1.role', 'student')
                ->where('members.1.canEnd', true)
                ->where('addableRoles', [
                    ['value' => 'co_teacher', 'label' => 'Co-teacher'],
                    ['value' => 'tutor', 'label' => 'Student tutor'],
                    ['value' => 'student', 'label' => 'Student'],
                ])
                ->where('canAddSubtopic', true));
    }

    public function test_the_dean_can_add_only_teachers_on_a_subject(): void
    {
        $this->actingAs($this->dean)
            ->get("/my-subjects/{$this->networks->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('addableRoles', [['value' => 'teacher', 'label' => 'Teacher']])
                ->where('canAddSubtopic', false)
                ->has('breadcrumbs', 1));
    }

    public function test_a_teacher_cannot_open_a_subject_outside_their_branch(): void
    {
        $this->actingAs($this->teacher)
            ->get("/my-subjects/{$this->networks->id}")
            ->assertRedirect('/')
            ->assertSessionHas('error');
    }

    public function test_the_search_finds_only_people_who_can_be_added(): void
    {
        $anna = $this->studentAccount();
        $anna->update(['name' => 'Anna Kiss', 'neptun_code' => 'ABC123']);
        $alreadyIn = $this->studentAccount();
        $alreadyIn->update(['name' => 'Anna Nagy']);
        $this->add($this->teacher, $this->database, TreeRole::Student, $alreadyIn);
        $this->teacherAccount()->update(['name' => 'Anna Teacher']);

        $this->actingAs($this->teacher)
            ->getJson("/my-subjects/{$this->database->id}/candidates?role=student&q=anna")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Anna Kiss');

        $this->actingAs($this->teacher)
            ->getJson("/my-subjects/{$this->database->id}/candidates?role=student&q=abc1")
            ->assertJsonPath('0.neptunCode', 'ABC123');

        // A student of the subject can still be found when making a tutor (rule 5).
        $this->actingAs($this->teacher)
            ->getJson("/my-subjects/{$this->database->id}/candidates?role=tutor&q=anna")
            ->assertJsonCount(2);
    }

    public function test_the_search_is_refused_for_roles_the_user_cannot_give(): void
    {
        $this->actingAs($this->teacher)
            ->getJson("/my-subjects/{$this->database->id}/candidates?role=teacher&q=an")
            ->assertForbidden();
    }

    public function test_a_teacher_adds_a_student_by_hand(): void
    {
        $student = $this->studentAccount();

        $this->actingAs($this->teacher)
            ->post("/my-subjects/{$this->database->id}/members", ['user_id' => $student->id, 'role' => 'student'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $record = UnitMembership::where('user_id', $student->id)->sole();
        $this->assertTrue($record->manual);
        $this->assertSame($this->teacher->id, $record->added_by_id);
    }

    public function test_a_teacher_adds_a_tutor_with_rights(): void
    {
        $student = $this->studentAccount();

        $this->actingAs($this->teacher)
            ->post("/my-subjects/{$this->sql->id}/members", [
                'user_id' => $student->id,
                'role' => 'tutor',
                'permissions' => ['create_events'],
            ]);

        $record = UnitMembership::where('user_id', $student->id)->sole();
        $this->assertSame(TreeRole::Tutor, $record->role);
        $this->assertTrue($record->hasPermission(TutorPermission::CreateEvents));
    }

    public function test_rule_errors_come_back_to_the_form(): void
    {
        $this->actingAs($this->teacher)
            ->from("/my-subjects/{$this->database->id}")
            ->post("/my-subjects/{$this->database->id}/members", ['user_id' => $this->teacherAccount()->id, 'role' => 'student'])
            ->assertRedirect("/my-subjects/{$this->database->id}")
            ->assertSessionHasErrors('user_id');
    }

    public function test_a_teacher_cannot_add_a_role_they_may_not_give(): void
    {
        $this->actingAs($this->teacher)
            ->post("/my-subjects/{$this->database->id}/members", ['user_id' => $this->teacherAccount()->id, 'role' => 'teacher'])
            ->assertRedirect('/')
            ->assertSessionHas('error');

        $this->assertSame(1, $this->database->memberships()->count());
    }

    public function test_a_teacher_sets_tutor_rights(): void
    {
        $tutor = $this->add($this->teacher, $this->database, TreeRole::Tutor);

        $this->actingAs($this->teacher)
            ->put("/my-subjects/members/{$tutor->id}/permissions", ['permissions' => ['select_applicants']])
            ->assertSessionHas('success');

        $this->assertSame(['select_applicants'], $tutor->fresh()->permissions);

        $this->actingAs($this->teacher)
            ->put("/my-subjects/members/{$tutor->id}/permissions", ['permissions' => []]);

        $this->assertSame([], $tutor->fresh()->permissions);
    }

    public function test_a_teacher_ends_a_role_with_a_reason(): void
    {
        $student = $this->add($this->teacher, $this->database, TreeRole::Student);

        $this->actingAs($this->teacher)
            ->post("/my-subjects/members/{$student->id}/end", ['ended_reason' => ''])
            ->assertSessionHasErrors('ended_reason');

        $this->actingAs($this->teacher)
            ->post("/my-subjects/members/{$student->id}/end", ['ended_reason' => 'Left the course'])
            ->assertSessionHas('success');

        $this->assertSame('Left the course', $student->fresh()->ended_reason);

        $this->actingAs($this->teacher)
            ->get("/my-subjects/{$this->database->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('members', 1)
                ->has('history', 1)
                ->where('history.0.endedReason', 'Left the course'));
    }

    public function test_a_teacher_adds_a_subtopic(): void
    {
        $this->actingAs($this->teacher)
            ->post("/my-subjects/{$this->sql->id}/subtopics", ['title' => 'Joins'])
            ->assertSessionHas('success');

        $this->assertSame('Joins', $this->sql->children()->sole()->title);
    }

    public function test_students_cannot_open_my_subjects(): void
    {
        $tutor = $this->add($this->teacher, $this->database, TreeRole::Tutor)->user;

        $this->actingAs($tutor)->get('/my-subjects')->assertRedirect('/');
    }
}
