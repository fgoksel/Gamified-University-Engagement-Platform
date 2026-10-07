<?php

namespace App\Models;

use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A Neptun course of one faculty. Courses are created on the admin's
 * Faculties page or by the enrolment import.
 *
 * A course is not a topic. Only records upgraded from the previous faculty
 * tree link a course to a topic (topics.course_id); the link is used by the
 * enrolment import and never decides a topic's name or position.
 */
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    protected $fillable = [
        'faculty_id',
        'code',
        'name',
    ];

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    /**
     * The topic this course was linked to by the upgrade from the old tree, if any.
     */
    public function legacyTopic(): HasOne
    {
        return $this->hasOne(Topic::class);
    }
}
