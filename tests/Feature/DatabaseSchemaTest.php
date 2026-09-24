<?php

namespace Tests\Feature;

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
