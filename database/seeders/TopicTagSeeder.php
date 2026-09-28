<?php

namespace Database\Seeders;

use App\Models\TopicTag;
use Illuminate\Database\Seeder;

/**
 * Creates active topic tags in every category (Technical Specification 6.4),
 * so an event can always be given a topic tag (events.topic_tag_id is required).
 */
class TopicTagSeeder extends Seeder
{
    /**
     * Tag name => category.
     *
     * @var array<string, string>
     */
    public const TOPIC_TAGS = [
        'Exam Preparation' => 'academic',
        'Tutoring' => 'academic',
        'TDK Submission' => 'scientific',
        'Conference Talk' => 'scientific',
        'University Championship' => 'sports',
        'Charity Run' => 'sports',
        'Freshman Ball' => 'community',
        'Volunteer Work' => 'community',
    ];

    public function run(): void
    {
        foreach (self::TOPIC_TAGS as $name => $category) {
            TopicTag::firstOrCreate(
                ['name' => $name],
                ['category' => $category, 'is_active' => true],
            );
        }
    }
}
