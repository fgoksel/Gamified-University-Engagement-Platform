<?php

namespace App\Enums;

/**
 * The roles a person can hold on a tree unit, highest level first.
 *
 * These are separate from UserRole: UserRole is the account type, a TreeRole
 * is the job a person does in one course.
 */
enum TreeRole: string
{
    case Dean = 'dean';

    case Teacher = 'teacher';

    case CoTeacher = 'co_teacher';

    case Tutor = 'tutor';

    case Student = 'student';

    /**
     * 5 for the dean down to 1 for a student.
     */
    public function level(): int
    {
        return match ($this) {
            self::Dean => 5,
            self::Teacher => 4,
            self::CoTeacher => 3,
            self::Tutor => 2,
            self::Student => 1,
        };
    }

    /**
     * The roles this role may give to other people. The dean is appointed
     * by the admin, who is outside the tree.
     *
     * @return list<self>
     */
    public function canAdd(): array
    {
        return match ($this) {
            self::Dean => [self::Teacher],
            self::Teacher => [self::CoTeacher, self::Tutor, self::Student],
            self::CoTeacher => [self::Tutor, self::Student],
            self::Tutor, self::Student => [],
        };
    }

    /**
     * The unit kinds a person with this role can sit on.
     *
     * @return list<TreeUnitKind>
     */
    public function sitsOn(): array
    {
        return match ($this) {
            self::Dean => [TreeUnitKind::Root],
            self::Teacher, self::CoTeacher, self::Student => [TreeUnitKind::Course],
            self::Tutor => [TreeUnitKind::Course, TreeUnitKind::Subtopic],
        };
    }

    /**
     * The account type a person needs for this role: staff roles need a
     * teacher account, tutors and students need a student account.
     */
    public function accountType(): UserRole
    {
        return match ($this) {
            self::Dean, self::Teacher, self::CoTeacher => UserRole::Teacher,
            self::Tutor, self::Student => UserRole::Student,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Dean => 'Dean',
            self::Teacher => 'Teacher',
            self::CoTeacher => 'Co-teacher',
            self::Tutor => 'Student tutor',
            self::Student => 'Student',
        };
    }
}
