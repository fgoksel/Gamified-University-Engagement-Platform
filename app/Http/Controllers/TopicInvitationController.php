<?php

namespace App\Http\Controllers;

use App\Enums\Capability;
use App\Http\Controllers\Concerns\ChecksImportTargets;
use App\Models\RoleAssignment;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\User;
use App\Services\AreaInvitations;
use App\Services\AssignmentService;
use App\Services\OrganizerAccounts;
use App\Services\StudentAccounts;
use App\Services\TopicAccess;
use App\Services\TopicBrowser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Invite a person" on an area's Access tab (Task #104): an area admin adds one student or
 * organiser who has no account yet, without the admin panel (N1, 9 November). The account is
 * created the same way as there (StudentAccounts, OrganizerAccounts) and gets the chosen role at
 * the area and the ticked areas above, with the same rights as "Import students".
 *
 * Someone who already has an account with this e-mail gets no second one: they are given the
 * role instead, unless the account is deactivated. Inviting an organiser also needs "Give a role".
 */
class TopicInvitationController extends Controller
{
    use ChecksImportTargets;

    public function __construct(
        private TopicAccess $access,
        private TopicBrowser $browser,
        private AssignmentService $assignments,
        private AreaInvitations $invitations,
    ) {}

    public function store(Request $request, Topic $topic): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->access->canView($user, $topic), 404);
        abort_unless($this->access->can($user, Capability::AccessImport, $topic), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'account_type' => ['required', Rule::in(['student', 'organiser'])],
            'neptun_code' => ['nullable', 'required_if:account_type,student', 'string', 'regex:/^[A-Za-z0-9]{6}$/'],
            'role_definition_id' => ['required', 'integer'],
            'parent_topic_ids' => ['nullable', 'array'],
            'parent_topic_ids.*' => ['integer'],
        ], [
            'name.required' => __('areas.invite_flash.enter_the_name'),
            'email.required' => __('areas.invite_flash.enter_the_email'),
            'email.email' => __('areas.invite_flash.the_email_is_not_valid'),
            'account_type.*' => __('areas.invite_flash.choose_the_account_type'),
            'neptun_code.required_if' => __('areas.invite_flash.enter_the_neptun_code'),
            'neptun_code.regex' => __('areas.invite_flash.the_neptun_code_must'),
            'role_definition_id.required' => __('areas.import_flash.choose_a_role'),
        ]);

        // An organiser creates and runs events: inviting one also needs "Give a role" here.
        abort_if($data['account_type'] === 'organiser' && ! $this->invitations->mayInviteOrganisers($user, $topic), 403);

        [$role, $parents] = $this->importTargets($this->browser, $user, $topic, (int) $data['role_definition_id'], $data['parent_topic_ids'] ?? []);

        $email = Str::lower(trim($data['email']));
        $neptun = filled($data['neptun_code'] ?? null) ? Str::upper(trim($data['neptun_code'])) : null;
        $existing = $this->existingAccount($email, $neptun);
        $semesterId = Semester::where('status', 'active')->orderByDesc('starts_at')->value('id');

        $person = DB::transaction(function () use ($data, $email, $neptun, $existing, $user, $topic, $role, $parents, $semesterId) {
            $person = $existing ?? ($data['account_type'] === 'student'
                ? app(StudentAccounts::class)->invite($data['name'], $email, $neptun)
                : app(OrganizerAccounts::class)->invite($data['name'], $email, null, $neptun));

            foreach ([$topic, ...$parents] as $target) {
                // At an area above, someone who already has the role there keeps it as it is.
                if ($target->isNot($topic) && $this->holds($person, $target, $role->id)) {
                    continue;
                }

                try {
                    $this->assignments->import($user, $target, $role, $person, $semesterId);
                } catch (ValidationException $e) {
                    // The form has no person field: say it at the e-mail address.
                    throw ValidationException::withMessages(['email' => collect($e->errors())->flatten()->first()]);
                } catch (AuthorizationException $e) {
                    throw ValidationException::withMessages(['email' => $e->getMessage()]);
                }
            }

            return $person;
        });

        $message = $existing === null
            ? __('areas.invite_flash.invitation_sent', ['email' => $person->email, 'role' => $role->name, 'area' => $topic->title])
            : __('areas.invite_flash.existing_account', ['name' => $person->name, 'role' => $role->name, 'area' => $topic->title]);

        return redirect()
            ->route('topics.show', ['topic' => $topic, 'tab' => 'access'])
            ->with('success', $message);
    }

    /**
     * "Resend invitation" on the Access tab (Task #104): a new activation link, valid for 24
     * hours, for someone who holds a role on this area or below and has not activated yet.
     */
    public function resend(Request $request, Topic $topic, User $person): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->access->canView($user, $topic), 404);
        // Only people of this area: anyone else is not found here.
        abort_unless($this->invitations->holdsRoleWithin($person, $topic), 404);
        abort_unless($this->invitations->mayResendTo($user, $topic, $person), 403);

        if ($person->status !== 'invited') {
            return back()->with('error', __('areas.invite_flash.not_invited', ['name' => $person->name]));
        }

        $this->invitations->resend($person);

        return back()->with('success', __('areas.invite_flash.resent', ['email' => $person->email]));
    }

    /**
     * The account to give the role to instead of creating one: only the account with this
     * e-mail address, and only when the Neptun code is empty or its own. A Neptun code that
     * belongs to somebody else is refused, so a typo never gives a role to the wrong person.
     *
     * @throws ValidationException
     */
    private function existingAccount(string $email, ?string $neptun): ?User
    {
        $byEmail = User::where('email', $email)->first();
        $byNeptun = $neptun === null ? null : User::where('neptun_code', $neptun)->first();

        if ($byEmail !== null && $byNeptun !== null && $byEmail->isNot($byNeptun)) {
            throw ValidationException::withMessages(['email' => __('areas.invite_flash.two_accounts')]);
        }

        if ($byEmail === null && $byNeptun !== null) {
            throw ValidationException::withMessages(['neptun_code' => __('areas.invite_flash.neptun_belongs_to', ['name' => $byNeptun->name, 'email' => $byNeptun->email])]);
        }

        // The e-mail is theirs, but they have another Neptun code (or none).
        if ($byEmail !== null && $neptun !== null && $byNeptun === null) {
            throw ValidationException::withMessages(['neptun_code' => __('areas.invite_flash.email_has_other_code', ['name' => $byEmail->name])]);
        }

        if ($byEmail !== null && $byEmail->status === 'inactive') {
            throw ValidationException::withMessages(['email' => __('areas.invite_flash.deactivated', ['name' => $byEmail->name])]);
        }

        return $byEmail;
    }

    private function holds(User $person, Topic $topic, int $roleId): bool
    {
        return RoleAssignment::query()->active()
            ->where(['topic_id' => $topic->id, 'user_id' => $person->id, 'role_definition_id' => $roleId])
            ->exists();
    }
}
