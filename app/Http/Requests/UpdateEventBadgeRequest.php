<?php

namespace App\Http\Requests;

use App\Models\Badge;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The badge card of the event page (Task #103): a name, and a line of text that may stay empty.
 * What happens to the students is decided by EventBadges.
 */
class UpdateEventBadgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && Gate::forUser($this->user())->allows('update', $this->route('event'));
    }

    /**
     * People who do not run the event do not learn that it exists; people who do but may not
     * edit it are refused (the same split as the edit page).
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
            'name' => ['required', 'string', 'max:'.Badge::NAME_MAX],
            'description' => ['nullable', 'string', 'max:'.Badge::DESCRIPTION_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('events.badge.give_it_a_name'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('events.badge.field_name'),
            'description' => __('events.badge.field_description'),
        ];
    }
}
