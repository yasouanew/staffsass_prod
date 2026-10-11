<?php

namespace App\Services;

use App\Enums\RosterChangeType;
use App\Models\RosterChange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the human-readable title/body for a roster change notification.
 *
 * This is the single source of truth for the wording of both the persisted
 * database notification row and the FCM push payload. Previously the summary
 * was duplicated in {@see RosterChangeService::summaryFor()} and
 * {@see \App\Jobs\DispatchRosterNotifications::summaryFor()}, which meant the
 * inbox row and the push could drift apart. Both now call this builder.
 *
 * The builder is pure: it only reads the `action` / `old_data` / `new_data`
 * snapshots already stored on each {@see RosterChange} row, so the preview,
 * the audit history and the notification always describe the same change.
 */
class RosterChangeSummaryBuilder
{
    /**
     * Build the title and body for a group of changes affecting one employee.
     *
     * A single change produces a specific sentence ("Your shift on Mon 6 Oct
     * was cancelled."); multiple changes are collapsed into one grouped
     * notification so an employee never receives a burst of pushes for a single
     * save.
     *
     * @param  Collection<int, RosterChange>  $changes
     * @return array{title: string, body: string}
     */
    public function build(Collection $changes): array
    {
        $changes = $changes->values();

        if ($changes->isEmpty()) {
            return [
                'title' => 'Roster updated',
                'body' => 'Your roster has been updated.',
            ];
        }

        if ($changes->count() === 1) {
            $change = $changes->first();

            return [
                'title' => $this->title($change),
                'body' => $this->line($change),
            ];
        }

        $lines = $changes
            ->map(fn (RosterChange $change) => $this->line($change))
            ->filter()
            ->values();

        return [
            'title' => $lines->count().' changes to your roster',
            'body' => $lines->implode('; '),
        ];
    }

    /**
     * Short, action-specific notification title.
     */
    public function title(RosterChange $change): string
    {
        return match ($change->action) {
            RosterChangeType::ShiftAdded->value => 'New shift added',
            RosterChangeType::ShiftUpdated->value => 'Shift changed',
            RosterChangeType::ShiftCancelled->value => 'Shift cancelled',
            RosterChangeType::ShiftReassigned->value => 'Shift removed',
            RosterChangeType::ShiftAssigned->value => 'New shift assigned',
            RosterChangeType::ShiftLocationChanged->value => 'Shift location changed',
            RosterChangeType::RosterPublished->value => 'Roster published',
            RosterChangeType::RosterUpdated->value => 'Roster updated',
            default => 'Roster updated',
        };
    }

    /**
     * One precise sentence describing a single change.
     *
     * The wording is derived from the before/after snapshots so it states the
     * exact effect: a new shift, a cancellation, a time change, a reassignment
     * or a branch move.
     */
    public function line(RosterChange $change): string
    {
        $old = is_array($change->old_data) ? $change->old_data : [];
        $new = is_array($change->new_data) ? $change->new_data : [];

        $when = $this->formatDate($new['date'] ?? $old['date'] ?? null);
        $oldTime = $this->formatTimeRange($old);
        $newTime = $this->formatTimeRange($new);

        return match ($change->action) {
            RosterChangeType::ShiftAdded->value => trim("New shift {$when}".$this->suffix($newTime)),

            RosterChangeType::ShiftCancelled->value => trim("Your shift {$when}".$this->suffix($oldTime).' has been cancelled'),

            RosterChangeType::ShiftReassigned->value => trim("You were removed from the shift {$when}".$this->suffix($oldTime)),

            RosterChangeType::ShiftAssigned->value => trim("You have been assigned a shift {$when}".$this->suffix($newTime)),

            RosterChangeType::ShiftLocationChanged->value => trim("Your shift {$when} moved to a different branch"),

            RosterChangeType::ShiftUpdated->value => $this->updatedLine($when, $oldTime, $newTime),

            RosterChangeType::RosterPublished->value => 'Your roster has been published.',

            default => trim("Your roster was updated {$when}"),
        };
    }

    /**
     * Describe a plain update, calling out the specific field that changed.
     */
    protected function updatedLine(string $when, ?string $oldTime, ?string $newTime): string
    {
        if ($oldTime !== null && $newTime !== null && $oldTime !== $newTime) {
            return trim("Your shift {$when} changed from {$oldTime} to {$newTime}");
        }

        if ($newTime !== null) {
            return trim("Your shift {$when} was updated to {$newTime}");
        }

        return trim("Your shift {$when} was updated");
    }

    /**
     * Format a `H:i:s` / `H:i` pair into "09:00-17:00", or null when absent.
     *
     * @param  array<string, mixed>  $data
     */
    protected function formatTimeRange(array $data): ?string
    {
        $start = $this->formatTime($data['start_time'] ?? null);
        $end = $this->formatTime($data['end_time'] ?? null);

        if ($start === null && $end === null) {
            return null;
        }

        return ($start ?? '?').'-'.($end ?? '?');
    }

    /**
     * Normalise a stored time to `H:i`.
     */
    protected function formatTime(mixed $time): ?string
    {
        if (! is_string($time) || $time === '') {
            return null;
        }

        return substr($time, 0, 5);
    }

    /**
     * Format a stored `Y-m-d` date as "Mon 6 Oct", or an empty string.
     */
    protected function formatDate(mixed $date): string
    {
        if (! is_string($date) || $date === '') {
            return '';
        }

        try {
            return Carbon::parse($date)->format('D j M');
        } catch (\Throwable) {
            return $date;
        }
    }

    /**
     * Render a time range as a leading ", 09:00-17:00" fragment.
     */
    protected function suffix(?string $timeRange): string
    {
        return $timeRange !== null ? ", {$timeRange}" : '';
    }
}
