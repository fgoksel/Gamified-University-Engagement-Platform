<?php

namespace App\Models;

use Database\Factories\FacultyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Faculty extends Model
{
    /** @use HasFactory<FacultyFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
    ];

    /**
     * Get all users affiliated with this faculty.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The Neptun courses of this faculty.
     */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
}
