<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The units of the role tree: one root per dean, subjects (Neptun courses)
     * below it, and subtopics of any depth below a subject.
     */
    public function up(): void
    {
        Schema::create('tree_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('tree_units')->restrictOnDelete();
            $table->enum('kind', ['root', 'subject', 'subtopic'])->index();
            $table->string('title');
            // A Neptun course is the subject of exactly one tree.
            $table->foreignId('course_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('subject_area_id')->nullable()->constrained()->nullOnDelete();
            // Ids from the root down, e.g. "/1/4/9/". "Everything below" is a prefix match.
            $table->string('path')->default('')->index();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tree_units');
    }
};
