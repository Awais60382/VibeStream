<?php
/*
 * dashboard-alerts-config.php  (same folder as index.php)
 *
 * Where alerts get sent. Fill in Telegram, email, or both. Leave a part empty to switch it off.
 * This file holds a secret (the bot token), so do not share it or put it in a public repo.
 * Opening it in a browser shows nothing.
 *
 * TELEGRAM SETUP (2 minutes)
 *   1. In Telegram, open @BotFather, send /newbot, follow the steps. It gives you a token.
 *   2. Open your new bot and send it any message, for example "hi".
 *   3. Visit  https://api.telegram.org/bot<YOUR_TOKEN>/getUpdates  in a browser.
 *      Find  "chat":{"id":123456789  and copy that number: it is your chat id.
 *   4. Paste both below, then run:  php dashboard-alerts-cron.php --test
 *
 * EMAIL needs your server to be able to send mail (PHP mail()). Many hosts block it or send it to
 * spam, so Telegram is the more reliable choice.
 */
return [
    'telegram_token'   => '',          // e.g. '123456:ABC-DEF...'
    'telegram_chat_id' => '',          // e.g. '123456789'

    'email_to'         => '',          // e.g. 'you@example.com'
    'email_from'       => '',          // e.g. 'alerts@yourdomain.com' (a real address on your domain works best)

    'min_severity'     => 'warning',   // 'critical' = only the serious ones, 'warning' = serious and warnings
    'site_name'        => 'Admin dashboard',
    'dashboard_url'    => '',          // optional link added to each message, e.g. 'https://yoursite.com/admin/'
];
