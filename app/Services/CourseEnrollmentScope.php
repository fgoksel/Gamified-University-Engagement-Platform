<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Builder;

class CourseEnrollmentScope
{
    /** @return Builder<Topic> */
    public static function destinations(Course $course): Builder
    {
        $linkedTopic = $course->legacyTopic;

        return $linkedTopic === null
            ? Topic::query()->whereRaw('1 = 0')
            : Topic::query()->withinBranch($linkedTopic)->notArchived();
    }

    public static function destination(Course $course, int $topicId): ?Topic
    {
        return self::destinations($course)->whereKey($topicId)->first();
    }
}
