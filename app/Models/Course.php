<?php

namespace App\Models;

use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A Neptun course. In the role tree it is a TreeUnit of kind "course".
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
     * The tree unit of this course, if the course is in a tree.
     */
    public function courseUnit(): HasOne
    {
        return $this->hasOne(TreeUnit::class);
    }
}
