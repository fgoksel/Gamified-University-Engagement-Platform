<?php

namespace App\Jobs;

use App\Enums\UserRole;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Import of the Neptun course enrolment file (UC-3.2.3, Technical Specification 7.3.1).
 *
 * Columns: Neptun Code, Course Code, Course Name (all required).
 * Missing courses are created, every student is found by Neptun code and linked to
 * the course for the semester (default: the active semester). Rows with an unknown
 * Neptun code are skipped and counted. The unique key (student, course, semester)
 * makes a repeated import safe (Technical Specification 6.2.13).
 */
class ImportCourseEnrollmentsJob extends ImportCsvJob
{
    private int $targetSemesterId = 0;

    private int $newCourses = 0;

    private int $registrations = 0;

    private int $unregistered = 0;

    /** @var array<string, int> Neptun code => student id */
    private array $students = [];

    /** @var array<string, int> course code => course id */
    private array $courses = [];

    /**
     * @param  bool  $deletePreviousSemesterData  The "Delete previous semester's course data?" checkbox (checked by default).
     * @param  int|null  $semesterId  Semester of the enrolments; the active semester when null.
     */
    public function __construct(
        string $path,
        int $adminId,
        public readonly bool $deletePreviousSemesterData = true,
        public readonly ?int $semesterId = null,
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
        $this->newCourses = $this->registrations = $this->unregistered = 0;
        $this->students = $this->courses = [];

        $semester = $this->semesterId !== null
            ? Semester::find($this->semesterId)
            : Semester::where('status', 'active')->first();

        if ($semester === null) {
            throw new InvalidArgumentException('There is no active semester to link the courses to.');
        }

        $this->targetSemesterId = $semester->id;

        if ($this->deletePreviousSemesterData) {
            DB::table('student_course_enrollments')->where('semester_id', '!=', $this->targetSemesterId)->delete();
        }
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

        $studentId = $this->studentId($neptun);

        if ($studentId === null) {
            $this->unregistered++;

            return;
        }

        $inserted = DB::table('student_course_enrollments')->insertOrIgnore([
            'student_id' => $studentId,
            'course_id' => $this->courseId($courseCode, $courseName),
            'semester_id' => $this->targetSemesterId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->registrations += $inserted;
    }

    protected function summary(): string
    {
        $text = "Successfully processed: {$this->newCourses} new courses, {$this->registrations} student-course registrations.";

        if ($this->unregistered > 0) {
            $text .= " Skipped {$this->unregistered} rows due to unregistered Neptun codes.";
        }

        return $text;
    }

    private function studentId(string $neptun): ?int
    {
        if (! array_key_exists($neptun, $this->students)) {
            $this->students[$neptun] = User::role(UserRole::Student)->where('neptun_code', $neptun)->value('id');
        }

        return $this->students[$neptun];
    }

    private function courseId(string $code, string $name): int
    {
        if (! isset($this->courses[$code])) {
            $id = DB::table('courses')->where('code', $code)->value('id');

            if ($id === null) {
                $id = DB::table('courses')->insertGetId([
                    'code' => $code,
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->newCourses++;
            }

            $this->courses[$code] = $id;
        }

        return $this->courses[$code];
    }
}
