<?php

namespace Tests\Unit\Services;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\DeviceToken;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Message;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Guards the actual message construction + delivery path of the FCM channel.
 *
 * The payload tests only cover the platform config builders; they never build
 * a CloudMessage, so they could not catch the v7 -> v8 API break where
 * CloudMessage::withTarget() was removed. These tests exercise sendToToken()
 * end to end against a fake Messaging client so that regression can never
 * silently return.
 */
class FcmChannelDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function channel(): FcmChannel
    {
        return new FcmChannel(app());
    }

    private function message(): FcmMessage
    {
        return (new FcmMessage)
            ->title('Roster published')
            ->body('Your roster for next week is ready.')
            ->data(['type' => 'roster.published', 'roster_id' => 42]);
    }

    /**
     * Invoke the protected sendToToken() with the same config the real
     * send() path would build.
     */
    private function sendToToken(Messaging $messaging, DeviceToken $deviceToken): void
    {
        $channel = $this->channel();
        $message = $this->message();

        $android = $this->invokeProtected($channel, 'androidConfig');
        $apns = $this->invokeProtected($channel, 'apnsConfig', $message);

        $method = new ReflectionMethod(FcmChannel::class, 'sendToToken');
        $method->setAccessible(true);
        $method->invoke($channel, $messaging, $deviceToken, $message, $android, $apns);
    }

    private function invokeProtected(FcmChannel $channel, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(FcmChannel::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($channel, ...$arguments);
    }

    public function test_it_builds_a_v8_cloud_message_targeted_at_the_device_token(): void
    {
        $deviceToken = DeviceToken::factory()->create(['token' => 'device-token-abc']);

        $captured = null;
        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (Message $message) use (&$captured): array {
                $captured = $message;

                return [];
            });

        $this->sendToToken($messaging, $deviceToken);

        $this->assertInstanceOf(CloudMessage::class, $captured);

        $payload = $captured->jsonSerialize();

        // The token target is the whole point of the v8 migration; before the
        // fix this call threw "Call to undefined method ...::withTarget()".
        $this->assertSame('device-token-abc', $payload['token']);
        $this->assertSame('Roster published', $payload['notification']['title']);
        $this->assertSame('roster.published', $payload['data']['type']);
    }

    public function test_it_strips_the_mobile_fcm_prefix_before_sending(): void
    {
        // The mobile app stores/transmits tokens prefixed with `fcm:`; the raw
        // FCM token never contains it, and sending the prefix yields NotRegistered.
        $deviceToken = DeviceToken::factory()->create(['token' => 'fcm:raw-registration-token']);

        $captured = null;
        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (Message $message) use (&$captured): array {
                $captured = $message;

                return [];
            });

        $this->sendToToken($messaging, $deviceToken);

        $this->assertSame('raw-registration-token', $captured->jsonSerialize()['token']);
    }

    public function test_a_not_found_response_deactivates_the_device_token(): void
    {
        $deviceToken = DeviceToken::factory()->create([
            'token' => 'stale-token',
            'is_active' => true,
        ]);

        $messaging = $this->createMock(Messaging::class);
        $messaging->method('send')
            ->willThrowException(NotFound::becauseTokenNotFound('stale-token'));

        $this->sendToToken($messaging, $deviceToken);

        $this->assertFalse($deviceToken->fresh()->is_active);
    }

    public function test_a_delivery_failure_is_swallowed_and_does_not_propagate(): void
    {
        $deviceToken = DeviceToken::factory()->create([
            'token' => 'flaky-token',
            'is_active' => true,
        ]);

        $messaging = $this->createMock(Messaging::class);
        $messaging->method('send')
            ->willThrowException(new RuntimeException('Firebase is down'));

        // A single failing token must never fail the whole notification
        // fan-out (which is what previously failed the queued job).
        $this->sendToToken($messaging, $deviceToken);

        $this->assertTrue($deviceToken->fresh()->is_active);
    }

    public function test_push_is_skipped_when_the_company_disables_it(): void
    {
        $company = Company::factory()->create();
        CompanySetting::factory()->create([
            'company_id' => $company->id,
            'allow_push_notifications' => false,
        ]);

        $user = User::factory()->create(['company_id' => $company->id]);
        DeviceToken::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'token' => 'opted-out-token',
            'is_active' => true,
        ]);

        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->never())->method('send');

        // Bind the mock so the assertion is meaningful: without it the channel
        // would bail out early because Firebase is unconfigured in tests.
        $this->app->instance(Messaging::class, $messaging);

        $this->channel()->send($user, new FakeFcmNotification);
    }

    public function test_push_is_delivered_when_the_company_allows_it(): void
    {
        $company = Company::factory()->create();
        CompanySetting::factory()->create([
            'company_id' => $company->id,
            'allow_push_notifications' => true,
        ]);

        $user = User::factory()->create(['company_id' => $company->id]);
        DeviceToken::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'token' => 'allowed-token',
            'is_active' => true,
        ]);

        $messaging = $this->createMock(Messaging::class);
        $messaging->expects($this->once())->method('send')->willReturn([]);

        $this->app->instance(Messaging::class, $messaging);

        $this->channel()->send($user, new FakeFcmNotification);
    }
}

/**
 * Minimal notification exposing toFcm() so the channel's send() path runs.
 */
class FakeFcmNotification extends Notification
{
    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage)
            ->title('Test')
            ->body('Test body')
            ->data(['type' => 'test']);
    }
}
