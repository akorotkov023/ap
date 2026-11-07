<?php

/**
 * Telegram bot integration (long-polling).
 *
 * Allows an authorized Telegram user to pick one of their servers, create a
 * VPN client, and receive the generated .conf file. It reuses the exact same
 * code path as the web UI:
 *
 *   VpnClient::create(...)  -> creates the client + initial config
 *   VpnClient::regenerateConfigFromServer(true) -> refresh AWG/WG config
 *   VpnClient::getConfig()  -> the .conf file contents (same as /clients/{id}/download)
 *
 * Authorization is a comma-separated allow-list of Telegram ids in .env:
 *
 *   TELEGRAM_BOT_TOKEN=123456:ABC...
 *   TELEGRAM_ALLOWED_IDS=111111111,222222222,@alice
 *
 * Each entry is a numeric Telegram user id or a @username. Anyone on the
 * list acts as the panel admin (resolved via ADMIN_EMAIL) and can create
 * configs on any server.
 */
class TelegramBot
{
    /** Reply-keyboard menu button labels (matched against incoming text). */
    private const BTN_START = '🚀 Старт';
    private const BTN_PING = '📡 Пинг';

    private string $token;
    private string $apiBase;

    /** @var array<int, true> allowed numeric Telegram ids (lazy-loaded) */
    private ?array $allowedIds = null;

    /** @var array<string, true> allowed lowercase usernames without @ (lazy-loaded) */
    private ?array $allowedUsernames = null;

    /** @var array|null cached admin panel user */
    private ?array $adminUser = null;

    /** @var array<int, array<string, mixed>> in-memory per-chat state machine */
    private array $state = [];

    public function __construct(?string $token = null)
    {
        $this->token = $token ?? (string) Config::get('TELEGRAM_BOT_TOKEN', '');
        $this->apiBase = 'https://api.telegram.org/bot' . $this->token;
    }

    /**
     * Whether the bot is configured (token + at least one allowed id set).
     */
    public static function isConfigured(): bool
    {
        return trim((string) Config::get('TELEGRAM_BOT_TOKEN', '')) !== ''
            && trim((string) Config::get('TELEGRAM_ALLOWED_IDS', '')) !== '';
    }

    /**
     * Whether the bot process is currently running (checks its PID file).
     * The PID file is written by bin/telegram_bot_monitor.sh.
     */
    public static function isRunning(): bool
    {
        $pidFile = '/var/www/html/logs/telegram_bot.pid';
        if (!is_file($pidFile)) {
            return false;
        }
        $pid = (int) trim((string) @file_get_contents($pidFile));
        if ($pid <= 0) {
            return false;
        }
        $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
        if ($cmdline === false) {
            return false;
        }
        return str_contains($cmdline, 'telegram_bot.php');
    }

    /**
     * Re-read .env so changes to TELEGRAM_ALLOWED_IDS / TELEGRAM_BOT_TOKEN /
     * ADMIN_EMAIL take effect without restarting the long-running process.
     */
    private function reloadEnv(): void
    {
        Config::load(__DIR__ . '/../.env');
        $this->token = (string) Config::get('TELEGRAM_BOT_TOKEN', '');
        $this->apiBase = 'https://api.telegram.org/bot' . $this->token;
        $this->allowedIds = null;
        $this->allowedUsernames = null;
        $this->adminUser = null;
    }

