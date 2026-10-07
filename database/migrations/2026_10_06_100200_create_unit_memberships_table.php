<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A person in a role on a tree unit. Rows are never deleted: a role is
     * ended with ended_at and ended_reason, so the history stays complete.
     */
    public function up(): void
    {
        Schema::create('unit_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('tree_units')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('role', ['dean', 'teacher', 'co_teacher', 'tutor', 'student']);
            $table->foreignId('added_by_id')->nullable()->constrained('users')->nullOnDelete();
            // Tutors only: create_events, select_applicants, credit_points.
            $table->json('permissions')->nullable();
            $table->foreignId('semester_id')->nullable()->constrained()->nullOnDelete();
            // True when a teacher or co-teacher added the student by hand.
            $table->boolean('manual')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason')->nullable();
            $table->timestamps();

            $table->index(['unit_id', 'ended_at']);
            $table->index(['user_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_memberships');
    }
};
