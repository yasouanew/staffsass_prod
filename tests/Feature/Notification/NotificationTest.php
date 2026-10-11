<?php

namespace Tests\Feature\Notification;

use App\Models\Company;
use App\Models\DeviceToken;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveRequestStatusNotification;
use App\Services\LeaveRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_register_a_device_token(): void
    {
        $this->postJson('/api/v1/device-tokens', [
            'token' => 'abc',
            'platform' => 'ios',
        ])->assertUnauthorized();
    }

    public function test_user_can_register_a_device_token(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user);


        $response = $this->postJson('/api/v1/device-tokens', [
            'token' => 'fcm-token-123',
            'platform' => 'android',
            'device_name' => 'Pixel 8',
            'app_version' => '1.2.3',
            'os_version' => '14.0.0',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.platform', 'android');

        $this->assertDatabaseHas('device_tokens', [
            'token' => 'fcm-token-123',
            'user_id' => $user->id,
            'is_active' => true,
        ]);
    }

    public function test_registering_same_token_reassigns_it_to_current_user(): void
    {
        $company = Company::factory()->create();
        $original = User::factory()->create(['company_id' => $company->id]);
        DeviceToken::factory()->create([
            'user_id' => $original->id,
            'company_id' => $company->id,
            'token' => 'shared-token',
        ]);

        $newUser = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($newUser);


        $this->postJson('/api/v1/device-tokens', [
            'token' => 'shared-token',
            'platform' => 'ios',
        ])->assertCreated();

        $this->assertDatabaseCount('device_tokens', 1);
        $this->assertDatabaseHas('device_tokens', [
            'token' => 'shared-token',
            'user_id' => $newUser->id,
        ]);
    }

    public function test_user_can_unregister_a_device_token(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        DeviceToken::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'token' => 'to-remove',
        ]);
        Sanctum::actingAs($user);


        $this->deleteJson('/api/v1/device-tokens', ['token' => 'to-remove'])
            ->assertOk();

        $this->assertDatabaseMissing('device_tokens', ['token' => 'to-remove']);
    }

    public function test_approving_leave_notifies_the_employee_user(): void
    {
        Notification::fake();

        $company = Company::factory()->create();
        $employeeUser = User::factory()->create(['company_id' => $company->id]);
        $employee = Employee::factory()->create([
            'company_id' => $company->id,
            'user_id' => $employeeUser->id,
        ]);
        $leaveRequest = LeaveRequest::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'status' => 'pending',
        ]);

        $approver = User::factory()->create(['company_id' => $company->id]);

        app(LeaveRequestService::class)->approve($leaveRequest, $approver);

        Notification::assertSentTo(
            $employeeUser,
            LeaveRequestStatusNotification::class
        );
    }

    public function test_user_can_list_and_read_notifications(): void
    {
        // Every non super-admin user belongs to a company; the notification
        // routes sit behind the company.access middleware.
        $user = User::factory()->create([
            'company_id' => Company::factory()->create()->id,
        ]);


        $user->notify(new class extends \Illuminate\Notifications\Notification
        {
            public function via($notifiable): array
            {
                return ['database'];
            }

            public function toArray($notifiable): array
            {
                return ['type' => 'test', 'title' => 'Hi', 'body' => 'There'];
            }
        });

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);

        $notification = $user->notifications()->first();

        $this->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk();

        $this->assertNotNull($user->notifications()->first()->read_at);
    }

    public function test_guest_cannot_read_unread_count(): void
    {
        $this->getJson('/api/v1/notifications/unread-count')->assertUnauthorized();
    }

    public function test_user_can_read_unread_notification_count(): void
    {
        $user = User::factory()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        foreach (range(1, 3) as $index) {
            $user->notify(new class($index) extends \Illuminate\Notifications\Notification
            {
                public function __construct(private int $index) {}

                public function via($notifiable): array
                {
                    return ['database'];
                }

                public function toArray($notifiable): array
                {
                    return ['type' => 'test', 'title' => "Hi {$this->index}", 'body' => 'There'];
                }
            });
        }

        Sanctum::actingAs($user);

        // GET is supported on this path (regression guard against the route being
        // shadowed by the DELETE-only `notifications/{notification}` route).
        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.unread_count', 3);

        $user->notifications()->first()->markAsRead();

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);
    }

    public function test_broadcast_channel_targets_the_private_user_channel(): void
    {
        Event::fake([BroadcastNotificationCreated::class]);

        $user = User::factory()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        $user->notify(new class extends \Illuminate\Notifications\Notification
        {
            public function via($notifiable): array
            {
                return ['broadcast'];
            }

            public function toArray($notifiable): array
            {
                return ['type' => 'test', 'title' => 'Hi', 'body' => 'There'];
            }
        });

        // The live in-app path depends on the event landing on the user's
        // private channel; if this regresses, realtime updates silently stop.
        Event::assertDispatched(
            BroadcastNotificationCreated::class,
            function (BroadcastNotificationCreated $event) use ($user): bool {
                $channels = collect($event->broadcastOn())
                    ->map(fn ($channel) => (string) $channel)
                    ->all();

                return in_array("private-App.Models.User.{$user->id}", $channels, true);
            }
        );
    }

    public function test_unread_count_is_scoped_to_the_authenticated_user(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $otherUser = User::factory()->create(['company_id' => $company->id]);

        $otherUser->notify(new class extends \Illuminate\Notifications\Notification
        {
            public function via($notifiable): array
            {
                return ['database'];
            }

            public function toArray($notifiable): array
            {
                return ['type' => 'test', 'title' => 'Hi', 'body' => 'There'];
            }
        });

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }
}
