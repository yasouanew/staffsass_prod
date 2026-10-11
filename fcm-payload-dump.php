<?php

/**
 * Temporary: dump the exact FCM v1 payload the backend sends.
 * Run: php fcm-payload-dump.php
 * Delete after use.
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\DeviceToken;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;

$channel = app(FcmChannel::class);

$ref = new ReflectionClass($channel);
$androidMethod = $ref->getMethod('androidConfig');
$androidMethod->setAccessible(true);
$apnsMethod = $ref->getMethod('apnsConfig');
$apnsMethod->setAccessible(true);
$normalizeMethod = $ref->getMethod('normalizeToken');
$normalizeMethod->setAccessible(true);

$message = (new FcmMessage)
    ->title('Roster published')
    ->body('Your roster for next week is ready.')
    ->data(['type' => 'roster.published', 'roster_id' => 42]);

$android = $androidMethod->invoke($channel);
$apns = $apnsMethod->invoke($channel, $message);

$token = DeviceToken::query()->where('is_active', true)->latest('id')->first();

$cloudMessage = CloudMessage::new()
    ->withToken($normalizeMethod->invoke($channel, $token->token))
    ->withNotification(FirebaseNotification::create($message->title, $message->body))
    ->withData($message->data)
    ->withAndroidConfig($android)
    ->withApnsConfig($apns);

echo "=== FULL FCM v1 PAYLOAD ===\n";
echo json_encode($cloudMessage->jsonSerialize(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n";

echo "=== KEY CHECKS ===\n";
$payload = $cloudMessage->jsonSerialize();
echo 'Has top-level "notification" block: '.(isset($payload['notification']) ? 'YES' : 'NO')."\n";
echo '  title: '.($payload['notification']['title'] ?? '(none)')."\n";
echo '  body : '.($payload['notification']['body'] ?? '(none)')."\n";
echo 'android.notification.channel_id: '.($payload['android']['notification']['channel_id'] ?? '(none)')."\n";
echo 'android.priority: '.($payload['android']['priority'] ?? '(none)')."\n";
echo 'android.notification.notification_priority: '.($payload['android']['notification']['notification_priority'] ?? '(none)')."\n";
echo 'data keys: '.implode(', ', array_keys($payload['data'] ?? []))."\n";
