<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Event Form when saving changes (Task #68). It checks the shape of the fields;
 * which fields may change in the event's current state is decided by EventEditor,
 * under the same lock that saves them.
 */
class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && Gate::forUser($this->user())->allows('update', $this->route('event'));
    }

    /**
     * People who do not run the event do not learn that it exists; people who do but may
     * not edit it are refused (the same split as the edit page).
     */
    protected function failedAuthorization(): never
    {
        throw Gate::forUser($this->user())->allows('staff', $this->route('event'))
            ? new AuthorizationException
            : new NotFoundHttpException;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version' => ['nullable', 'string', 'max:40'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'topic_tag_id' => ['required', 'integer', 'exists:topic_tags,id'],
            'owning_topic_id' => ['required', 'integer', 'exists:topics,id'],
            'audience_topic_id' => ['nullable', 'integer', 'exists:topics,id'],
            'capacity' => ['required', 'integer', 'min:1', 'max:10000'],
            'application_type' => ['required', Rule::in(['fcfs', 'teacher_select'])],
            'points_value' => ['required', 'integer', 'min:1', 'max:1000'],
            'event_starts_at' => ['required', 'date'],
            'event_ends_at' => ['required', 'date'],
            'application_deadline' => ['required', 'date'],
            'completion_deadline' => ['required', 'date'],
            'is_hidden' => ['boolean'],
            'reveal_at' => ['nullable', 'date'],
            'qr_enabled' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'topic_tag_id.required' => 'Choose a topic tag.',
            'owning_topic_id.required' => 'Choose where the event is managed from.',
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
