<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'mobilesentrix_scraper' => [
        'url' => env('MOBILESENTRIX_SCRAPER_URL', 'http://127.0.0.1:3005'),
        'urls' => (static function (): array {
            $explicitUrls = trim((string) env('MOBILESENTRIX_SCRAPER_URLS', ''));

            if ($explicitUrls !== '') {
                return array_values(array_filter(array_map('trim', explode(',', $explicitUrls))));
            }

            $ports = trim((string) env('MOBILESENTRIX_SCRAPER_PORTS', ''));

            if ($ports === '') {
                return [env('MOBILESENTRIX_SCRAPER_URL', 'http://127.0.0.1:3005')];
            }

            $baseUrl = rtrim((string) env('MOBILESENTRIX_SCRAPER_HOST', 'http://127.0.0.1'), '/');
            $urls = [];

            foreach (array_filter(array_map('trim', explode(',', $ports))) as $portPart) {
                if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $portPart, $matches) === 1) {
                    $start = min((int) $matches[1], (int) $matches[2]);
                    $end = max((int) $matches[1], (int) $matches[2]);

                    for ($port = $start; $port <= $end; $port++) {
                        $urls[] = $baseUrl.':'.$port;
                    }

                    continue;
                }

                if (preg_match('/^\d+$/', $portPart) === 1) {
                    $urls[] = $baseUrl.':'.$portPart;
                }
            }

            return $urls ?: [env('MOBILESENTRIX_SCRAPER_URL', 'http://127.0.0.1:3005')];
        })(),
        'product_sync_workers' => env('PRODUCT_SYNC_WORKERS', 25),
    ],

];
