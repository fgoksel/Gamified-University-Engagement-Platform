<?php

namespace App\Models;

use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A Neptun course. In the role tree it is a subject (TreeUnit of kind "subject").
 */
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
    ];

    /**
     * The subject unit this course is in, if the admin has added it to a tree.
     */
    public function subjectUnit(): HasOne
    {
        return $this->hasOne(TreeUnit::class);
    }
}
