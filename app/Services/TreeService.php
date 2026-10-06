<?php

namespace App\Services;

use App\Enums\TreeRole;
use App\Enums\TreeUnitKind;
use App\Enums\TutorPermission;
use App\Models\Course;
use App\Models\Semester;
use App\Models\SubjectArea;
use App\Models\TreeUnit;
use App\Models\UnitMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The only place that writes to the subject tree.
 *
 * Every method first asks the policies whether $actor may do it (and throws
 * AuthorizationException if not), then checks the data rules (and throws
 * ValidationException). Memberships are never deleted, only ended.
 */
class TreeService
{
    /**
     * The admin creates a dean's tree: a root unit with the dean on it.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function createTree(User $admin, string $title, User $dean): TreeUnit
    {
        Gate::forUser($admin)->authorize('create', TreeUnit::class);

        return DB::transaction(function () use ($admin, $title, $dean) {
            $root = TreeUnit::create([
                'kind' => TreeUnitKind::Root,
                'title' => $title,
                'created_by_id' => $admin->id,
            ]);

            $this->addMember($admin, $root, $dean, TreeRole::Dean);

            return $root;
        });
    }

    /**
     * The admin adds a Neptun course to a dean's tree as a subject.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function addSubject(User $admin, TreeUnit $root, Course $course, ?SubjectArea $subjectArea = null): TreeUnit
    {
        Gate::forUser($admin)->authorize('addSubject', $root);

        if (TreeUnit::where('course_id', $course->id)->exists()) {
            throw ValidationException::withMessages([
                'course_id' => 'This course is already a subject in a tree.',
            ]);
        }

        return TreeUnit::create([
            'parent_id' => $root->id,
            'kind' => TreeUnitKind::Subject,
            'title' => $course->name,
            'course_id' => $course->id,
            'subject_area_id' => $subjectArea?->id,
            'created_by_id' => $admin->id,
        ]);
    }

    /**
     * A teacher or co-teacher adds a subtopic below a subject or subtopic.
     *
     * @throws AuthorizationException
     */
    public function addSubtopic(User $actor, TreeUnit $parent, string $title): TreeUnit
    {
        Gate::forUser($actor)->authorize('addSubtopic', $parent);

        return TreeUnit::create([
            'parent_id' => $parent->id,
            'kind' => TreeUnitKind::Subtopic,
            'title' => $title,
            'created_by_id' => $actor->id,
        ]);
    }

    /**
     * Give $user the role $role on $unit.
     *
     * Students added here are marked manual (rule 9); the Neptun import
     * adds students separately. Making a student of the subject a tutor
     * ends their student role first (rule 5).
     *
     * @param  list<TutorPermission|string>  $permissions  Tutors only.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function addMember(User $actor, TreeUnit $unit, User $user, TreeRole $role, array $permissions = []): UnitMembership
    {
        Gate::forUser($actor)->authorize('add', [UnitMembership::class, $unit, $role]);

        if (! in_array($unit->kind, $role->sitsOn(), true)) {
            throw ValidationException::withMessages([
                'role' => "A {$role->label()} cannot be placed on this unit.",
            ]);
        }

        if (! $user->hasRole($role->accountType())) {
            throw ValidationException::withMessages([
                'user_id' => "Only {$role->accountType()->value} accounts can be added as {$role->label()}.",
            ]);
        }

        $permissions = $role === TreeRole::Tutor ? $this->permissionValues($permissions) : null;

        return DB::transaction(function () use ($actor, $unit, $user, $role, $permissions) {
            if ($role === TreeRole::Dean) {
                $this->ensureNoDean($unit);
            } else {
                $this->makeRoomInSubject($unit, $user, $role);
            }

            return UnitMembership::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'role' => $role,
                'added_by_id' => $actor->id,
                'permissions' => $permissions,
                'semester_id' => $role === TreeRole::Student ? Semester::where('status', 'active')->value('id') : null,
                'manual' => $role === TreeRole::Student,
                'started_at' => now(),
            ]);
        });
    }

    /**
     * The Neptun enrolment import adds a student to a subject (build step 4).
     * Runs in a queued job started by the admin, so there is no policy check.
     *
     * Returns "added", "already" when the student already studies the
     * subject, or "staff" when they hold another role in it (rule 5). Nothing
     * is ever ended or deleted here.
     *
     * @return 'added'|'already'|'staff'
     */
    public function importStudent(TreeUnit $subject, User $student, Semester $semester, ?User $admin = null): string
    {
        return DB::transaction(function () use ($subject, $student, $semester, $admin) {
            $roles = UnitMembership::query()
                ->active()
                ->where('user_id', $student->id)
                ->whereIn('unit_id', TreeUnit::within($subject)->select('id'))
                ->lockForUpdate()
                ->pluck('role');

            if ($roles->isNotEmpty()) {
                return $roles->every(fn (TreeRole $role) => $role === TreeRole::Student) ? 'already' : 'staff';
            }

            UnitMembership::create([
                'unit_id' => $subject->id,
                'user_id' => $student->id,
                'role' => TreeRole::Student,
                'added_by_id' => $admin?->id,
                'semester_id' => $semester->id,
                'manual' => false,
                'started_at' => now(),
            ]);

            return 'added';
        });
    }

