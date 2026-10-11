<?php

namespace Tests\Unit\Services;

use App\Enums\RosterChangeType;
use App\Models\RosterChange;
use App\Services\RosterChangeSummaryBuilder;
use Tests\TestCase;

/**
 * Guards the wording of roster change notifications.
 *
 * The body must state the exact change (added / cancelled / changed /
 * reassigned / location moved) rather than the old generic
 * "N changes to your roster" string, and the same builder feeds both the
 * database row and the FCM push so the two can never drift.
 */
class RosterChangeSummaryBuilderTest extends TestCase
{
    private function builder(): RosterChangeSummaryBuilder
    {
        return new RosterChangeSummaryBuilder;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function change(string $action, ?array $old = null, ?array $new = null): RosterChange
    {
        return new RosterChange([
            'action' => $action,
            'old_data' => $old,
            'new_data' => $new,
        ]);
    }

    public function test_a_new_shift_is_described_as_added_with_its_time(): void
    {
        $summary = $this->builder()->build(collect([
            $this->change(RosterChangeType::ShiftAdded->value, null, [
                'date' => '2026-10-06',
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
            ]),
        ]));

        $this->assertSame('New shift added', $summary['title']);
        $this->assertStringContainsString('New shift', $summary['body']);
        $this->assertStringContainsString('09:00-17:00', $summary['body']);
    }

    public function test_a_cancellation_is_described_as_cancelled(): void
    {
        $summary = $this->builder()->build(collect([
            $this->change(RosterChangeType::ShiftCancelled->value, [
                'date' => '2026-10-06',
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
            ], [
                'date' => '2026-10-06',
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'status' => 'cancelled',
            ]),
        ]));

        $this->assertSame('Shift cancelled', $summary['title']);
        $this->assertStringContainsString('cancelled', $summary['body']);
        $this->assertStringContainsString('09:00-17:00', $summary['body']);
    }

    public function test_a_time_change_states_the_before_and_after(): void
    {
        $summary = $this->builder()->build(collect([
            $this->change(RosterChangeType::ShiftUpdated->value, [
                'date' => '2026-10-06',
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
            ], [
                'date' => '2026-10-06',
                'start_time' => '10:00:00',
                'end_time' => '18:00:00',
            ]),
        ]));

        $this->assertSame('Shift changed', $summary['title']);
        $this->assertStringContainsString('09:00-17:00', $summary['body']);
        $this->assertStringContainsString('10:00-18:00', $summary['body']);
    }

    public function test_a_reassignment_is_described_as_removed(): void
    {
        $summary = $this->builder()->build(collect([
            $this->change(RosterChangeType::ShiftReassigned->value, [
                'date' => '2026-10-06',
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
            ], [
                'date' => '2026-10-06',
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'employee_id' => 99,
            ]),
        ]));

        $this->assertSame('Shift removed', $summary['title']);
        $this->assertStringContainsString('removed', $summary['body']);
    }

    public function test_a_new_assignment_is_described_as_assigned(): void
    {
        $summary = $this->builder()->build(collect([
            $this->change(RosterChangeType::ShiftAssigned->value, null, [
                'date' => '2026-10-06',
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
            ]),
        ]));

        $this->assertSame('New shift assigned', $summary['title']);
        $this->assertStringContainsString('assigned', $summary['body']);
    }

    public function test_a_branch_move_is_described_as_a_location_change(): void
    {
        $summary = $this->builder()->build(collect([
            $this->change(RosterChangeType::ShiftLocationChanged->value, [
                'date' => '2026-10-06',
                'branch_id' => 1,
            ], [
                'date' => '2026-10-06',
                'branch_id' => 2,
            ]),
        ]));

        $this->assertSame('Shift location changed', $summary['title']);
        $this->assertStringContainsString('different branch', $summary['body']);
    }

    public function test_multiple_changes_are_grouped_into_one_notification(): void
    {
        $summary = $this->builder()->build(collect([
            $this->change(RosterChangeType::ShiftAdded->value, null, [
                'date' => '2026-10-06',
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
            ]),
            $this->change(RosterChangeType::ShiftCancelled->value, [
                'date' => '2026-10-07',
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
            ], [
                'date' => '2026-10-07',
                'status' => 'cancelled',
            ]),
        ]));

        $this->assertSame('2 changes to your roster', $summary['title']);
        $this->assertStringContainsString(';', $summary['body']);
        $this->assertStringContainsString('New shift', $summary['body']);
        $this->assertStringContainsString('cancelled', $summary['body']);
    }

    public function test_an_empty_change_set_falls_back_to_a_generic_message(): void
    {
        $summary = $this->builder()->build(collect());

        $this->assertSame('Roster updated', $summary['title']);
        $this->assertNotSame('', $summary['body']);
    }
}
