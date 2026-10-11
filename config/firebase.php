<?php

/*
|--------------------------------------------------------------------------
| Firebase configuration (kreait/laravel-firebase)
|--------------------------------------------------------------------------
|
| Used for sending FCM push notifications to the mobile app. The service
| account credentials JSON is provided via FIREBASE_CREDENTIALS. When no
| credentials are configured (e.g. in the test environment) push sending
| is skipped gracefully by the FcmChannel.
|
*/

return [
    'default' => env('FIREBASE_PROJECT', 'app'),

    /*
    |--------------------------------------------------------------------------
    | FCM delivery defaults (mobile push)
    |--------------------------------------------------------------------------
    |
    | Android requires every notification posted to Android 8.0+ to target a
    | notification channel. The mobile app is compiled with the channel id in
    | FIREBASE_ANDROID_CHANNEL_ID injected through react-native-config, so the
    | backend MUST send the same value or Android silently substitutes its own
    | "fcm_fallback_notification_channel".
    |
    | The three places that must agree are:
    |   1. Mobile env  FCM_ANDROID_CHANNEL_ID   (.env)
    |   2. Mobile res  default_notification_channel_id (strings.xml)
    |   3. Backend     FIREBASE_ANDROID_CHANNEL_ID (this file)
    |
    */
    'fcm' => [
        // Android notification channel id. Must match the mobile app exactly.
        'android_channel_id' => env('FIREBASE_ANDROID_CHANNEL_ID', 'staffsaas_default'),

        // Human readable channel name shown in Android settings.
        'android_channel_name' => env('FIREBASE_ANDROID_CHANNEL_NAME', 'StaffSaaS'),

        // Android notification channel description.
        'android_channel_description' => env('FIREBASE_ANDROID_CHANNEL_DESCRIPTION', 'Shift, roster and leave alerts'),

        // Default notification icon (mipmap/drawable name without extension).
        // Leave null to let Firebase use the application icon.
        'android_icon' => env('FIREBASE_ANDROID_ICON'),

        // Android notification accent colour in #rrggbb format.
        'android_color' => env('FIREBASE_ANDROID_COLOR'),

        // Android notification sound. "default" uses the system sound.
        'android_sound' => env('FIREBASE_ANDROID_SOUND', 'default'),

        // iOS APNs topic. This is normally the bundle id, and is only required
        // when the app is built with a custom APNs topic.
        'apns_topic' => env('FIREBASE_APNS_TOPIC'),

        // iOS thread identifier - groups notifications of the same kind in the
        // Notification Centre. Mirrors the notification "type" data key.
        'apns_thread_id' => env('FIREBASE_APNS_THREAD_ID'),

        // iOS notification sound. "default" uses the system sound.
        'apns_sound' => env('FIREBASE_APNS_SOUND', 'default'),

        // iOS interruption level (passive|active|time-sensitive|critical).
        // Time-sensitive bypasses Focus modes and needs the Time Sensitive
        // Notifications capability enabled in the iOS target.
        'apns_interruption_level' => env('FIREBASE_APNS_INTERRUPTION_LEVEL', 'time-sensitive'),

        // APNs priority. 10 = deliver immediately, 5 = defer to save power.
        'apns_priority' => (int) env('FIREBASE_APNS_PRIORITY', 10),

        // Collapse key so a newer notification replaces an older one of the
        // same kind instead of stacking (e.g. several roster updates).
        'android_collapse_key' => env('FIREBASE_ANDROID_COLLAPSE_KEY', 'staffsaas_general'),
    ],

    'projects' => [
        // The project key MUST match `firebase.default` above, otherwise the
        // package throws "Firebase project [x] not configured". Keying it by the
        // same env value keeps the two in sync no matter what FIREBASE_PROJECT is
        // set to (the real Firebase project id still comes from the credentials
        // JSON, not from this key).
        env('FIREBASE_PROJECT', 'app') => [

            /*
             * Path to the Google service account JSON file, relative to the
             * project base path, or an absolute path.
             */
            'credentials' => env('FIREBASE_CREDENTIALS')
                ? base_path(env('FIREBASE_CREDENTIALS'))
                : null,

            'auth' => [
                'tenant_id' => env('FIREBASE_AUTH_TENANT_ID'),
            ],

            'firestore' => [
                'database' => env('FIREBASE_FIRESTORE_DATABASE'),
            ],

            'database' => [
                'url' => env('FIREBASE_DATABASE_URL'),
            ],

            'dynamic_links' => [
                'default_domain' => env('FIREBASE_DYNAMIC_LINKS_DEFAULT_DOMAIN'),
            ],

            'storage' => [
                'default_bucket' => env('FIREBASE_STORAGE_DEFAULT_BUCKET'),
            ],

            'cache_store' => env('FIREBASE_CACHE_STORE', 'file'),

            'logging' => [
                'http_log_channel' => env('FIREBASE_HTTP_LOG_CHANNEL'),
                'http_debug_log_channel' => env('FIREBASE_HTTP_DEBUG_LOG_CHANNEL'),
            ],

            'http_client_options' => [
                'proxy' => env('FIREBASE_HTTP_CLIENT_PROXY'),
                'timeout' => env('FIREBASE_HTTP_CLIENT_TIMEOUT'),
            ],
        ],
    ],
];
