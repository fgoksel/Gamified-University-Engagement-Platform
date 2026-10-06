<?php

namespace App\Services;

use App\Enums\TreeRole;
use App\Enums\TreeUnitKind;
use App\Enums\TutorPermission;
use App\Models\Course;
use App\Models\Faculty;
use App\Models\Semester;
use App\Models\TreeUnit;
use App\Models\UnitMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The only place that writes to the faculty tree.
 *
 * Every method first asks the policies whether $actor may do it (and throws
 * AuthorizationException if not), then checks the data rules (and throws
 * ValidationException). Memberships are never deleted, only ended.
 */
class TreeService
{
    /**
     * The admin creates a faculty's tree with its dean. The tree is named
     * after the faculty and holds every course of the faculty at once.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function createTree(User $admin, Faculty $faculty, User $dean): TreeUnit
    {
        Gate::forUser($admin)->authorize('create', TreeUnit::class);

        if ($faculty->tree()->exists()) {
            throw ValidationException::withMessages([
                'faculty_id' => 'This faculty already has a tree.',
            ]);
        }

        return DB::transaction(function () use ($admin, $faculty, $dean) {
            $root = TreeUnit::create([
                'faculty_id' => $faculty->id,
                'kind' => TreeUnitKind::Root,
                'title' => $faculty->name,
                'created_by_id' => $admin->id,
            ]);

            $this->addMember($admin, $root, $dean, TreeRole::Dean);

            $faculty->courses()->each(fn (Course $course) => $this->placeCourse($course));

            return $root;
        });
    }

    /**
     * The admin replaces a tree's dean in one step: the current dean's role
     * is ended with the reason and the new dean is appointed. If the new
     * dean cannot be appointed, nothing changes, so the tree never ends up
     * without a dean. The old dean's record is kept, like every ended role.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function replaceDean(User $admin, TreeUnit $root, User $newDean, string $reason): UnitMembership
    {
        Gate::forUser($admin)->authorize('add', [UnitMembership::class, $root, TreeRole::Dean]);

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'ended_reason' => 'Give a reason for replacing the dean.',
            ]);
        }

        $current = $root->memberships()->active()->where('role', TreeRole::Dean)->first();

        if ($current?->user_id === $newDean->id) {
            throw ValidationException::withMessages([
                'user_id' => 'This person is already the dean of this tree.',
            ]);
        }

        return DB::transaction(function () use ($admin, $root, $newDean, $reason, $current) {
            if ($current !== null) {
                $this->endMembership($admin, $current, $reason);
            }

            return $this->addMember($admin, $root, $newDean, TreeRole::Dean);
        });
    }

    /**
     * Put a course into its faculty's tree, if the faculty has one and the
     * course is not there yet. Called whenever a course is created and when
     * a tree is created, so the tree always shows all of its faculty's courses.
     */
    public function placeCourse(Course $course): ?TreeUnit
    {
        $root = TreeUnit::where('kind', TreeUnitKind::Root)->where('faculty_id', $course->faculty_id)->first();

        if ($root === null || TreeUnit::where('course_id', $course->id)->exists()) {
            return null;
        }

        return TreeUnit::create([
            'parent_id' => $root->id,
            'kind' => TreeUnitKind::Course,
            'title' => $course->name,
            'course_id' => $course->id,
        ]);
    }

    /**
     * A teacher or co-teacher adds a subtopic below a course or subtopic.
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
     * adds students separately. Making a student of the course a tutor
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
                $this->ensureNoDean($unit, $user);
            } else {
                $this->makeRoomInCourse($unit, $user, $role);
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
     * The Neptun enrolment import adds a student to a course (build step 4).
     * Runs in a queued job started by the admin, so there is no policy check.
     *
     * Returns "added", "already" when the student already studies the
     * course, or "staff" when they hold another role in it (rule 5). Nothing
     * is ever ended or deleted here.
     *
     * @return 'added'|'already'|'staff'
     */
    public function importStudent(TreeUnit $courseUnit, User $student, Semester $semester, ?User $admin = null): string
    {
        return DB::transaction(function () use ($courseUnit, $student, $semester, $admin) {
            $roles = UnitMembership::query()
                ->active()
                ->where('user_id', $student->id)
                ->whereIn('unit_id', TreeUnit::within($courseUnit)->select('id'))
                ->lockForUpdate()
                ->pluck('role');

            if ($roles->isNotEmpty()) {
                return $roles->every(fn (TreeRole $role) => $role === TreeRole::Student) ? 'already' : 'staff';
            }

            UnitMembership::create([
                'unit_id' => $courseUnit->id,
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
     * One active dean per tree, and a person is the dean of one tree only.
     * The database enforces both as well.
     */
    private function ensureNoDean(TreeUnit $root, User $user): void
    {
        if ($root->memberships()->active()->where('role', TreeRole::Dean)->exists()) {
            throw ValidationException::withMessages([
                'user_id' => 'This tree already has a dean.',
            ]);
        }

        if ($user->memberships()->active()->where('role', TreeRole::Dean)->exists()) {
            throw ValidationException::withMessages([
                'user_id' => 'This person is already the dean of another tree.',
            ]);
        }
    }

    /**
     * Rule 5: one active role per person in one course's whole subtree.
     * The only exception: a student of the course can become its tutor,
     * which ends the student role.
     */
    private function makeRoomInCourse(TreeUnit $unit, User $user, TreeRole $role): void
    {
        $courseUnit = $unit->courseUnit();

        $existing = UnitMembership::query()
            ->active()
            ->where('user_id', $user->id)
            ->whereIn('unit_id', TreeUnit::within($courseUnit)->select('id'))
            ->lockForUpdate()
            ->get();

        if ($existing->isEmpty()) {
            return;
        }

        $studentBecomesTutor = $role === TreeRole::Tutor
            && $existing->every(fn (UnitMembership $membership) => $membership->role === TreeRole::Student);

        if (! $studentBecomesTutor) {
            throw ValidationException::withMessages([
                'user_id' => 'This person already has a role in this course.',
            ]);
        }

        $existing->each->update([
            'ended_at' => now(),
            'ended_reason' => 'Became a tutor in this course.',
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
