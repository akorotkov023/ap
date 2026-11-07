#!/usr/bin/env php
<?php
/**
 * Telegram bot — long-polling entrypoint.
 *
 * Requires .env:
 *   TELEGRAM_BOT_TOKEN=<token from @BotFather>
 *   TELEGRAM_ALLOWED_IDS=<telegram_id|@username>[, ...]
 *
 * Run (inside the `web` container):
 *   docker compose exec web php bin/telegram_bot.php
 *
 * Debug a single poll batch:
 *   docker compose exec web php bin/telegram_bot.php --once
 *
 * Kept alive by bin/telegram_bot_monitor.sh (cron + /start.sh).
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../inc/Config.php';
require __DIR__ . '/../inc/DB.php';
require __DIR__ . '/../inc/Auth.php';
require __DIR__ . '/../inc/VpnServer.php';
require __DIR__ . '/../inc/VpnClient.php';
require __DIR__ . '/../inc/TelegramBot.php';

Config::load(__DIR__ . '/../.env');

$once = in_array('--once', $argv, true);

$bot = new TelegramBot();
$bot->run($once);
