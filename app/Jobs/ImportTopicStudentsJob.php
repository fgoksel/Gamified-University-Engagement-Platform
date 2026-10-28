<?php

namespace App\Jobs;

use App\Enums\UserRole;
use App\Models\RoleAssignment;
use App\Models\RoleDefinition;
use App\Models\Semester;
use App\Models\Topic;
use App\Models\User;
use App\Services\AssignmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * "Import students" on a topic (Task #72): a file with one column of Neptun codes.
 *
 * Each student gets the chosen role at the topic and at the topics above that the
 * importer ticked, so a topic above holds the union of the sets imported into it
 * (26 October, Q1). Nothing is added anywhere else, and a student who already has
 * the role at a topic is left as they are, so importing the same file again adds
 * nobody twice.
 *
 * Every role goes through AssignmentService::import(): the importer needs "Import
 * students" at each topic and may only give powers they may pass on there, so topics
 * above their own branch are refused (S3, 27 October). The rights are checked again
 * for every student, because they may change while the file is processed.
 */
class ImportTopicStudentsJob extends ImportCsvJob
{
    private ?Topic $topic = null;

    /** @var Collection<int, Topic>|null Ticked topics above, nearest first */
    private ?Collection $parents = null;

    private ?RoleDefinition $role = null;

    private ?User $importer = null;

    private ?int $semesterId = null;

    /** Students who got the role at the topic itself. */
    private int $added = 0;

    /** Students who already had it there. */
    private int $already = 0;

    /** @var array<int, int> topic id => students who got the role at that topic above */
    private array $addedAbove = [];

    /**
     * @param  int  $importerId  Who started the import; the result is sent to them.
     * @param  list<int>  $parentTopicIds  The topics above that were ticked.
     */
    public function __construct(
        string $path,
        int $importerId,
        public int $topicId,
        public int $roleId,
        public array $parentTopicIds = [],
        string $disk = 'local',
    ) {
        parent::__construct($path, $importerId, $disk);
    }

    protected function requiredColumns(): array
    {
        return ['neptun_code'];
    }

    protected function requiredColumnLabels(): array
    {
        return ['Neptun Code'];
    }

    protected function title(): string
    {
        return 'Student import';
    }

    /**
     * A file with only the Neptun Code column has no delimiter for the reader to find, and
     * it would then take the space in "Neptun Code" for one. Use the heading line's instead.
     */
    protected function csvSettings(): array
    {
        // Not a technical problem to retry: the same missing file would fail again.
        if ($this->path === '' || ! Storage::disk($this->disk)->fileExists($this->path)) {
            throw new InvalidArgumentException('The uploaded file could not be found, so nothing was imported. Please upload it again.');
        }

        $stream = Storage::disk($this->disk)->readStream($this->path);
        $heading = $stream ? (string) fgets($stream) : '';

        if ($stream) {
            fclose($stream);
        }

        return ['delimiter' => match (true) {
            str_contains($heading, ';') => ';',
            str_contains($heading, "\t") => "\t",
            default => ',',
        }];
    }

    protected function prepare(): void
    {
        $this->added = $this->already = 0;
        $this->addedAbove = [];

        $this->topic = Topic::query()->notArchived()->find($this->topicId);
        $this->role = RoleDefinition::query()->usable()->find($this->roleId);
        $this->importer = User::find($this->adminId);

        if ($this->topic === null) {
            throw new InvalidArgumentException('The topic was archived or deleted, so nothing was imported.');
        }

        if ($this->role === null || $this->importer === null) {
            throw new InvalidArgumentException('The chosen role is no longer available, so nothing was imported.');
        }

        // Only topics that are still above this one count.
        $above = array_slice($this->topic->lineageIds(), 1);
        $this->parents = Topic::query()
            ->whereIn('id', array_intersect($this->parentTopicIds, $above))
            ->notArchived()
            ->get()
            ->sortBy(fn (Topic $parent) => array_search($parent->id, $above, true))
            ->values();

        $this->semesterId = Semester::where('status', 'active')->orderByDesc('starts_at')->value('id');
    }

    protected function processRow(array $row, int $line): void
    {
        $neptun = Str::upper(trim((string) ($row['neptun_code'] ?? '')));

        if ($neptun === '') {
            $this->skip($line, 'the Neptun Code is empty.');

            return;
        }

        $student = User::role(UserRole::Student)->where('neptun_code', $neptun)->first();

        if ($student === null) {
            $this->skip($line, "no student account has the Neptun code {$neptun}.");

            return;
        }

        foreach ([$this->topic, ...$this->parents] as $target) {
            $here = $target->id === $this->topic->id;

            if ($this->holds($student, $target)) {
                if ($here) {
                    $this->already++;
                }

                continue;
            }

            try {
                app(AssignmentService::class)->import($this->importer, $target, $this->role, $student, $this->semesterId);
            } catch (ValidationException $e) {
                // About the student (for example an account that is not active yet): the same everywhere.
                $this->skip($line, "{$neptun}: ".collect($e->errors())->flatten()->first());

                return;
            } catch (AuthorizationException $e) {
                if ($here) {
                    $this->skip($line, "{$neptun}: {$e->getMessage()}");

                    return;
                }

                $this->note($line, "{$neptun} was not added to {$target->title}: {$e->getMessage()}");

                continue;
            }

            if ($here) {
                $this->added++;
            } else {
                $this->addedAbove[$target->id] = ($this->addedAbove[$target->id] ?? 0) + 1;
            }
        }
    }

    protected function summary(): string
    {
        $text = "{$this->added} student(s) now have the role \"{$this->role->name}\" in {$this->topic->title}.";

        if ($this->already > 0) {
            $text .= " {$this->already} already had it there.";
        }

        foreach ($this->parents as $parent) {
            $text .= ' Also added to '.$parent->title.': '.($this->addedAbove[$parent->id] ?? 0).'.';
        }

        return $text;
    }

    private function holds(User $student, Topic $topic): bool
    {
        return RoleAssignment::query()->active()
            ->where(['topic_id' => $topic->id, 'user_id' => $student->id, 'role_definition_id' => $this->role->id])
            ->exists();
    }
}
