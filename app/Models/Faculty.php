<?php

namespace App\Models;

use App\Enums\TreeUnitKind;
use Database\Factories\FacultyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Faculty extends Model
{
    /** @use HasFactory<FacultyFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
    ];

    protected static function booted(): void
    {
        // The faculty's tree is named after the faculty.
        static::updated(function (Faculty $faculty) {
            if ($faculty->wasChanged('name')) {
                $faculty->tree?->update(['title' => $faculty->name]);
            }
        });
    }

    /**
     * Get all users affiliated with this faculty.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The Neptun courses of this faculty. Each one also appears in the
     * faculty's tree.
     */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    /**
     * The faculty's tree (its root unit), once the admin has created it.
     */
    public function tree(): HasOne
    {
        return $this->hasOne(TreeUnit::class)->where('kind', TreeUnitKind::Root);
    }
}
