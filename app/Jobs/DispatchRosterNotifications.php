<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\Roster;
use App\Models\RosterChange;
use App\Notifications\RosterChangeNotification;
use App\Notifications\RosterPublishedNotification;
use App\Services\RosterChangeSummaryBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Post-commit delivery of roster notifications.
 *
 * The database rows were written synchronously inside the transaction; this
 * job exists purely to fan out the realtime (broadcast) and mobile (FCM) push
 * channels after the transaction has committed. Because it runs on the queue,
 * a push failure can never block or roll back the roster publish / change.
 *
 * The job is unique per (roster, version). The roster `version` is bumped
 * exactly once per business event, so a duplicate dispatch for the same event
 * is collapsed by the queue's unique lock instead of re-notifying every
 * employee. Combined with the per-recipient `dedupe_key` on the notification
 * and the FCM send-level marker, one business event produces exactly one push
 * per device even if the job is retried.
 */
class DispatchRosterNotifications implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    /**
     * Seconds the unique lock is held. Long enough to cover a retry window,
     * short enough that a later event (new version) is never blocked.
     */
    public int $uniqueFor = 3600;

    /**
     * @param  list<int>  $employeeIds
     * @param  list<int>  $changeIds
     */
    public function __construct(
        public int $rosterId,
        public array $employeeIds = [],
        public array $changeIds = [],
        public bool $isPublish = false,
        public int $version = 0,
    ) {}

    /**
     * Unique key for the queue lock: one dispatch per roster event.
     */
    public function uniqueId(): string
    {
        return "roster-notify:{$this->rosterId}:v{$this->version}";
    }

    /**
     * Handle a job failure.
     *
     * Push/broadcast delivery is best-effort and must never surface to the
     * user, but a silent failure is how the v7/v8 FCM API break went
     * unnoticed. Log it loudly so it is visible in the queue worker output
     * and the application log.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('DispatchRosterNotifications failed.', [
            'roster_id' => $this->rosterId,
            'version' => $this->version,
            'is_publish' => $this->isPublish,
            'employee_count' => count($this->employeeIds),
            'change_count' => count($this->changeIds),
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * Execute the job.
     */
    public function handle(RosterChangeSummaryBuilder $summaryBuilder): void
    {
        $roster = Roster::query()->find($this->rosterId);

        if ($roster === null) {
            return;
        }

        if (empty($this->employeeIds)) {
            return;
        }

        $employees = Employee::query()
            ->whereIn('id', $this->employeeIds)
            ->with('user')
            ->get()
            ->reject(fn (Employee $employee) => $employee->user === null);

        if ($this->isPublish) {
            $employees->each(function (Employee $employee) use ($roster) {
                $notification = new RosterPublishedNotification(
                    $roster,
                    $this->dedupeKey($employee),
                );

                $employee->user->notify($notification);
            });

            return;
        }

        $changesByEmployee = RosterChange::query()
            ->whereIn('id', $this->changeIds)
            ->get()
            ->groupBy('employee_id');

        $employees->each(function (Employee $employee) use ($roster, $changesByEmployee, $summaryBuilder) {
            $changes = $changesByEmployee->get($employee->id, collect());

            if ($changes->isEmpty()) {
                return;
            }

            $changes = $changes instanceof Collection ? $changes : collect($changes);
            $summary = $summaryBuilder->build($changes);

            $notification = new RosterChangeNotification(
                $roster,
                $changes,
                $summary['title'],
                $summary['body'],
                $this->dedupeKey($employee),
            );

            $employee->user->notify($notification);
        });
    }

    /**
     * Stable business key for one (roster event, employee) pair.
     *
     * Mirrors {@see \App\Services\RosterChangeService::dedupeKey()} so the
     * database row and the push share the same identity.
     */
    protected function dedupeKey(Employee $employee): string
    {
        return "roster:{$this->rosterId}:v{$this->version}:emp:{$employee->id}";
    }
}
