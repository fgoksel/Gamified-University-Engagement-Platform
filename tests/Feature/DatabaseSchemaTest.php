<?php

namespace Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Checks the Sprint 1 tables against Technical Specification section 6.2.
 */
class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_faculties_table_matches_spec(): void
    {
        $this->assertTrue(Schema::hasColumns('faculties', [
            'id', 'name', 'code', 'created_at', 'updated_at',
        ]));
    }

    public function test_users_table_matches_spec(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'id', 'name', 'email', 'email_verified_at', 'password', 'avatar',
            'status', 'must_change_password', 'neptun_code', 'major',
            'year_of_study', 'appearance', 'faculty_id', 'activation_token',
            'activation_token_expires_at', 'remember_token', 'created_at', 'updated_at',
        ]));
    }

    public function test_semesters_table_matches_spec(): void
    {
        $this->assertTrue(Schema::hasColumns('semesters', [
            'id', 'name', 'starts_at', 'ends_at', 'status', 'created_at', 'updated_at',
        ]));
    }

    public function test_subject_areas_table_matches_spec(): void
    {
        $this->assertTrue(Schema::hasColumns('subject_areas', [
            'id', 'title', 'code', 'description', 'category', 'faculty_id',
            'is_active', 'created_at', 'updated_at',
        ]));
    }

    public function test_topic_tags_table_matches_spec(): void
    {
        $this->assertTrue(Schema::hasColumns('topic_tags', [
            'id', 'name', 'category', 'description', 'is_active', 'created_at', 'updated_at',
        ]));
    }

    public function test_topic_tag_requests_table_matches_spec(): void
    {
        $this->assertTrue(Schema::hasColumns('topic_tag_requests', [
            'id', 'proposed_name', 'category', 'justification', 'status',
            'requested_by_id', 'resolved_by_id', 'rejection_reason',
            'resolved_topic_tag_id', 'created_at', 'updated_at',
        ]));
    }

    public function test_courses_table_matches_spec(): void
    {
        $this->assertTrue(Schema::hasColumns('courses', [
            'id', 'faculty_id', 'code', 'name', 'created_at', 'updated_at',
        ]));
    }

    // The legacy faculty-tree tables are kept as they were (the upgrade only copies from them).
    public function test_tree_units_table_matches_design(): void
    {
        $this->assertTrue(Schema::hasColumns('tree_units', [
            'id', 'parent_id', 'faculty_id', 'kind', 'title', 'course_id', 'subject_area_id',
            'path', 'created_by_id', 'created_at', 'updated_at',
        ]));
    }

    public function test_unit_memberships_table_matches_design(): void
    {
        $this->assertTrue(Schema::hasColumns('unit_memberships', [
            'id', 'unit_id', 'user_id', 'role', 'added_by_id', 'permissions',
            'semester_id', 'manual', 'started_at', 'ended_at', 'ended_reason',
            'created_at', 'updated_at',
        ]));
    }

    public function test_topic_tables_match_the_design(): void
    {
        $this->assertTrue(Schema::hasColumns('topics', [
            'id', 'parent_id', 'title', 'code', 'description', 'depth', 'created_by_id',
            'legacy_tree_unit_id', 'course_id', 'subject_area_id', 'archived_at', 'archived_by_id', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('topic_closure', ['ancestor_id', 'descendant_id', 'distance']));
        $this->assertTrue(Schema::hasColumns('role_definitions', [
            'id', 'name', 'description', 'topic_id', 'capabilities', 'delegable', 'created_by_id',
            'archived_at', 'legacy_key', 'version', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('role_assignments', [
            'id', 'topic_id', 'user_id', 'role_definition_id', 'granted_by_id', 'granted_by_assignment_id',
            'authority_snapshot', 'started_at', 'ended_at', 'ended_reason', 'ended_by_id',
            'replaces_assignment_id', 'replaced_by_assignment_id', 'version', 'semester_id', 'manual',
            'legacy_permissions',
        ]));
        $this->assertTrue(Schema::hasColumns('topic_audit_events', [
            'id', 'actor_id', 'action', 'topic_id', 'subject_type', 'subject_id', 'before', 'after', 'created_at',
        ]));
    }

    public function test_the_topic_title_has_no_length_coupled_path_so_depth_is_not_column_limited(): void
    {
        $this->assertFalse(Schema::hasColumn('topics', 'path'));
    }

    public function test_an_active_role_can_exist_only_once_per_person_and_topic_but_history_is_unlimited(): void
    {
        $now = now();
        $user = DB::table('users')->insertGetId(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x']);
        $topic = DB::table('topics')->insertGetId(['title' => 'T', 'created_at' => $now, 'updated_at' => $now]);
        $role = DB::table('role_definitions')->insertGetId(['name' => 'R', 'capabilities' => '["topic.view"]', 'delegable' => '[]', 'created_at' => $now, 'updated_at' => $now]);
        $row = ['topic_id' => $topic, 'user_id' => $user, 'role_definition_id' => $role, 'started_at' => $now, 'created_at' => $now, 'updated_at' => $now];

        DB::table('role_assignments')->insert($row);
        // Ended history may repeat freely.
        DB::table('role_assignments')->insert([...$row, 'ended_at' => $now]);
        DB::table('role_assignments')->insert([...$row, 'ended_at' => $now]);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('role_assignments')->insert($row);
    }

    public function test_new_users_default_to_invited_and_must_change_password(): void
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'New Teacher',
            'email' => 'teacher@example.com',
            'password' => 'x',
        ]);

        $user = DB::table('users')->find($id);

        $this->assertSame('invited', $user->status);
        $this->assertEquals(1, $user->must_change_password);
        $this->assertSame('light', $user->appearance);
    }
}
