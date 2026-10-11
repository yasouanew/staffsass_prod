<?php

namespace Tests\Unit\Services;

use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the platform-specific parts of the FCM payload.
 *
 * These assertions exist because the failures they prevent are silent:
 *  - Without an Android channel id, Android 8+ files the notification under
 *    the SDK's fallback channel with default importance, so it appears without
 *    sound or heads-up behaviour and looks like "push is broken".
 *  - Without an APNs alert + high priority, iOS defers or coalesces the alert
 *    when the app is backgrounded.
 */
class FcmChannelPayloadTest extends TestCase
{
    private function channel(): FcmChannel
    {
        return new FcmChannel(app());
    }

    /**
     * @return array<string, mixed>
     */
    private function invoke(string $method, mixed ...$arguments): array
    {
        $reflection = new ReflectionMethod(FcmChannel::class, $method);
        $reflection->setAccessible(true);

        /** @var \JsonSerializable $config */
        $config = $reflection->invoke($this->channel(), ...$arguments);

        return $config->jsonSerialize();
    }

    /**
     * @return array<string, mixed>
     */
    private function message(): FcmMessage
    {
        return (new FcmMessage)
            ->title('Roster published')
            ->body('Your roster for next week is ready.')
            ->data(['type' => 'roster.published', 'roster_id' => 42]);
    }

    public function test_android_config_targets_the_mobile_notification_channel(): void
    {
        $config = $this->invoke('androidConfig');

        $expected = config('firebase.fcm.android_channel_id');

        $this->assertSame(
            $expected,
            $config['notification']['channel_id'],
            'The Android channel id must match the channel the mobile app registers.'
        );
    }

    public function test_android_channel_id_defaults_to_staffsaas_default(): void
    {
        config(['firebase.fcm.android_channel_id' => null]);

        // config() returning null must not leak an empty channel id into the
        // payload; the builder falls back to the documented channel name.
        $config = $this->invoke('androidConfig');

        $this->assertSame('staffsaas_default', $config['notification']['channel_id']);
    }

    public function test_android_config_uses_high_message_priority(): void
    {
        $config = $this->invoke('androidConfig');

        $this->assertSame('high', $config['priority']);
    }

    public function test_android_config_includes_collapse_key_when_configured(): void
    {
        config(['firebase.fcm.android_collapse_key' => 'staffsaas_general']);

        $config = $this->invoke('androidConfig');

        $this->assertSame('staffsaas_general', $config['collapse_key']);
    }

    public function test_android_config_omits_optional_presentation_overrides_when_blank(): void
    {
        config([
            'firebase.fcm.android_icon' => null,
            'firebase.fcm.android_color' => null,
        ]);

        $notification = $this->invoke('androidConfig')['notification'];

        $this->assertArrayNotHasKey('icon', $notification);
        $this->assertArrayNotHasKey('color', $notification);
    }

    public function test_apns_config_carries_the_alert_and_immediate_priority(): void
    {
        $config = $this->invoke('apnsConfig', $this->message());

        $this->assertSame('10', $config['headers']['apns-priority']);
        $this->assertSame('Roster published', $config['payload']['aps']['alert']['title']);
        $this->assertSame(
            'Your roster for next week is ready.',
            $config['payload']['aps']['alert']['body']
        );
        $this->assertSame(1, $config['payload']['aps']['content-available']);
    }

    public function test_apns_config_groups_by_notification_type(): void
    {
        config(['firebase.fcm.apns_thread_id' => null]);

        $config = $this->invoke('apnsConfig', $this->message());

        // Threading keeps repeated roster updates from stacking separately.
        $this->assertSame('roster.published', $config['payload']['aps']['thread-id']);
    }

    public function test_apns_config_respects_configured_interruption_level(): void
    {
        config(['firebase.fcm.apns_interruption_level' => 'active']);

        $config = $this->invoke('apnsConfig', $this->message());

        $this->assertSame('active', $config['payload']['aps']['interruption-level']);
    }
}
