<?php

namespace App\Models;

use App\Services\TreeService;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A Neptun course of one faculty. Courses are created only on the admin's
 * Faculties page. Every course appears in its faculty's tree as a TreeUnit
 * of kind "course", with no extra step.
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

    protected static function booted(): void
    {
        // Live link: a new course shows up in its faculty's tree at once.
        static::created(fn (Course $course) => app(TreeService::class)->placeCourse($course));

        static::updated(function (Course $course) {
            if ($course->wasChanged('name')) {
                $course->courseUnit?->update(['title' => $course->name]);
            }
        });
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    /**
     * The tree unit of this course, once its faculty has a tree.
     */
    public function courseUnit(): HasOne
    {
        return $this->hasOne(TreeUnit::class);
    }
}