    /**
     * End a role (rule 8). The record stays, with the date and the reason.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function endMembership(User $actor, UnitMembership $membership, string $reason): UnitMembership
    {
        Gate::forUser($actor)->authorize('end', $membership);

        if (! $membership->isActive()) {
            throw ValidationException::withMessages([
                'membership' => 'This role has already ended.',
            ]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'ended_reason' => 'Give a reason for ending this role.',
            ]);
        }

        $membership->update([
            'ended_at' => now(),
            'ended_reason' => trim($reason),
        ]);

        return $membership;
    }

    /**
     * A teacher or co-teacher sets what one tutor may do (rule 7).
     *
     * @param  list<TutorPermission|string>  $permissions
     *
     * @throws AuthorizationException|ValidationException
     */
    public function setTutorPermissions(User $actor, UnitMembership $membership, array $permissions): UnitMembership
    {
        Gate::forUser($actor)->authorize('setPermissions', $membership);

        if (! $membership->isActive()) {
            throw ValidationException::withMessages([
                'membership' => 'This role has already ended.',
            ]);
        }

        $membership->update(['permissions' => $this->permissionValues($permissions)]);

        return $membership;
    }

    /**
     * The admin removes a unit created by mistake (rule 8). Only a unit that
     * never had anyone on it and has no units below it can be deleted.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function deleteUnit(User $admin, TreeUnit $unit): void
    {
        Gate::forUser($admin)->authorize('delete', $unit);

        if ($unit->children()->exists() || $unit->memberships()->exists()) {
            throw ValidationException::withMessages([
                'unit' => 'Only an empty unit can be deleted. This unit has units below it or people who were on it.',
            ]);
        }

        $unit->delete();
    }

    /**
     * One active dean per tree.
     */
    private function ensureNoDean(TreeUnit $root): void
    {
        if ($root->memberships()->active()->where('role', TreeRole::Dean)->exists()) {
            throw ValidationException::withMessages([
                'user_id' => 'This tree already has a dean.',
            ]);
        }
    }

    /**
     * Rule 5: one active role per person in one subject's whole subtree.
     * The only exception: a student of the subject can become its tutor,
     * which ends the student role.
     */
    private function makeRoomInSubject(TreeUnit $unit, User $user, TreeRole $role): void
    {
        $subject = $unit->subjectUnit();

        $existing = UnitMembership::query()
            ->active()
            ->where('user_id', $user->id)
            ->whereIn('unit_id', TreeUnit::within($subject)->select('id'))
            ->lockForUpdate()
            ->get();

        if ($existing->isEmpty()) {
            return;
        }

        $studentBecomesTutor = $role === TreeRole::Tutor
            && $existing->every(fn (UnitMembership $membership) => $membership->role === TreeRole::Student);

        if (! $studentBecomesTutor) {
            throw ValidationException::withMessages([
                'user_id' => 'This person already has a role in this subject.',
            ]);
        }

        $existing->each->update([
            'ended_at' => now(),
            'ended_reason' => 'Became a tutor in this subject.',
        ]);
    }

    /**
     * @param  list<TutorPermission|string>  $permissions
     * @return list<string>
     */
    private function permissionValues(array $permissions): array
    {
        $values = [];

        foreach ($permissions as $permission) {
            $case = $permission instanceof TutorPermission ? $permission : TutorPermission::tryFrom((string) $permission);

            if ($case === null) {
                throw ValidationException::withMessages([
                    'permissions' => "Unknown tutor permission: {$permission}.",
                ]);
            }

            $values[] = $case->value;
        }

        return array_values(array_unique($values));
    }
}
