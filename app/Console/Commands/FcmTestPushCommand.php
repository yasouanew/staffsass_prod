<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use Illuminate\Console\Command;

/**
 * Push a test notification to a real device so the mobile setup can be
 * verified end to end (token registration, channel id, APNs delivery).
 *
 * Usage examples:
 *   php artisan fcm:test 12
 *   php artisan fcm:test user@example.com --platform=android
 *   php artisan fcm:test --token="<fcm-registration-token>"
 *
 * The command deliberately reuses the production FcmChannel so the payload it
 * sends is byte-for-byte what real notifications use - including the Android
 * channel id and the APNs alert configuration.
 */
class FcmTestPushCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'fcm:test
        {user? : User id or email address whose active device tokens should be targeted}
        {--token=* : One or more raw FCM registration tokens to target directly}
        {--platform= : Restrict delivery to ios or android device tokens}
        {--title=FCM test notification : Notification title}
        {--body=If you can see this, push notifications are working. : Notification body}
        {--type=fcm.test : Value sent in the FCM data payload "type" key}
        {--dry-run : Print the platform config instead of sending}';

    /**
     * @var string
     */
    protected $description = 'Send a test FCM push notification to a device';

    public function handle(FcmChannel $channel): int
    {
        $message = (new FcmMessage)
            ->title((string) $this->option('title'))
            ->body((string) $this->option('body'))
            ->data([
                'type' => (string) $this->option('type'),
                'sent_at' => now()->toIso8601String(),
            ]);

        $tokens = $this->resolveTokens();

        if ($tokens->isEmpty()) {
            $this->error('No device tokens matched. Pass a user id/email, or --token=<fcm-token>.');
            $this->listRegisteredTokens();

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Targeting %d device token(s). Channel id: %s',
            $tokens->count(),
            (string) config('firebase.fcm.android_channel_id'),
        ));

        if ($this->option('dry-run')) {
            $this->warn('Dry run - nothing was sent.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($tokens as $deviceToken) {
            try {
                $channel->sendToTokenForTesting($deviceToken, $message);
                $sent++;
                $this->line(sprintf('  sent  -> #%d %s', $deviceToken->id, $deviceToken->platform));
            } catch (\Throwable $e) {
                $failed++;
                $this->error(sprintf('  failed-> #%d %s : %s', $deviceToken->id, $deviceToken->platform, $e->getMessage()));
            }
        }

        $this->newLine();
        $this->info(sprintf('Sent: %d, failed: %d', $sent, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Print the most recently registered tokens to help diagnose targeting.
     *
     * A database outage must not hide the original "no tokens matched"
     * message, so failures here are reported without aborting the command.
     */
    protected function listRegisteredTokens(): void
    {
        $this->line('Registered tokens in this database:');

        try {
            $tokens = DeviceToken::query()
                ->select(['id', 'user_id', 'platform', 'device_name', 'is_active'])
                ->latest('id')
                ->limit(20)
                ->get();
        } catch (\Throwable $e) {
            $this->warn('  could not read device tokens: '.$e->getMessage());

            return;
        }

        if ($tokens->isEmpty()) {
            $this->line('  (none - no device has registered a token yet)');

            return;
        }

        $tokens->each(fn (DeviceToken $t) => $this->line(sprintf(
            '  #%d user:%s platform:%s device:%s active:%s',
            $t->id,
            $t->user_id,
            $t->platform,
            $t->device_name ?? '-',
            $t->is_active ? 'yes' : 'no',
        )));
    }

    /**
     * Resolve the device tokens to target from the command options.
     *
     * @return \Illuminate\Support\Collection<int, DeviceToken>
     */
    protected function resolveTokens()
    {
        $rawTokens = array_filter((array) $this->option('token'));
        $platform = $this->option('platform');

        if ($platform && ! in_array($platform, ['ios', 'android'], true)) {
            $this->error('The --platform option must be either "ios" or "android".');

            return collect();
        }

        if ($rawTokens !== []) {
            try {
                return DeviceToken::query()->whereIn('token', $rawTokens)->get();
            } catch (\Throwable $e) {
                $this->error('Could not read device tokens: '.$e->getMessage());

                return collect();
            }
        }

        $identifier = $this->argument('user');

        if (! $identifier) {
            return collect();
        }

        try {
            $user = is_numeric($identifier)
                ? User::find((int) $identifier)
                : User::where('email', $identifier)->first();
        } catch (\Throwable $e) {
            $this->error('Could not read users: '.$e->getMessage());

            return collect();
        }

        if (! $user) {
            $this->error(sprintf('No user found for "%s".', $identifier));

            return collect();
        }

        $query = $user->deviceTokens()->active();

        if ($platform) {
            $query->where('platform', $platform);
        }

        return $query->get();
    }
}
