<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Events attached to the topic tree, and the students' applications.
     *
     * An event is managed from exactly one owning topic and announced to one
     * audience topic at or above it (or to nobody, when invitation-only).
     * Nothing here is ever removed together with an event that has history:
     * applications restrict the delete, so an event that has applicants can
     * never be deleted. An event without any can be deleted for good.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description');
            // Copied from the tag when the event is saved; the ledger snapshots it again when crediting.
            $table->enum('category', ['academic', 'scientific', 'sports', 'community']);
            // Required at publication, so a draft may not have one yet.
            $table->foreignId('topic_tag_id')->nullable()->constrained('topic_tags')->restrictOnDelete();
            // Historical attribution only: it gives no authority over the event.
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            // Where management authority comes from. Fixed at publication.
            $table->foreignId('owning_topic_id')->constrained('topics')->restrictOnDelete();
            // Who can see and apply: this topic or below. Null = invitation only.
            $table->foreignId('audience_topic_id')->nullable()->constrained('topics')->restrictOnDelete();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('capacity');
            $table->enum('application_type', ['fcfs', 'teacher_select']);
            $table->unsignedInteger('points_value');
            $table->dateTime('event_starts_at');
            $table->dateTime('event_ends_at');
            $table->dateTime('application_deadline');
            $table->dateTime('completion_deadline');
            // The lifecycle. On hold: kept, hidden from students, no applications or points until
            // restored. Hidden (surprise events) and the application states are separate concepts.
            $table->enum('status', ['draft', 'published', 'on_hold', 'completed', 'cancelled'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            // Surprise events: only staff see them until revealed.
            $table->boolean('is_hidden')->default(false);
            $table->dateTime('reveal_at')->nullable();
            $table->boolean('qr_enabled')->default(false);
            $table->timestamps();
            $table->index(['owning_topic_id', 'status']);
            $table->index(['audience_topic_id', 'status']);
            $table->index('created_by_id');
            $table->index('semester_id');
            $table->index('event_starts_at');
            $table->index('application_deadline');
        });

        Schema::create('event_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['pending', 'accepted', 'waitlisted', 'rejected', 'withdrawn'])->default('pending');
            // Moves to the end of the queue again when a withdrawn student re-applies.
            $table->timestamp('applied_at')->useCurrent();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // Set when the shared ledger credit is written; never a separate balance.
            $table->timestamp('scored_at')->nullable();
            $table->timestamps();
            // One record per student and event: re-applying reuses it.
            $table->unique(['event_id', 'student_id']);
            $table->index(['event_id', 'status', 'applied_at']);
            $table->index('student_id');
        });

        // The history of an application: every state it has been in.
        Schema::create('event_application_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_application_id')->constrained('event_applications')->restrictOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('event_application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_application_transitions');
        Schema::dropIfExists('event_applications');
        Schema::dropIfExists('events');
    }
};
