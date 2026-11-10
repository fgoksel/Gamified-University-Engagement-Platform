<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Models\Topic;
use App\Services\CategoryList;
use App\Services\EventPlacement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
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
        return $this->user() !== null && Gate::forUser($this->user())->allows('create', Event::class);
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
            // Task #77: free text, may stay empty.
            'location' => ['nullable', 'string', 'max:255'],
            // Both optional (D10, Task #116): the topic tree organises events. A tag brings its
            // category along; without one the organiser may still pick a category.
            'topic_tag_id' => ['nullable', 'integer', Rule::exists('topic_tags', 'id')->where('is_active', true)
                ->whereIn('category', array_keys(app(CategoryList::class)->options()))],
            'category' => ['nullable', 'string', app(CategoryList::class)->inUseRule()],
            'owning_topic_id' => ['required', 'integer', 'exists:topics,id'],
            'audience_topic_id' => ['nullable', 'integer', 'exists:topics,id'],
            'capacity' => ['required', 'integer', 'min:1', 'max:10000'],
            'application_type' => ['required', Rule::in(['fcfs', 'teacher_select'])],
            'points_value' => ['required', 'integer', 'min:1', 'max:1000'],
            'event_starts_at' => ['required', 'date', 'after:now'],
            'event_ends_at' => ['required', 'date', 'after:event_starts_at'],
            'applications_open_at' => ['nullable', 'date', 'before:application_deadline'],
            'application_deadline' => ['required', 'date', 'after:now', 'before_or_equal:event_ends_at'],
            'completion_deadline' => ['required', 'date', 'after_or_equal:event_ends_at'],
            'is_hidden' => ['boolean'],
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
                    $validator->errors()->add('owning_topic_id', __('events.validation.you_cannot_create_events'));

                    return;
                }

                if ($this->input('action') === 'publish' && ! $placement->mayPublish($this->user(), $owning)) {
                    $validator->errors()->add('action', __('events.validation.you_may_save_this_event'));

                    return;
                }

                $audience = $this->input('audience_topic_id');

                if ($audience !== null && ! in_array((int) $audience, $placement->audienceIds($owning), true)) {
                    $validator->errors()->add('audience_topic_id', __('events.validation.the_audience_must'));
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
            'topic_tag_id.exists' => __('events.validation.this_topic_tag'),
            'category.exists' => __('events.validation.this_category_is_no_longer'),
            'owning_topic_id.required' => __('events.validation.choose_where_the_event'),
            'event_starts_at.after' => __('events.validation.the_event_must_start'),
            'event_ends_at.after' => __('events.validation.the_event_must_end_after'),
            'application_deadline.after' => __('events.validation.the_application_deadline_must'),
            'applications_open_at.before' => __('events.validation.applications_must_open_before_they'),
            'application_deadline.before_or_equal' => __('events.validation.applications_must_close_before'),
            'completion_deadline.after_or_equal' => __('events.validation.the_completion_deadline_cannot'),
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
            'applications_open_at' => __('events.validation.application_opening'),
            'points_value' => 'points',
        ];
    }
}
