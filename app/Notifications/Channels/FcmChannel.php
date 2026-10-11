<?php

namespace App\Notifications\Channels;

use App\Models\DeviceToken;
use App\Notifications\Messages\FcmMessage;
use Illuminate\Contracts\Container\Container;
use Illuminate\Notifications\Notification;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;
use Throwable;

/**
 * Delivers notifications to the mobile app via Firebase Cloud Messaging.
 *
 * The channel resolves all active device tokens for the notifiable and sends
 * an individual message to each. Tokens that Firebase reports as invalid or
 * unregistered are automatically deactivated so we stop targeting dead devices.
 *
 * Platform configuration matters as much as the token:
 *  - Android 8.0+ drops notifications that do not target an existing
 *    notification channel, so every message carries an explicit channel id
 *    that matches the channel the mobile app creates at startup.
 *  - iOS needs an APNs alert payload with a high priority, otherwise
 *    backgrounded notifications are coalesced or delayed by the system.
 */
class FcmChannel
{
    public function __construct(protected Container $container) {}

    /**
     * Send the given notification.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toFcm')) {
            return;
        }

        // Respect the company-level push opt-out. When a business disables
        // push notifications we must not deliver to any of its devices, even
        // though the tokens are still registered.
        if (! $this->pushEnabledFor($notifiable)) {
            return;
        }

        $tokens = $this->tokensFor($notifiable);

        if ($tokens->isEmpty()) {
            return;
        }

        // Firebase credentials are optional (they are absent in local/test
        // environments), so resolve the messaging client lazily and skip
        // delivery instead of failing the request that triggered it.
        $messaging = $this->messaging();

        if (! $messaging instanceof Messaging) {
            return;
        }

        /** @var FcmMessage $message */
        $message = $notification->toFcm($notifiable);

        // Build the platform-specific configuration once; it is identical for
        // every token of this notifiable.
        $android = $this->androidConfig();
        $apns = $this->apnsConfig($message);

