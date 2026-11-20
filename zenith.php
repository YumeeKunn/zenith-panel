<?php

// Zenith Panel customizations
return [
    'brand_logo_url' => env('ZENITH_BRAND_LOGO_URL'),
    'announcement' => env('ZENITH_ANNOUNCEMENT'),
    'announcement_type' => env('ZENITH_ANNOUNCEMENT_TYPE', 'info'),

    // Notification channels — prefer Zenith Control (DB) over env
    'discord_webhook' => env('ZENITH_DISCORD_WEBHOOK'),
    'discord_bot_token' => env('ZENITH_DISCORD_BOT_TOKEN'),
    'discord_channel_id' => env('ZENITH_DISCORD_CHANNEL_ID'),
    'line_token' => env('ZENITH_LINE_TOKEN'),
    'line_user' => env('ZENITH_LINE_USER'),
    'telegram_token' => env('ZENITH_TELEGRAM_TOKEN'),
    'telegram_chat' => env('ZENITH_TELEGRAM_CHAT'),
    'generic_webhook' => env('ZENITH_GENERIC_WEBHOOK'),

    // Self-signed cert installs (IP) — wings TLS verify toggle
    'insecure_tls' => env('ZENITH_VERIFY_TLS') === null ? true : (env('ZENITH_VERIFY_TLS') === 'false'),
];
