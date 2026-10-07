<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The generic topic tree and its configurable roles.
     *
     * Purely additive: the legacy tree_units and unit_memberships tables are
     * left untouched, so a rollback loses nothing from before the upgrade.
     * The next migration copies the legacy records into these tables.
     */
    public function up(): void
    {
        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('topics')->restrictOnDelete();
            $table->string('title');
            $table->string('code', 50)->nullable();
            $table->text('description')->nullable();
            // 0 for a root. Kept for the explicit depth limit and for sorting.
            $table->unsignedSmallInteger('depth')->default(0);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            // Legacy links, filled only by the upgrade: they never decide behaviour.
            $table->unsignedBigInteger('legacy_tree_unit_id')->nullable()->unique();
            $table->foreignId('course_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('subject_area_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['parent_id', 'title']);
        });

        // Every topic with every one of its ancestors (itself at depth 0).
        // Replaces a path string, so nesting depth is not limited by a column length.
        Schema::create('topic_closure', function (Blueprint $table) {
            $table->foreignId('ancestor_id')->constrained('topics')->cascadeOnDelete();
            $table->foreignId('descendant_id')->constrained('topics')->cascadeOnDelete();
            $table->unsignedSmallInteger('distance');
            $table->primary(['ancestor_id', 'descendant_id']);
            $table->index('descendant_id');
        });

        Schema::create('role_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            // Null = global (System Admin only). Otherwise usable at this topic and below.
            $table->foreignId('topic_id')->nullable()->constrained('topics')->restrictOnDelete();
            // What the holder can do, and the subset the holder may pass on to others.
            $table->json('capabilities');
            $table->json('delegable');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            // Set by the upgrade only: which legacy role label this definition came from.
            $table->string('legacy_key', 30)->nullable()->unique();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['topic_id', 'archived_at']);
        });

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained('topics')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('role_definition_id')->constrained('role_definitions')->restrictOnDelete();
            // Provenance. History only: nothing here must stay active for the assignment to work.
            $table->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('granted_by_assignment_id')->nullable()->index();
            $table->json('authority_snapshot')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason', 500)->nullable();
            $table->foreignId('ended_by_id')->nullable()->constrained('users')->nullOnDelete();
            // Handover linkage: the assignment this one replaced / was replaced by.
            $table->unsignedBigInteger('replaces_assignment_id')->nullable()->unique();
            $table->unsignedBigInteger('replaced_by_assignment_id')->nullable()->unique();
            // Bumped on every change; confirmations carry it so stale or replayed submissions fail.
            $table->unsignedInteger('version')->default(1);
            // Legacy metadata kept from unit_memberships.
            $table->foreignId('semester_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('manual')->default(false);
            $table->json('legacy_permissions')->nullable();
            $table->timestamps();
            $table->index(['topic_id', 'ended_at']);
            $table->index(['user_id', 'ended_at']);
            // Only active rows get a value, so an active (person, role, topic) is unique
            // while ended history is unlimited. NULLs never collide in a unique index.
            $table->unsignedBigInteger('active_user_id')->nullable()
                ->virtualAs('case when ended_at is null then user_id end');
            $table->unsignedBigInteger('active_role_id')->nullable()
                ->virtualAs('case when ended_at is null then role_definition_id end');
            $table->unsignedBigInteger('active_topic_id')->nullable()
                ->virtualAs('case when ended_at is null then topic_id end');
            $table->unique(['active_user_id', 'active_role_id', 'active_topic_id'], 'role_assignments_active_unique');
        });

        Schema::create('topic_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60)->index();
            $table->unsignedBigInteger('topic_id')->nullable()->index();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_audit_events');
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('role_definitions');
        Schema::dropIfExists('topic_closure');
        Schema::dropIfExists('topics');
    }
};
