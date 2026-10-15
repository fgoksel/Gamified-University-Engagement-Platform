<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * A published event that started an hour ago and can still be credited.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->sentence(3),
            'description' => fake()->paragraph(),
            'category' => 'academic',
            'created_by_id' => User::factory(),
            'owning_topic_id' => Topic::query()->inRandomOrder()->value('id'),
            'audience_topic_id' => null,
            'semester_id' => fn () => Semester::query()->value('id') ?? Semester::create([
                'name' => 'Test semester',
                'starts_at' => now()->subMonth()->toDateString(),
                'ends_at' => now()->addMonths(4)->toDateString(),
                'status' => 'active',
            ])->id,
            'capacity' => 30,
            'application_type' => 'fcfs',
            'points_value' => 10,
            'event_starts_at' => now()->subHour(),
            'event_ends_at' => now()->addHour(),
            'application_deadline' => now()->subDay(),
            'completion_deadline' => now()->addDays(7),
            'status' => 'published',
            'published_at' => now()->subDays(2),
            'qr_enabled' => true,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => 'draft', 'published_at' => null]);
    }

    public function onHold(): static
    {
        return $this->state(['status' => 'on_hold']);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'Test']);
    }
}
