<?php

namespace App\Jobs;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\User;
use App\Services\AssignmentService;
use App\Support\LegacyTreeUpgrade;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Import of the Neptun course enrolment file (UC-3.2.3, Technical Specification 7.3.1).
 *
 * Columns: Neptun Code, Course Code, Course Name (all required).
 *
 * This is a bounded compatibility path, not a topic template. A student is
 * placed only on the topic that the upgrade from the previous faculty tree
 * linked to that course (topics.course_id), using the role definition that
 * was created from the old "student" label. Nothing is attached to any other
 * topic, and nothing is created: rows that cannot be placed are counted in
 * the summary by reason (unknown Neptun code, unknown course, course not
 * linked to a topic, student role unavailable). A repeated import adds
 * nothing twice, and nothing is ever deleted.
 */
class ImportCourseEnrollmentsJob extends ImportCsvJob
{
    private ?Semester $semester = null;

    private ?User $admin = null;

    private int $registrations = 0;

    private int $unregistered = 0;

    private int $roleUnavailable = 0;

    /** @var array<string, int> Unknown course code => rows */
    private array $unknownCourses = [];

    /** @var array<string, int> Code of a course that has no linked topic => rows */
    private array $notLinked = [];

    /** @var array<string, int|null> Neptun code => student id */
    private array $students = [];

    /** @var array<string, Course|null> course code => course */
    private array $courses = [];

    private ?RoleDefinition $studentRole = null;

    /**
     * @param  int|null  $semesterId  Semester of the enrolments; the active semester when null.
     */
    public function __construct(
        string $path,
        int $adminId,
        public ?int $semesterId = null,
        string $disk = 'local',
    ) {
        parent::__construct($path, $adminId, $disk);
    }

    protected function requiredColumns(): array
    {
        return ['neptun_code', 'course_code', 'course_name'];
    }

    protected function requiredColumnLabels(): array
    {
        return ['Neptun Code', 'Course Code', 'Course Name'];
    }

    protected function title(): string
    {
        return 'Course enrolment import';
    }

    protected function prepare(): void
    {
        $this->registrations = $this->unregistered = $this->roleUnavailable = 0;
        $this->students = $this->courses = $this->unknownCourses = $this->notLinked = [];
        $this->studentRole = null;

        $this->semester = $this->semesterId !== null
            ? Semester::find($this->semesterId)
            : Semester::where('status', 'active')->first();

        if ($this->semester === null) {
            throw new InvalidArgumentException('There is no active semester to link the courses to.');
        }

        $this->admin = User::find($this->adminId);
    }

    protected function processRow(array $row, int $line): void
    {
        $neptun = Str::upper((string) ($row['neptun_code'] ?? ''));
        $courseCode = Str::upper((string) ($row['course_code'] ?? ''));
        $courseName = (string) ($row['course_name'] ?? '');

        if ($neptun === '' || $courseCode === '' || $courseName === '') {
            $this->skip($line, 'Neptun Code, Course Code and Course Name are all required.');

            return;
        }

        $student = $this->student($neptun);

        if ($student === null) {
            $this->unregistered++;

            return;
        }

        $course = $this->course($courseCode);

        if ($course === null) {
            $this->unknownCourses[$courseCode] = ($this->unknownCourses[$courseCode] ?? 0) + 1;

            return;
        }

        $topic = $course->legacyTopic;

        if (! $topic instanceof Topic) {
            $this->notLinked[$courseCode] = ($this->notLinked[$courseCode] ?? 0) + 1;

            return;
        }

        $role = $this->studentRole();

        if ($role === null || $role->isArchived() || $this->admin === null) {
            $this->roleUnavailable++;

            return;
        }

        $already = RoleAssignment::query()->active()
            ->where(['topic_id' => $topic->id, 'user_id' => $student->id, 'role_definition_id' => $role->id])
            ->exists();

        if ($already) {
            return;
        }

        try {
            app(AssignmentService::class)->grant($this->admin, $topic, $role, $student, $this->semester->id);
            $this->registrations++;
        } catch (ValidationException|AuthorizationException) {
            $this->roleUnavailable++;
        }
    }

    protected function summary(): string
    {
        $text = "Successfully processed: {$this->registrations} student-course registrations.";

        if ($this->unregistered > 0) {
            $text .= " Skipped {$this->unregistered} rows due to unregistered Neptun codes.";
        }

        if ($this->unknownCourses !== []) {
            $text .= ' Skipped '.array_sum($this->unknownCourses).' rows because the course code is unknown ('
                .implode(', ', array_keys($this->unknownCourses)).'). Add the course under its faculty first.';
        }

        if ($this->notLinked !== []) {
            $text .= ' Skipped '.array_sum($this->notLinked).' rows because the course is not linked to a topic ('
                .implode(', ', array_keys($this->notLinked)).'). Only courses carried over from the previous faculty tree are linked; students are never attached to another topic automatically.';
        }

        if ($this->roleUnavailable > 0) {
            $text .= " Skipped {$this->roleUnavailable} rows because the student role is archived or could not be given.";
        }

        return $text;
    }

    private function student(string $neptun): ?User
    {
        if (! array_key_exists($neptun, $this->students)) {
            $this->students[$neptun] = User::role(UserRole::Student)->where('neptun_code', $neptun)->value('id');
        }

        return $this->students[$neptun] === null ? null : User::find($this->students[$neptun]);
    }

    private function course(string $code): ?Course
    {
        if (! array_key_exists($code, $this->courses)) {
            $this->courses[$code] = Course::with('legacyTopic')->where('code', $code)->first();
        }

        return $this->courses[$code];
    }

    /**
     * The role definition created from the old "student" label. It exists
     * after an upgrade; it is created here only when a linked topic needs it.
     */
    private function studentRole(): ?RoleDefinition
    {
        return $this->studentRole ??= RoleDefinition::find(LegacyTreeUpgrade::ensureRoleDefinition('student'));
    }
}
