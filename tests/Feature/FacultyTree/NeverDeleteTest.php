<?php

namespace Tests\Feature\FacultyTree;

use App\Enums\TreeRole;
use App\Models\TreeUnit;

/**
 * Rule 8: roles are ended, never deleted. Only the admin may delete a unit,
 * and only one that never had anything attached.
 */
class NeverDeleteTest extends FacultyTreeTestCase
{
    public function test_ending_a_role_keeps_the_record_with_a_date_and_a_reason(): void
    {
        $record = $this->add($this->teacher, $this->database, TreeRole::Student);

        $this->tree->endMembership($this->teacher, $record, 'Left the course');

        $record->refresh();
        $this->assertFalse($record->isActive());
        $this->assertNotNull($record->ended_at);
        $this->assertSame('Left the course', $record->ended_reason);
        $this->assertSame(1, $this->database->memberships()->where('user_id', $record->user_id)->count());
    }

    public function test_a_role_needs_a_reason_and_ends_only_once(): void
    {
        $record = $this->add($this->teacher, $this->database, TreeRole::Student);

        $this->assertRejected(fn () => $this->tree->endMembership($this->teacher, $record, '   '), 'ended_reason');

        $this->tree->endMembership($this->teacher, $record, 'Left the course');
        $this->assertRejected(fn () => $this->tree->endMembership($this->teacher, $record, 'Again'), 'membership');
    }

    public function test_the_admin_can_end_any_role(): void
    {
        $deanRecord = $this->root->memberships()->where('user_id', $this->dean->id)->first();

        $this->tree->endMembership($this->admin, $deanRecord, 'Retired');

        $this->assertFalse($deanRecord->fresh()->isActive());
    }

    public function test_only_the_admin_deletes_an_empty_unit(): void
    {
        $mistake = $this->tree->addSubtopic($this->teacher, $this->database, 'Typo');

        $this->assertForbidden(fn () => $this->tree->deleteUnit($this->teacher, $mistake));

        $this->tree->deleteUnit($this->admin, $mistake);
        $this->assertNull(TreeUnit::find($mistake->id));
    }

    public function test_a_unit_that_ever_had_someone_on_it_is_kept(): void
    {
        $record = $this->add($this->teacher, $this->sql, TreeRole::Tutor);
        $this->tree->endMembership($this->teacher, $record, 'End of semester');

        $this->assertRejected(fn () => $this->tree->deleteUnit($this->admin, $this->sql), 'unit');
    }

    public function test_a_unit_with_units_below_it_is_kept(): void
    {
        $this->tree->addSubtopic($this->teacher, $this->sql, 'Joins');

        $this->assertRejected(fn () => $this->tree->deleteUnit($this->admin, $this->sql), 'unit');

        // Networks has no units below it and nobody was ever on it.
        $this->tree->deleteUnit($this->admin, $this->networks);
        $this->assertNull(TreeUnit::find($this->networks->id));
    }
}