    /**
     * Long-polling main loop. Pass $once = true to process a single batch and
     * exit (useful for debugging: `php bin/telegram_bot.php --once`).
     */
    public function run(bool $once = false): void
    {
        if ($this->token === '') {
            fwrite(STDERR, "[telegram-bot] TELEGRAM_BOT_TOKEN is not set in .env\n");
            exit(1);
        }

        $running = true;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () use (&$running) { $running = false; });
            pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
        }

        $offset = 0;

        // Register the bot command menu (/start, /ping, …) once at startup.
        $this->syncCommands();

        while ($running) {
            try {
                $this->reloadEnv();
                $updates = $this->getUpdates($offset, 25);
                foreach ($updates as $update) {
                    $offset = max($offset, (int) ($update['update_id'] ?? 0) + 1);
                    $this->handleUpdate($update);
                }
                if ($once) {
                    break;
                }
            } catch (Throwable $e) {
                error_log('[telegram-bot] ' . $e->getMessage());
                if ($once) {
                    break;
                }
                sleep(2);
            }
        }
    }

    // ── Update routing ─────────────────────────────────────────────────────

    private function handleUpdate(array $update): void
    {
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        } elseif (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        }
    }

    private function handleMessage(array $message): void
    {
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $text = trim((string) ($message['text'] ?? ''));
        if ($chatId === 0) {
            return;
        }

        $user = $this->resolveUser($message['from'] ?? []);
        if (!$user) {
            $this->sendMessage($chatId, 'Доступ запрещён. Обратитесь к администратору.');
            return;
        }

        if ($text === '/start' || $text === '/servers' || $text === self::BTN_START) {
            $this->showServers($chatId);
            return;
        }
        if ($text === '/ping' || $text === self::BTN_PING) {
            $this->clearState($chatId);
            $this->pingServers($chatId);
            return;
        }
        if ($text === '/cancel' || $text === '/help') {
            $this->clearState($chatId);
            $this->sendHelp($chatId);
            return;
        }

        $state = $this->state[$chatId] ?? null;
        if ($state && ($state['step'] ?? '') === 'awaiting_name') {
            $this->handleNameInput($chatId, $user, $text);
            return;
        }

        // Unknown input — show the server list again.
        $this->showServers($chatId);
    }

    private function handleCallback(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $chatId = (int) ($callback['message']['chat']['id'] ?? 0);
        $data = (string) ($callback['data'] ?? '');
        if ($chatId === 0) {
            return;
        }

        $user = $this->resolveUser($callback['from'] ?? []);
        if (!$user) {
            $this->answerCallbackQuery($callbackId, 'Доступ запрещён');
            return;
        }

        // Answer early so the button spinner clears before any slow work
        // (config creation can SSH to the remote server and take a while).
        $this->answerCallbackQuery($callbackId);

        if ($data === 'servers') {
            $this->showServers($chatId);
        } elseif (strpos($data, 'server:') === 0) {
            $this->selectServer($chatId, (int) substr($data, 7));
        } elseif (strpos($data, 'proto:') === 0) {
            // proto:{server_id}:{protocol_id}
            $parts = explode(':', $data);
            $this->createConfig($chatId, $user, (int) ($parts[1] ?? 0), (int) ($parts[2] ?? 0));
        } elseif ($data === 'cancel') {
            $this->clearState($chatId);
            $this->sendMessage($chatId, 'Отменено.');
        }
    }

    // ── Bot flows ──────────────────────────────────────────────────────────

    private function showServers(int $chatId): void
    {
        $this->clearState($chatId);
        $servers = array_values(array_filter(VpnServer::listAll(), function ($s) {
            return ($s['status'] ?? '') === 'active';
        }));
        if (empty($servers)) {
            $this->sendMessage($chatId, 'Нет активных серверов. Добавьте сервер в панели.', $this->menuKeyboard());
            return;
        }

        $keyboard = [];
        foreach ($servers as $server) {
            $keyboard[] = [[
                'text' => $server['name'] . ' (' . $server['host'] . ')',
                'callback_data' => 'server:' . $server['id'],
            ]];
        }
        $this->sendMessage($chatId, 'Выберите сервер:', ['inline_keyboard' => $keyboard]);
        $this->sendMessage($chatId, 'Или воспользуйтесь меню ниже.', $this->menuKeyboard());
    }

    /**
     * Ping every active server (SSH connectivity check) and report availability.
     */
    private function pingServers(int $chatId): void
    {
        $servers = array_values(array_filter(VpnServer::listAll(), function ($s) {
            return ($s['status'] ?? '') === 'active';
        }));

        if (empty($servers)) {
            $this->sendMessage($chatId, 'Нет активных серверов. Добавьте сервер в панели.', $this->menuKeyboard());
            return;
        }

        $this->sendMessage($chatId, '📡 Проверяю доступность серверов…', $this->menuKeyboard());

        $lines = [];
        foreach ($servers as $server) {
            $start = microtime(true);
            $ok = false;
            try {
                $ok = (new VpnServer((int) $server['id']))->testConnection();
            } catch (Throwable $e) {
                $ok = false;
            }
            $latency = (int) round((microtime(true) - $start) * 1000);

            $lines[] = sprintf(
                '%s (%s): %s%s',
                $server['name'],
                $server['host'],
                $ok ? '✅ доступен' : '❌ недоступен',
                $ok ? " · {$latency} мс" : ''
            );
        }

        $this->sendMessage($chatId, "📡 Результат проверки:\n\n" . implode("\n", $lines), $this->menuKeyboard());
    }

    /**
     * Persistent reply-keyboard menu with the Start / Ping buttons.
     */
    private function menuKeyboard(): array
    {
        return [
            'keyboard' => [
                [self::BTN_START, self::BTN_PING],
            ],
            'resize_keyboard' => true,
        ];
    }

    /**
     * Register the bot command menu (the "/" list) via setMyCommands.
     */
    private function syncCommands(): void
    {
        if ($this->token === '') {
            return;
        }
        try {
            $this->request('setMyCommands', [
                'commands' => [
                    ['command' => 'start', 'description' => 'Список серверов'],
                    ['command' => 'ping', 'description' => 'Проверить доступность серверов'],
                    ['command' => 'cancel', 'description' => 'Отмена'],
                    ['command' => 'help', 'description' => 'Помощь'],
                ],
            ]);
        } catch (Throwable $e) {
            error_log('[telegram-bot] setMyCommands failed: ' . $e->getMessage());
        }
    }

    private function selectServer(int $chatId, int $serverId): void
    {
        $server = $this->getOwnServer($serverId);
        if (!$server) {
            $this->sendMessage($chatId, 'Сервер не найден или недоступен.');
            return;
        }

        $this->state[$chatId] = ['step' => 'awaiting_name', 'server_id' => $serverId];
        $this->sendMessage($chatId, "Сервер: {$server['name']}\nВведите имя клиента (например, Ivan-iPhone):");
    }

    private function handleNameInput(int $chatId, array $user, string $name): void
    {
        $serverId = (int) ($this->state[$chatId]['server_id'] ?? 0);
        if ($name === '') {
            $this->sendMessage($chatId, 'Имя не может быть пустым. Попробуйте ещё раз.');
            return;
        }
        if (mb_strlen($name) > 64) {
            $this->sendMessage($chatId, 'Слишком длинное имя (максимум 64 символа).');
            return;
        }

        $protocols = $this->listInstalledProtocols($serverId);
        if (count($protocols) > 1) {
            $this->state[$chatId] = ['step' => 'awaiting_protocol', 'server_id' => $serverId, 'name' => $name];
            $keyboard = [];
            foreach ($protocols as $protocol) {
                $keyboard[] = [[
                    'text' => $protocol['name'],
                    'callback_data' => 'proto:' . $serverId . ':' . $protocol['protocol_id'],
                ]];
            }
            $this->sendMessage($chatId, 'Выберите протокол:', ['inline_keyboard' => $keyboard]);
            return;
        }

        $this->createConfig($chatId, $user, $serverId, isset($protocols[0]['protocol_id']) ? (int) $protocols[0]['protocol_id'] : null, $name);
    }

    private function createConfig(int $chatId, array $user, int $serverId, ?int $protocolId, ?string $name = null): void
    {
        $name = $name ?? trim((string) ($this->state[$chatId]['name'] ?? ''));
        $this->clearState($chatId);

        if ($name === '') {
            $this->sendMessage($chatId, 'Ошибка: имя клиента не задано.');
            return;
        }

        try {
            $server = $this->getOwnServer($serverId);
            if (!$server) {
                $this->sendMessage($chatId, 'Сервер не найден или недоступен.');
                return;
            }

            $this->sendMessage($chatId, '⏳ Создаю конфиг…');

            $clientId = VpnClient::create($serverId, (int) $user['id'], $name, null, $protocolId, null, null);
            $client = new VpnClient($clientId);

            // Same refresh step as /api/clients/create and /clients/{id}/download.
            try {
                $regen = $client->regenerateConfigFromServer(true);
                if (is_array($regen) && empty($regen['success']) && ($regen['error'] ?? '') === 'awg_params_missing') {
                    $this->sendMessage($chatId, 'Ошибка: на сервере отсутствуют AWG параметры. Переустановите/почините AWG.');
                    return;
                }
            } catch (Throwable $e) {
                error_log('[telegram-bot] regen failed: ' . $e->getMessage());
            }

            $clientData = $client->getData();
            $config = $client->getConfig();

            $baseName = !empty($clientData['login']) ? $clientData['login'] : $clientData['name'];
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $baseName);
            if ($safeName === '') {
                $safeName = 'client_' . $clientId;
            }
            $filename = $safeName . '.conf';

            $caption = "✅ Конфиг создан\nИмя: {$clientData['name']}\nIP: {$clientData['client_ip']}\nСервер: {$server['name']}";

            // Send the QR code as a photo alongside the .conf file (so the
            // client can be added by scanning the camera importer).
            $protocolIdResolved = (int) ($clientData['protocol_id'] ?? $protocolId ?? 0);
            $qrFile = $this->renderQrPng($config, $protocolIdResolved);
            if ($qrFile !== null) {
                $this->sendPhoto($chatId, $qrFile, $caption . "\n\n📱 Отсканируйте QR-код или откройте файл ниже.");
                @unlink($qrFile);
                $docCaption = 'Файл конфигурации: ' . $filename;
            } else {
                $docCaption = $caption;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'tgconf_');
            file_put_contents($tmp, $config);

            $this->sendDocument($chatId, $tmp, $filename, $docCaption);

            @unlink($tmp);
        } catch (Throwable $e) {
            $this->sendMessage($chatId, 'Ошибка при создании конфига: ' . $e->getMessage());
        }
    }

    private function sendHelp(int $chatId): void
    {
        $this->sendMessage($chatId, "Команды:\n/start — список серверов\n/ping — проверить доступность серверов\n/cancel — отмена\n\nВыберите сервер, введите имя клиента — и получите готовый .conf файл и QR-код для подключения.", $this->menuKeyboard());
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function resolveUser(array $from): ?array
    {
        $this->reloadEnv();
        $this->loadAllowList();

        $id = (int) ($from['id'] ?? 0);
        $username = strtolower(ltrim((string) ($from['username'] ?? ''), '@'));

        $allowed = ($id > 0 && isset($this->allowedIds[$id]))
            || ($username !== '' && isset($this->allowedUsernames[$username]));

        return $allowed ? $this->getAdminUser() : null;
    }

    private function loadAllowList(): void
    {
        if ($this->allowedIds !== null) {
            return;
        }
        $this->allowedIds = [];
        $this->allowedUsernames = [];

        $raw = (string) Config::get('TELEGRAM_ALLOWED_IDS', '');
        foreach (explode(',', $raw) as $entry) {
            $entry = strtolower(ltrim(trim($entry), '@'));
            if ($entry === '') {
                continue;
            }
            if (ctype_digit($entry)) {
                $this->allowedIds[(int) $entry] = true;
            } else {
                $this->allowedUsernames[$entry] = true;
            }
        }
    }

    private function getAdminUser(): ?array
    {
        if ($this->adminUser !== null) {
            return $this->adminUser;
        }
        $this->adminUser = null;

        $email = (string) Config::get('ADMIN_EMAIL', '');
        if ($email !== '') {
            $this->adminUser = Auth::getUserByEmail($email);
        }
        if ($this->adminUser === null) {
            // Fallback: first admin in the DB.
            foreach (Auth::listUsers() as $u) {
                if (($u['role'] ?? '') === 'admin') {
                    $this->adminUser = $u;
                    break;
                }
            }
        }
        return $this->adminUser;
    }

    private function getOwnServer(int $serverId): ?array
    {
        $server = new VpnServer($serverId);
        return $server->getData();
    }

    private function listInstalledProtocols(int $serverId): array
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT sp.protocol_id, p.name, p.slug FROM server_protocols sp JOIN protocols p ON p.id = sp.protocol_id WHERE sp.server_id = ? ORDER BY p.name');
        $stmt->execute([$serverId]);
        return $stmt->fetchAll();
    }

    /**
     * Render the client's QR code to a temporary PNG file, or null if it
     * can't be generated (then the bot still sends the .conf file alone).
     */
    private function renderQrPng(string $config, int $protocolId): ?string
    {
        try {
            $slug = '';
            if ($protocolId > 0) {
                $stmt = DB::conn()->prepare('SELECT slug FROM protocols WHERE id = ? LIMIT 1');
                $stmt->execute([$protocolId]);
                $slug = (string) $stmt->fetchColumn();
            }

            $dataUri = VpnClient::generateQRCode($config, $slug);
            $prefix = 'data:image/png;base64,';
            if ($dataUri === '' || !str_starts_with($dataUri, $prefix)) {
                return null;
            }
            $bytes = base64_decode(substr($dataUri, strlen($prefix)), true);
            if ($bytes === false || $bytes === '') {
                return null;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'tgqr_');
            if ($tmp === false) {
                return null;
            }
            file_put_contents($tmp, $bytes);
            return $tmp;
        } catch (Throwable $e) {
            error_log('[telegram-bot] QR render failed: ' . $e->getMessage());
            return null;
        }
    }

    private function clearState(int $chatId): void
    {
        unset($this->state[$chatId]);
    }

    // ── Telegram API client ────────────────────────────────────────────────

    public function getUpdates(int $offset, int $timeout = 25, int $limit = 100): array
    {
        return $this->request('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'limit' => $limit,
            'allowed_updates' => ['message', 'callback_query'],
        ]);
    }

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): array
    {
        $params = ['chat_id' => $chatId, 'text' => $text];
        if ($replyMarkup !== null) {
            $params['reply_markup'] = $replyMarkup;
        }
        return $this->request('sendMessage', $params);
    }

    public function sendDocument(int $chatId, string $filePath, string $filename, string $caption = ''): array
    {
        $file = new CURLFile($filePath, 'text/plain', $filename);
        $params = ['chat_id' => $chatId];
        if ($caption !== '') {
            $params['caption'] = $caption;
        }
        return $this->request('sendDocument', $params, ['document' => $file]);
    }

    public function sendPhoto(int $chatId, string $filePath, string $caption = ''): array
    {
        $file = new CURLFile($filePath, 'image/png', 'qr.png');
        $params = ['chat_id' => $chatId];
        if ($caption !== '') {
            $params['caption'] = $caption;
        }
        return $this->request('sendPhoto', $params, ['photo' => $file]);
    }

    public function answerCallbackQuery(string $callbackId, string $text = ''): array
    {
        try {
            return $this->request('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => $text]);
        } catch (Throwable $e) {
            // "query is too old / response timeout expired / query ID is invalid"
            // is harmless (the button spinner just stays) — never crash the loop.
            error_log('[telegram-bot] answerCallbackQuery: ' . $e->getMessage());
            return [];
        }
    }

    private function request(string $method, array $params = [], array $files = []): array
    {
        $ch = curl_init($this->apiBase . '/' . $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_POST, true);

        if (!empty($files)) {
            $params = array_merge($params, $files);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        } else {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        }

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new Exception('Telegram API request failed: ' . $error);
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['ok'] ?? false) !== true) {
            throw new Exception('Telegram API error: ' . ($data['description'] ?? $raw));
        }

        // Some methods (e.g. answerCallbackQuery) return a boolean `true` result
        // rather than an object — normalise it so the `: array` return type holds.
        $result = $data['result'] ?? [];
        return is_array($result) ? $result : [];
    }
}
