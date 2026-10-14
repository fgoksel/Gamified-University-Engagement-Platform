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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Import of the Neptun course enrolment file (UC-3.2.3, Technical Specification 7.3.1; Task #29).
 *
 * Columns: Neptun Code, Course Code, Course Name (all required).
 *
 * Per 13 Oct 2026 agreement:
 * Students are imported at a specific course topic node. During import, the importer
 * can select additional nodes directly above (ancestors) or below (descendants) in the
 * same branch. Nothing is added automatically (replaces automatic upward/downward flow).
 * Students belong strictly to the import node and the nodes explicitly ticked.
 */
class ImportCourseEnrollmentsJob extends ImportCsvJob
{
    private ?Semester $semester = null;

    private ?User $admin = null;

    private int $registrations = 0;

    private int $additionalPlacements = 0;

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

    /** @var Collection<int, Topic>|null Pre-resolved same-branch topics when targetTopicId is fixed */
    private ?Collection $precomputedAdditionalTopics = null;

    /**
     * @param  int|null  $semesterId  Semester of the enrolments; the active semester when null.
     * @param  array<int>  $additionalTopicIds  Nodes ticked above and below in the same branch.
     * @param  int|null  $targetTopicId  Specific topic node to import into, overriding course link if provided.
     */
    public function __construct(
        string $path,
        int $adminId,
        public ?int $semesterId = null,
        string $disk = 'local',
        public array $additionalTopicIds = [],
        public ?int $targetTopicId = null,
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
        $this->registrations = $this->additionalPlacements = $this->unregistered = $this->roleUnavailable = 0;
        $this->students = $this->courses = $this->unknownCourses = $this->notLinked = [];
        $this->studentRole = null;

        $this->semester = $this->semesterId !== null
            ? Semester::find($this->semesterId)
            : Semester::where('status', 'active')->first();

        if ($this->semester === null) {
            throw new InvalidArgumentException('There is no active semester to link the courses to.');
        }

        $this->admin = User::find($this->adminId);

        $this->precomputedAdditionalTopics = null;
        if ($this->targetTopicId !== null && ! empty($this->additionalTopicIds)) {
            $this->precomputedAdditionalTopics = Topic::whereIn('id', $this->additionalTopicIds)
                ->where(function ($q) {
                    $q->whereIn('id', DB::table('topic_closure')->where('descendant_id', $this->targetTopicId)->where('distance', '>', 0)->select('ancestor_id'))
                        ->orWhereIn('id', DB::table('topic_closure')->where('ancestor_id', $this->targetTopicId)->where('distance', '>', 0)->select('descendant_id'));
                })
                ->notArchived()
                ->get();
        }
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

        $courseTopic = $course->topic ?? $course->legacyTopic;

        // When a target topic is specified (per-course import), only process rows belonging to that topic
        if ($this->targetTopicId !== null && ($courseTopic === null || $courseTopic->id !== $this->targetTopicId)) {
            $this->notLinked[$courseCode] = ($this->notLinked[$courseCode] ?? 0) + 1;

            return;
        }

        $topic = $courseTopic;

        if (! $topic instanceof Topic) {
            $this->notLinked[$courseCode] = ($this->notLinked[$courseCode] ?? 0) + 1;

            return;
        }

        $role = $this->studentRole();

        if ($role === null || $role->isArchived() || $this->admin === null) {
            $this->roleUnavailable++;

            return;
        }

        // Validate same-branch constraint for additional topics (directly above or directly below, distance > 0)
        $validAdditionalTopics = $this->precomputedAdditionalTopics;
        if ($validAdditionalTopics === null && ! empty($this->additionalTopicIds)) {
            $validAdditionalTopics = Topic::whereIn('id', $this->additionalTopicIds)
                ->where(function ($q) use ($topic) {
                    $q->whereIn('id', DB::table('topic_closure')->where('descendant_id', $topic->id)->where('distance', '>', 0)->select('ancestor_id'))
                        ->orWhereIn('id', DB::table('topic_closure')->where('ancestor_id', $topic->id)->where('distance', '>', 0)->select('descendant_id'));
                })
                ->notArchived()
                ->get();
        }

        $topicsToAssign = collect([$topic])->concat($validAdditionalTopics ?? [])->unique('id');
        $registeredPrimary = false;

        foreach ($topicsToAssign as $target) {
            $already = RoleAssignment::query()->active()
                ->where(['topic_id' => $target->id, 'user_id' => $student->id, 'role_definition_id' => $role->id])
                ->exists();

            if ($already) {
                continue;
            }

            try {
                app(AssignmentService::class)->grant($this->admin, $target, $role, $student, $this->semester->id);
                if ($target->id === $topic->id) {
                    $registeredPrimary = true;
                } else {
                    $this->additionalPlacements++;
                }
            } catch (ValidationException|AuthorizationException) {
                $this->roleUnavailable++;
            }
        }

        if ($registeredPrimary) {
            $this->registrations++;
        }
    }

    protected function summary(): string
    {
        $text = "Successfully processed: {$this->registrations} student-course registrations.";

        if ($this->additionalPlacements > 0) {
            $text .= " Added {$this->additionalPlacements} additional same-branch node memberships.";
        }

        if ($this->unregistered > 0) {
            $text .= " Skipped {$this->unregistered} rows due to unregistered Neptun codes.";
        }

        if ($this->unknownCourses !== []) {
            $text .= ' Skipped '.array_sum($this->unknownCourses).' rows because the course code is unknown ('
                .implode(', ', array_keys($this->unknownCourses)).'). Add the course under its faculty first.';
        }

        if ($this->notLinked !== []) {
            $text .= ' Skipped '.array_sum($this->notLinked).' rows because the course is not linked to a topic ('
                .implode(', ', array_keys($this->notLinked)).'). Link the course to a topic in the topic tree first; students are never attached to another topic automatically.';
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
