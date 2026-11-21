<?php

namespace App\Services;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Models\RoleAssignment;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who may invite whom from an area (Task #104), in one place for the Access tab and the server.
 *
 * Students need "Import students" (access.import) on the area. Organisers create and run
 * events, so inviting one, or resending their invitation, also needs "Give a role"
 * (access.assign) there.
 */
class AreaInvitations
{
    public function __construct(private TopicAccess $access) {}

    public function mayInviteStudents(User $viewer, Topic $topic): bool
    {
        return $this->access->can($viewer, Capability::AccessImport, $topic);
    }

    public function mayInviteOrganisers(User $viewer, Topic $topic): bool
    {
        return $this->mayInviteStudents($viewer, $topic)
            && $this->access->can($viewer, Capability::AccessAssign, $topic);
    }

    /**
     * The rights part of "Resend invitation": the same as inviting this kind of account.
     */
    public function mayResendTo(User $viewer, Topic $topic, User $person): bool
    {
        return $this->isStudent($person)
            ? $this->mayInviteStudents($viewer, $topic)
            : $this->mayInviteOrganisers($viewer, $topic);
    }

    /**
     * The person holds a role on this area or an area below it: only they are "from" the area.
     */
    public function holdsRoleWithin(User $person, Topic $topic): bool
    {
        return RoleAssignment::query()->active()
            ->where('user_id', $person->id)
            ->whereIn('topic_id', DB::table('topic_closure')->where('ancestor_id', $topic->id)->select('descendant_id'))
            ->exists();
    }

    /**
     * A new activation link, valid for 24 hours, with the same e-mail as the first invitation.
     */
    public function resend(User $person): void
    {
        $this->isStudent($person)
            ? app(StudentAccounts::class)->resend($person)
            : app(OrganizerAccounts::class)->resend($person);
    }

    private function isStudent(User $person): bool
    {
        return $person->hasRole(UserRole::Student);
    }
}
