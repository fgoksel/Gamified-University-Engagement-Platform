<?php

namespace App\Jobs;

use App\Enums\TreeUnitKind;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Semester;
use App\Models\TreeUnit;
use App\Models\User;
use App\Services\TreeService;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Import of the Neptun course enrolment file (UC-3.2.3, Technical Specification 7.3.1).
 *
 * Columns: Neptun Code, Course Code, Course Name (all required).
 * Missing courses are created. Every student is found by Neptun code and
 * added as a student on the course's subject in the subject tree, for the
 * semester (default: the active semester). Rows are skipped and counted when
 * the Neptun code is unknown, the course is not in a tree yet, or the student
 * is a tutor in that subject. A repeated import adds nothing twice, and
 * nothing is ever deleted (subject tree, build step 4).
 */
class ImportCourseEnrollmentsJob extends ImportCsvJob
{
    private ?Semester $semester = null;

    private ?User $admin = null;

    private int $newCourses = 0;

    private int $registrations = 0;

    private int $unregistered = 0;

    private int $tutors = 0;

    private int $notInTreeRows = 0;

    /** @var array<string, true> Codes of courses that are in no tree yet. */
    private array $notInTree = [];

    /** @var array<string, int|null> Neptun code => student id */
    private array $students = [];

    /** @var array<string, int> course code => course id */
    private array $courses = [];

    /** @var array<int, TreeUnit|null> course id => subject unit */
    private array $subjects = [];

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
        $this->newCourses = $this->registrations = $this->unregistered = $this->tutors = $this->notInTreeRows = 0;
        $this->students = $this->courses = $this->subjects = $this->notInTree = [];

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
        $courseCode = (string) ($row['course_code'] ?? '');
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

        $subject = $this->subject($this->courseId($courseCode, $courseName));

        if ($subject === null) {
            $this->notInTree[$courseCode] = true;
            $this->notInTreeRows++;

            return;
        }

        match (app(TreeService::class)->importStudent($subject, $student, $this->semester, $this->admin)) {
            'added' => $this->registrations++,
            'staff' => $this->tutors++,
            'already' => null,
        };
    }

    protected function summary(): string
    {
        $text = "Successfully processed: {$this->newCourses} new courses, {$this->registrations} student-course registrations.";

        if ($this->unregistered > 0) {
            $text .= " Skipped {$this->unregistered} rows due to unregistered Neptun codes.";
        }

        if ($this->notInTreeRows > 0) {
            $codes = implode(', ', array_keys($this->notInTree));
            $text .= " Skipped {$this->notInTreeRows} rows because the course is not in a subject tree yet ({$codes}). Add the course to a tree and import the file again.";
        }

        if ($this->tutors > 0) {
            $text .= " Skipped {$this->tutors} rows because the student is a tutor in that subject.";
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

    private function courseId(string $code, string $name): int
    {
        if (! isset($this->courses[$code])) {
            $course = Course::firstOrCreate(['code' => $code], ['name' => $name]);

            if ($course->wasRecentlyCreated) {
                $this->newCourses++;
            }

            $this->courses[$code] = $course->id;
        }

        return $this->courses[$code];
    }

    private function subject(int $courseId): ?TreeUnit
    {
        if (! array_key_exists($courseId, $this->subjects)) {
            $this->subjects[$courseId] = TreeUnit::where('course_id', $courseId)
                ->where('kind', TreeUnitKind::Subject)
                ->first();
        }

        return $this->subjects[$courseId];
    }
}
