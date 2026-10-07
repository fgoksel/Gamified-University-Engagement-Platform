<?php

namespace App\Support;

use App\Enums\Capability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copies the old faculty tree (tree_units, unit_memberships) into topics and
 * role assignments. See docs/topic-tree.md for the mapping and its decisions.
 *
 * - Ids are preserved: topic id = tree_unit id, assignment id = membership id,
 *   and legacy_tree_unit_id keeps the trace.
 * - The legacy tables are never changed or dropped, so the upgrade can be
 *   checked against them and a rollback loses nothing.
 * - Legacy role labels become ordinary role definitions, only for roles that
 *   existing records use. The names have no special meaning afterwards.
 * - Safe to run twice: rows that were already copied are skipped.
 */
class LegacyTreeUpgrade
{
    /**
     * Legacy label => name, capabilities and what the holder may pass on.
     *
     * Ranks are gone: the dean, teacher and co-teacher tiers differ only in
     * what they can pass on. Tutor event rights (create events, select
     * applicants, credit points) belong to later work, so they are kept on the
     * assignment as history and grant no power.
     *
     * @var array<string, array{name: string, capabilities: list<Capability>, delegable: list<Capability>}>
     */
    public const ROLES = [
        'dean' => [
            'name' => 'Dean',
            'capabilities' => [Capability::View, Capability::Create, Capability::AccessView, Capability::AccessAssign, Capability::AccessEnd],
            'delegable' => [Capability::View, Capability::Create, Capability::AccessView, Capability::AccessAssign, Capability::AccessEnd],
        ],
        'teacher' => [
            'name' => 'Teacher',
            'capabilities' => [Capability::View, Capability::Create, Capability::AccessView, Capability::AccessAssign, Capability::AccessEnd],
            'delegable' => [Capability::View, Capability::Create, Capability::AccessView, Capability::AccessAssign, Capability::AccessEnd],
        ],
        'co_teacher' => [
            'name' => 'Co-teacher',
            'capabilities' => [Capability::View, Capability::Create, Capability::AccessView, Capability::AccessAssign, Capability::AccessEnd],
            'delegable' => [Capability::View],
        ],
        'tutor' => [
            'name' => 'Student tutor',
            'capabilities' => [Capability::View],
            'delegable' => [],
        ],
        'student' => [
            'name' => 'Student',
            'capabilities' => [Capability::View],
            'delegable' => [],
        ],
    ];

    /**
     * @return array{topics: int, closure_rows: int, assignments: int, role_definitions: int}
     */
    public function run(): array
    {
        $summary = ['topics' => 0, 'closure_rows' => 0, 'assignments' => 0, 'role_definitions' => 0];

        if (! Schema::hasTable('tree_units') || ! Schema::hasTable('unit_memberships')) {
            return $summary;
        }

        DB::transaction(function () use (&$summary) {
            $summary = $this->copyTopics($summary);
            $summary = $this->copyAssignments($summary);
        });

        return $summary;
    }

    /**
     * The definition for one legacy label, created on first use.
     */
    public static function ensureRoleDefinition(string $legacyKey): int
    {
        $existing = DB::table('role_definitions')->where('legacy_key', $legacyKey)->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $role = self::ROLES[$legacyKey];

        return (int) DB::table('role_definitions')->insertGetId([
            'name' => $role['name'],
            'description' => 'Created from the previous faculty tree. An ordinary role: edit or archive it like any other.',
            'topic_id' => null,
            'capabilities' => json_encode(array_map(fn (Capability $c) => $c->value, $role['capabilities'])),
            'delegable' => json_encode(array_map(fn (Capability $c) => $c->value, $role['delegable'])),
            'legacy_key' => $legacyKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array{topics: int, closure_rows: int, assignments: int, role_definitions: int}  $summary
     * @return array{topics: int, closure_rows: int, assignments: int, role_definitions: int}
     */
    private function copyTopics(array $summary): array
    {
        $units = DB::table('tree_units')->get()->keyBy('id');
        $done = DB::table('topics')->whereNotNull('legacy_tree_unit_id')->pluck('id')->flip();

        // Parents first: sort by the number of ids in the stored path.
        $ordered = $units->sortBy(fn ($unit) => count(array_filter(explode('/', (string) $unit->path))))->values();

        foreach ($ordered as $unit) {
            if ($done->has($unit->id)) {
                continue;
            }

            $ancestors = $this->ancestorIds($unit, $units);

            DB::table('topics')->insert([
                'id' => $unit->id,
                'parent_id' => $unit->parent_id,
                'title' => $unit->title,
                'depth' => count($ancestors),
                'created_by_id' => $unit->created_by_id,
                'legacy_tree_unit_id' => $unit->id,
                'course_id' => $unit->course_id,
                'subject_area_id' => $unit->subject_area_id,
                'created_at' => $unit->created_at,
                'updated_at' => $unit->updated_at,
            ]);
            $summary['topics']++;

            $rows = [['ancestor_id' => $unit->id, 'descendant_id' => $unit->id, 'distance' => 0]];
            foreach ($ancestors as $distance => $ancestorId) {
                $rows[] = ['ancestor_id' => $ancestorId, 'descendant_id' => $unit->id, 'distance' => $distance + 1];
            }
            DB::table('topic_closure')->insert($rows);
            $summary['closure_rows'] += count($rows);
        }

        return $summary;
    }

    /**
     * Ancestor ids of a unit from its parent up to the root, following parent_id.
     *
     * @return list<int>
     */
    private function ancestorIds(object $unit, $units): array
    {
        $ids = [];
        $seen = [$unit->id => true];
        $parentId = $unit->parent_id;

        while ($parentId !== null) {
            if (isset($seen[$parentId])) {
                throw new \RuntimeException("The legacy tree has a cycle at unit {$parentId}. Repair it before upgrading.");
            }
            $seen[$parentId] = true;
            $ids[] = (int) $parentId;
            $parentId = $units[$parentId]->parent_id ?? null;
        }

        return $ids;
    }

    /**
     * @param  array{topics: int, closure_rows: int, assignments: int, role_definitions: int}  $summary
     * @return array{topics: int, closure_rows: int, assignments: int, role_definitions: int}
     */
    private function copyAssignments(array $summary): array
    {
        $existingDefinitions = DB::table('role_definitions')->whereNotNull('legacy_key')->count();
        $definitionIds = [];

        foreach (DB::table('unit_memberships')->orderBy('id')->get() as $membership) {
            if (DB::table('role_assignments')->where('id', $membership->id)->exists()) {
                continue;
            }

            $definitionIds[$membership->role] ??= self::ensureRoleDefinition($membership->role);

            DB::table('role_assignments')->insert([
                'id' => $membership->id,
                'topic_id' => $membership->unit_id,
                'user_id' => $membership->user_id,
                'role_definition_id' => $definitionIds[$membership->role],
                'granted_by_id' => $membership->added_by_id,
                'authority_snapshot' => json_encode(['via' => 'legacy_tree', 'legacy_role' => $membership->role]),
                'started_at' => $membership->started_at,
                'ended_at' => $membership->ended_at,
                'ended_reason' => $membership->ended_reason,
                'semester_id' => $membership->semester_id,
                'manual' => $membership->manual,
                'legacy_permissions' => $membership->permissions,
                'created_at' => $membership->created_at,
                'updated_at' => $membership->updated_at,
            ]);
            $summary['assignments']++;
        }

        $summary['role_definitions'] = DB::table('role_definitions')->whereNotNull('legacy_key')->count() - $existingDefinitions;

        return $summary;
    }
}