        foreach ($tokens as $deviceToken) {
            $this->sendToToken($messaging, $deviceToken, $message, $android, $apns);
        }
    }

    /**
     * Build the Android notification configuration.
     *
     * The channel id must match the channel the mobile app registers at
     * startup (FCM_ANDROID_CHANNEL_ID) and the manifest default channel id
     * declared in the app's strings.xml.
     */
    protected function androidConfig(): AndroidConfig
    {
        // Fall back explicitly when the key is present but empty/blank. An
        // empty channel id makes Android 8+ route the notification to the
        // SDK fallback channel, which is the exact silent failure this
        // configuration exists to prevent.
        $channelId = config('firebase.fcm.android_channel_id') ?: 'staffsaas_default';

        $notification = [
            'channel_id' => (string) $channelId,
            'sound' => (string) (config('firebase.fcm.android_sound') ?: 'default'),
            // Valid FCM v1 field. NOTE: "importance" is NOT part of the FCM v1
            // android.notification schema (it is a Notifee/Android-channel
            // concept); sending it makes FCM reject the whole message with
            // "Unknown name \"importance\"". Heads-up behaviour comes from the
            // channel's importance, which the mobile app sets to HIGH.
            'notification_priority' => 'PRIORITY_HIGH',
        ];

        // Optional presentation overrides - only sent when configured so we
        // never override the app icon with an empty value.
        if ($icon = config('firebase.fcm.android_icon')) {
            $notification['icon'] = (string) $icon;
        }

        if ($color = config('firebase.fcm.android_color')) {
            $notification['color'] = (string) $color;
        }

        $config = AndroidConfig::new()
            ->withHighMessagePriority()
            ->withHighNotificationPriority();

        $display = $config->jsonSerialize();
        $display['notification'] = array_merge(
            ($display['notification'] ?? []),
            $notification
        );

        // kreait/firebase-php v8 removed AndroidConfig::withTtl(); the TTL is
        // now supplied through the config array (an int of seconds, or a
        // "<n>s" string). 4 weeks is the maximum FCM queue lifetime.
        $display['ttl'] = 2419200;

        $config = AndroidConfig::fromArray($display);

        if ($collapseKey = config('firebase.fcm.android_collapse_key')) {
            // Collapse key lives at the top level of the Android config, so it
            // is merged into the serialised array rather than set via a setter.
            $withCollapseKey = $config->jsonSerialize();
            $withCollapseKey['collapse_key'] = (string) $collapseKey;

            $config = AndroidConfig::fromArray($withCollapseKey);
        }

        return $config;
    }

    /**
     * Build the APNs configuration for iOS.
     *
     * A "time-sensitive" interruption level lets roster/leave alerts break
     * through Focus modes; the payload still degrades gracefully when the
     * capability is not enabled on the iOS target.
     */
    protected function apnsConfig(FcmMessage $message): ApnsConfig
    {
        $headers = [
            'apns-priority' => (string) config('firebase.fcm.apns_priority', 10),
        ];

        if ($topic = config('firebase.fcm.apns_topic')) {
            $headers['apns-topic'] = (string) $topic;
        }

        $config = ApnsConfig::new()
            ->withPriority($headers['apns-priority'])
            ->withApsField('alert', [
                'title' => $message->title,
                'body' => $message->body,
            ])
            ->withSound((string) config('firebase.fcm.apns_sound', 'default'))
            ->withApsField('content-available', 1);

        if (isset($headers['apns-topic'])) {
            $config = $config->withHeader('apns-topic', $headers['apns-topic']);
        }

        $threadId = config('firebase.fcm.apns_thread_id')
            ?? ($message->data['type'] ?? null);

        if ($threadId) {
            // Group related notifications (e.g. every roster update) together.
            $config = $config->withApsField('thread-id', (string) $threadId);
        }

        if ($level = config('firebase.fcm.apns_interruption_level')) {
            $config = $config->withApsField('interruption-level', (string) $level);
        }

        return $config;
    }

    /**
     * Resolve the Firebase messaging client, or null when it is unavailable.
     */
    protected function messaging(): ?Messaging
    {
        try {
            return $this->container->make(Messaging::class);
        } catch (Throwable $e) {
            Log::warning('FCM push notifications are disabled: Firebase is not configured.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }


    /**
     * Determine whether push delivery is enabled for the notifiable.
     *
     * The company-level `allow_push_notifications` setting is authoritative:
     * when a business turns push off, no device of any of its users should
     * receive a push, regardless of how many tokens remain registered.
     *
     * Notifiables without a company (or without settings) default to enabled
     * so the channel keeps working for any future non-company notifiable.
     */
    protected function pushEnabledFor(object $notifiable): bool
    {
        if (! method_exists($notifiable, 'company')) {
            return true;
        }

        $company = $notifiable->company;

        if ($company === null) {
            return true;
        }

        $settings = $company->settings;

        if ($settings === null) {
            return true;
        }

        return (bool) $settings->allow_push_notifications;
    }

    /**
     * Resolve the active device tokens for the notifiable.
     *
     * @return \Illuminate\Support\Collection<int, DeviceToken>
     */
    protected function tokensFor(object $notifiable)
    {
        // Allow a notifiable to customise how its device tokens are resolved.
        if (method_exists($notifiable, 'routeNotificationForFcm')) {
            return collect($notifiable->routeNotificationForFcm());
        }

        if (method_exists($notifiable, 'deviceTokens')) {
            return $notifiable->deviceTokens()->active()->get();
        }

        return collect();
    }

    /**
     * Send a message to a single device token using the exact same payload
     * construction as real notifications.
     *
     * Used by the `fcm:test` artisan command so physical devices can be
     * verified against production behaviour rather than a hand-rolled payload.
     *
     * @throws Throwable when Firebase is not configured or delivery fails
     */
    public function sendToTokenForTesting(DeviceToken $deviceToken, FcmMessage $message): void
    {
        $messaging = $this->container->make(Messaging::class);

        // Deliberately does NOT swallow errors: the `fcm:test` command must
        // report a real failure (e.g. invalid credentials) instead of a false
        // success. Production delivery goes through sendToToken(), which does
        // swallow so a push failure can never break the triggering request.
        $this->deliver(
            $messaging,
            $deviceToken,
            $message,
            $this->androidConfig(),
            $this->apnsConfig($message)
        );
    }

    /**
     * Send a single message to one device token, swallowing failures.
     *
     * When the message carries a stable business key, a short-lived marker is
     * written per device token before delivery. A retried or replayed job then
     * finds the marker and skips the send, so one business event can never
     * produce two pushes to the same device.
     */
    protected function sendToToken(
        Messaging $messaging,
        DeviceToken $deviceToken,
        FcmMessage $message,
        AndroidConfig $android,
        ApnsConfig $apns
    ): void {
        if (! $this->claimDelivery($message, $deviceToken)) {
            return;
        }

        try {
            $this->deliver($messaging, $deviceToken, $message, $android, $apns);
        } catch (NotFound) {

            // The token is no longer registered on this device; stop using it.
            $deviceToken->update(['is_active' => false]);
        } catch (Throwable $e) {
            // Message construction is inside the try so a single malformed
            // message can never fail the whole notification fan-out.
            Log::warning('FCM push notification failed.', [
                'device_token_id' => $deviceToken->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Atomically claim delivery of a message to a device token.
     *
     * Returns true when this call owns the delivery (and should send), false
     * when the same business key was already delivered to this token. Messages
     * without a dedupe key are always sent, preserving the previous behaviour
     * for every non-roster notification.
     */
    protected function claimDelivery(FcmMessage $message, DeviceToken $deviceToken): bool
    {
        if ($message->dedupeKey === null || $message->dedupeKey === '') {
            return true;
        }

        $marker = 'fcm-sent:'.$message->dedupeKey.':'.$deviceToken->id;

        // Cache::add() is atomic: only the first caller gets true. The TTL is
        // long enough to cover queue retries but short enough that a genuinely
        // new event (which carries a new key) is never blocked.
        return Cache::add($marker, true, now()->addHours(6));
    }

    /**
     * Build and send a single message, letting any failure propagate.
     *
     * @throws Throwable
     */
    protected function deliver(
        Messaging $messaging,
        DeviceToken $deviceToken,
        FcmMessage $message,
        AndroidConfig $android,
        ApnsConfig $apns
    ): void {
        $data = $message->data;

        // Forward the business key so the mobile client can also collapse a
        // duplicate that reached the device before the marker was written.
        if ($message->dedupeKey !== null && $message->dedupeKey !== '') {
            $data['dedupe_key'] = $message->dedupeKey;
        }

        // kreait/firebase-php v8 removed the CloudMessage::withTarget() factory;
        // a message is now built from CloudMessage::new() and targeted with
        // withToken()/withTopic()/withCondition().
        $cloudMessage = CloudMessage::new()
            ->withToken($this->normalizeToken($deviceToken->token))
            ->withNotification(FirebaseNotification::create($message->title, $message->body))
            ->withData($data)
            ->withAndroidConfig($android)
            ->withApnsConfig($apns);

        $messaging->send($cloudMessage);
    }

    /**
     * Strip the mobile app's local `fcm:` storage prefix from a token.
     *
     * The React Native client stores and transmits tokens prefixed with `fcm:`
     * (see `toStoredTokenFormat` in the mobile push service) so it can tell an
     * FCM token apart from other stored credentials. That prefix is a *client*
     * convention: the raw FCM registration token never contains it, and sending
     * `fcm:<token>` to the FCM API is rejected with `NotRegistered` (404).
     *
     * Normalising here — rather than at registration — keeps existing stored
     * tokens working and does not break the client's unregister call, which
     * still matches on the prefixed value.
     */
    protected function normalizeToken(string $token): string
    {
        return str_starts_with($token, 'fcm:') ? substr($token, 4) : $token;
    }
}
