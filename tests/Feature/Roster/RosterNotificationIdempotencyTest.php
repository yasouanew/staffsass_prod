<?php

namespace Tests\Feature\Roster;

use App\Jobs\DispatchRosterNotifications;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Roster;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\RosterChangeNotification;
use App\Services\RosterChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Guards the idempotency of roster change notifications.
 *
 * One business event must produce exactly one notification row per intended
 * recipient, and a replayed dispatch must not create a second row or a second
 * push. The stable business key is `roster:{id}:v{version}:emp:{employee}`.
 */
class RosterNotificationIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['roster.view', 'roster.create', 'roster.edit', 'roster.delete', 'roster.publish'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $superAdmin = Role::findOrCreate('super_admin', 'web');
        $superAdmin->syncPermissions(Permission::all());
    }

    protected function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Build a published roster with one employee + user + one assigned shift.
     *
     * @return array<string, mixed>
     */
    protected function makePublishedRoster(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->create(['company_id' => $company->id]);
        $position = Position::factory()->create(['company_id' => $company->id]);

        $employeeUser = User::factory()->create(['company_id' => $company->id]);
        $employee = Employee::factory()->create([
            'company_id' => $company->id,
            'user_id' => $employeeUser->id,
            'branch_id' => $branch->id,
            'position_id' => $position->id,
        ]);

        $roster = Roster::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'status' => 'published',
            'published_at' => now(),
            'published_by' => $employeeUser->id,
        ]);

        $shift = Shift::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'roster_id' => $roster->id,
            'employee_id' => $employee->id,
            'position_id' => $position->id,
            'date' => $roster->week_start,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'status' => 'scheduled',
        ]);

        return compact('company', 'branch', 'position', 'employeeUser', 'employee', 'roster', 'shift');
    }

    public function test_one_business_event_creates_exactly_one_notification_row_per_recipient(): void
    {
        $this->actingAsSuperAdmin();
        ['roster' => $roster, 'shift' => $shift, 'employeeUser' => $employeeUser] = $this->makePublishedRoster();

        $this->postJson("/api/v1/rosters/{$roster->id}/changes/apply", [
            'version' => $roster->version,
            'mutations' => [['type' => 'cancel', 'id' => $shift->id]],
        ])->assertOk();

        $this->assertSame(
            1,
            $employeeUser->notifications()->count(),
            'One business event must create exactly one notification row per recipient.'
        );
    }

    public function test_the_notification_row_carries_a_stable_dedupe_key(): void
    {
        $this->actingAsSuperAdmin();
        ['roster' => $roster, 'shift' => $shift, 'employee' => $employee, 'employeeUser' => $employeeUser] = $this->makePublishedRoster();

        $this->postJson("/api/v1/rosters/{$roster->id}/changes/apply", [
            'version' => $roster->version,
            'mutations' => [['type' => 'cancel', 'id' => $shift->id]],
        ])->assertOk();

        $expected = "roster:{$roster->id}:v".($roster->version + 1).":emp:{$employee->id}";

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $employeeUser->id,
            'dedupe_key' => $expected,
        ]);
    }

    public function test_the_unique_dedupe_key_prevents_a_duplicate_notification_row(): void
    {
        $this->actingAsSuperAdmin();
        ['roster' => $roster, 'shift' => $shift, 'employee' => $employee, 'employeeUser' => $employeeUser] = $this->makePublishedRoster();

        $this->postJson("/api/v1/rosters/{$roster->id}/changes/apply", [
            'version' => $roster->version,
            'mutations' => [['type' => 'cancel', 'id' => $shift->id]],
        ])->assertOk();

        $this->assertSame(1, $employeeUser->notifications()->count());

        $key = "roster:{$roster->id}:v".($roster->version + 1).":emp:{$employee->id}";

        // Simulate a replayed write for the SAME business event: firstOrCreate
        // against the unique dedupe_key must be a no-op, not a second row.
        $employeeUser->notifications()->firstOrCreate(
            ['dedupe_key' => $key],
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => RosterChangeNotification::class,
                'data' => ['title' => 'duplicate', 'body' => 'duplicate'],
            ],
        );

        $this->assertSame(
            1,
            $employeeUser->notifications()->count(),
            'A replayed write for the same business event must not create a second row.'
        );
    }

    public function test_a_new_version_is_a_new_event_and_creates_a_new_row(): void
    {
        $this->actingAsSuperAdmin();
        ['roster' => $roster, 'shift' => $shift, 'employeeUser' => $employeeUser] = $this->makePublishedRoster();

        $this->postJson("/api/v1/rosters/{$roster->id}/changes/apply", [
            'version' => $roster->version,
            'mutations' => [['type' => 'cancel', 'id' => $shift->id]],
        ])->assertOk();

        $this->assertSame(1, $employeeUser->notifications()->count());

        // A genuinely new event (new version) legitimately produces a new row.
        app(RosterChangeService::class)->apply(
            $roster->fresh(),
            [['type' => 'update', 'id' => $shift->id, 'shift' => ['status' => 'scheduled']]],
            null,
            null,
        );

        $this->assertSame(2, $employeeUser->notifications()->count());
    }

    public function test_the_dispatch_job_is_unique_per_roster_version(): void
    {
        $job = new DispatchRosterNotifications(
            rosterId: 12,
            employeeIds: [1, 2],
            changeIds: [5],
            isPublish: false,
            version: 4,
        );

        $this->assertSame('roster-notify:12:v4', $job->uniqueId());
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class, $job);
    }

    public function test_the_notification_body_describes_the_exact_change(): void
    {
        Notification::fake();
        $this->actingAsSuperAdmin();
        ['roster' => $roster, 'shift' => $shift, 'employeeUser' => $employeeUser] = $this->makePublishedRoster();

        $this->postJson("/api/v1/rosters/{$roster->id}/changes/apply", [
            'version' => $roster->version,
            'mutations' => [['type' => 'cancel', 'id' => $shift->id]],
        ])->assertOk();

        Notification::assertSentTo(
            $employeeUser,
            RosterChangeNotification::class,
            function (RosterChangeNotification $notification) {
                return $notification->title === 'Shift cancelled'
                    && str_contains($notification->body, 'cancelled')
                    && str_contains($notification->body, '09:00-17:00');
            },
        );
    }

    public function test_the_persisted_row_body_matches_the_push_body(): void
    {
        $this->actingAsSuperAdmin();
        ['roster' => $roster, 'shift' => $shift, 'employeeUser' => $employeeUser] = $this->makePublishedRoster();

        $this->postJson("/api/v1/rosters/{$roster->id}/changes/apply", [
            'version' => $roster->version,
            'mutations' => [['type' => 'cancel', 'id' => $shift->id]],
        ])->assertOk();

        $row = $employeeUser->notifications()->firstOrFail();

        $this->assertSame('Shift cancelled', $row->data['title']);
        $this->assertStringContainsString('cancelled', $row->data['body']);
    }

    public function test_deleting_a_shift_on_a_published_roster_uses_the_single_cancel_path(): void
    {
        $this->actingAsSuperAdmin();
        ['roster' => $roster, 'shift' => $shift, 'employeeUser' => $employeeUser] = $this->makePublishedRoster();

        $this->deleteJson("/api/v1/shifts/{$shift->id}")->assertOk();

        // Exactly one change row and one notification row — the delete path
        // delegates to RosterChangeService::apply() instead of duplicating it.
        $this->assertDatabaseCount('roster_changes', 1);
        $this->assertSame(1, $employeeUser->notifications()->count());
        $this->assertDatabaseHas('shifts', ['id' => $shift->id, 'status' => 'cancelled']);
    }
}
