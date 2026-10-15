<?php

namespace App\Http\Requests;

use App\Models\Topic;
use App\Services\EventPlacement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The Event Form (Task #38). "action" says whether the organiser saves a
 * draft or publishes; dates are checked the same way for both, so a draft
 * can always be published later without surprises.
 */
class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('events.manage_own') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['draft', 'publish'])],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'topic_tag_id' => ['required', 'integer', Rule::exists('topic_tags', 'id')->where('is_active', true)],
            'owning_topic_id' => ['required', 'integer', 'exists:topics,id'],
            'audience_topic_id' => ['nullable', 'integer', 'exists:topics,id'],
            'capacity' => ['required', 'integer', 'min:1', 'max:10000'],
            'application_type' => ['required', Rule::in(['fcfs', 'teacher_select'])],
            'points_value' => ['required', 'integer', 'min:1', 'max:1000'],
            'event_starts_at' => ['required', 'date', 'after:now'],
            'event_ends_at' => ['required', 'date', 'after:event_starts_at'],
            'application_deadline' => ['required', 'date', 'after:now', 'before_or_equal:event_starts_at'],
            'completion_deadline' => ['required', 'date', 'after_or_equal:event_ends_at'],
            'is_hidden' => ['boolean'],
            'reveal_at' => ['nullable', 'required_if:is_hidden,true', 'date', 'after:now', 'before_or_equal:event_starts_at'],
            'qr_enabled' => ['boolean'],
        ];
    }

    /**
     * Rules that depend on the topic tree.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['owning_topic_id', 'audience_topic_id'])) {
                    return;
                }

                $placement = app(EventPlacement::class);
                $owning = Topic::find($this->integer('owning_topic_id'));

                if (! $placement->mayOwn($this->user(), $owning)) {
                    $validator->errors()->add('owning_topic_id', 'You cannot create events at this topic.');

                    return;
                }

                $audience = $this->input('audience_topic_id');

                if ($audience !== null && ! in_array((int) $audience, $placement->audienceIds($owning), true)) {
                    $validator->errors()->add('audience_topic_id', 'The audience must be the owning topic or a topic above it.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'topic_tag_id.required' => 'Choose a topic tag.',
            'topic_tag_id.exists' => 'This topic tag is no longer available.',
            'owning_topic_id.required' => 'Choose where the event is managed from.',
            'event_starts_at.after' => 'The event must start in the future.',
            'event_ends_at.after' => 'The event must end after it starts.',
            'application_deadline.after' => 'The application deadline must be in the future.',
            'application_deadline.before_or_equal' => 'Applications must close before the event starts.',
            'completion_deadline.after_or_equal' => 'The completion deadline cannot be before the event ends.',
            'reveal_at.required_if' => 'Choose when a hidden event is revealed.',
            'reveal_at.before_or_equal' => 'A hidden event must be revealed before it starts.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'event_starts_at' => 'start',
            'event_ends_at' => 'end',
            'points_value' => 'points',
        ];
    }
}
