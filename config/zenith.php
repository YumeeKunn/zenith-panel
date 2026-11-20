<?php

// Zenith Panel customizations (v2-style features backport)
return [
    'brand_logo_url' => env('ZENITH_BRAND_LOGO_URL'),
    // announcements banner text (null = hidden)
    'announcement' => env('ZENITH_ANNOUNCEMENT'),
    'announcement_type' => env('ZENITH_ANNOUNCEMENT_TYPE', 'info'), // info|warning|danger|success
    // Discord notifier
    'discord_webhook' => env('ZENITH_DISCORD_WEBHOOK'),
    'discord_bot_token' => env('ZENITH_DISCORD_BOT_TOKEN'),
    'discord_channel_id' => env('ZENITH_DISCORD_CHANNEL_ID'),
];
