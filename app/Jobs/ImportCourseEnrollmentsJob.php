<?php

namespace App\Jobs;

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
 * Every student is found by Neptun code and added as a student on the
 * course in its faculty's tree, for the semester (default: the active
 * semester). Courses are not created here: they are created only under
 * their faculty on the Faculties page. Rows are skipped and counted when the
 * Neptun code is unknown, the course code is unknown, the course's faculty
 * has no tree yet, or the student is a tutor in that course. A repeated
 * import adds nothing twice, and nothing is ever deleted.
 */
class ImportCourseEnrollmentsJob extends ImportCsvJob
{
    private ?Semester $semester = null;

    private ?User $admin = null;

    private int $registrations = 0;

    private int $unregistered = 0;

    private int $tutors = 0;

    /** @var array<string, int> Unknown course code => rows */
    private array $unknownCourses = [];

    /** @var array<string, int> Code of a course whose faculty has no tree => rows */
    private array $notInTree = [];

    /** @var array<string, int|null> Neptun code => student id */
    private array $students = [];

    /** @var array<string, Course|null> course code => course */
    private array $courses = [];

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
        $this->registrations = $this->unregistered = $this->tutors = 0;
        $this->students = $this->courses = $this->unknownCourses = $this->notInTree = [];

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

        $courseUnit = $course->courseUnit;

        if (! $courseUnit instanceof TreeUnit) {
            $this->notInTree[$courseCode] = ($this->notInTree[$courseCode] ?? 0) + 1;

            return;
        }

        match (app(TreeService::class)->importStudent($courseUnit, $student, $this->semester, $this->admin)) {
            'added' => $this->registrations++,
            'staff' => $this->tutors++,
            'already' => null,
        };
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

        if ($this->notInTree !== []) {
            $text .= ' Skipped '.array_sum($this->notInTree).' rows because the faculty of the course has no tree yet ('
                .implode(', ', array_keys($this->notInTree)).').';
        }

        if ($this->tutors > 0) {
            $text .= " Skipped {$this->tutors} rows because the student is a tutor in that course.";
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
            $this->courses[$code] = Course::with('courseUnit')->where('code', $code)->first();
        }

        return $this->courses[$code];
    }
}
