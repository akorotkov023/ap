# Developer Guide

Guide for contributing to the Amnezia VPN Web Panel.

## Overview

The panel is plain **PHP 8.2 + MySQL 8.0** with **no framework** (not Laravel/Symfony):

- A custom micro-router (`inc/Router.php`) with **all routes defined in `public/index.php`** (~4500 lines).
- **Twig** templates.
- Global classes in `inc/` (and `controllers/`) — **no namespaces**.
- Fully Dockerized (three services: `web`, `db`, `dind`).

There is **no test suite, linter, typechecker, or CI**. No Composer scripts, no PHPUnit. The only fast static check is PHP lint:

```bash
docker compose exec web php -l <file>
```

Verify changes manually.

## Prerequisites

- Docker + Docker Compose
- (Optional) local PHP 8.2 + Composer + MySQL 8.0 for non-Docker development

## Development Setup

### Docker (recommended)

```bash
cp .env.local .env          # first time; .env is gitignored and required
docker compose up -d --build
docker compose exec web composer install
# app: http://localhost:8082
```

DB migrations are auto-applied by the MySQL entrypoint **only on a fresh volume**. For an existing install, apply them manually:

```bash
for f in migrations/*.sql; do
  docker compose exec -T db mysql --default-character-set=utf8mb4 -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" < "$f"
done
```

### Local (without Docker)

1. Install PHP 8.2 with extensions: `pdo_mysql`, `mbstring`, `gd`, `curl`, `exif`, `pcntl`, `bcmath`, `ldap` (optional).
2. Install MySQL 8.0 and create the database/user from `.env.local`.
3. Run Composer and the built-in server:

```bash
composer install
DB_HOST=127.0.0.1 php -S localhost:8000 -t public
```

`inc/DB.php` reads `DB_HOST` from `Config`/env and defaults to `127.0.0.1`, so it works outside Docker as long as the DB credentials are exported.

## Project Architecture

There is no MVC in the classic sense. The request flow is:

```
Request → public/index.php (Router dispatch → inline route closure)
                     ↓
              inc/*.php classes (DB, Auth, VpnServer, VpnClient, ...)
                     ↓
              Twig template (View::render)  or  JSON response (API)
```

### Entry point (`public/index.php`)

The single entrypoint. It:

1. Starts the session.
2. `require_once`s the core classes from `inc/` explicitly.
3. Loads env config via `Config::load(__DIR__ . '/../.env')`.
4. Verifies the DB connection and seeds the admin user (`Auth::seedAdmin`).
5. Registers **all routes** via `Router::get/post/put/delete`.

> When you add a new `inc/` class, add an explicit `require_once` in `index.php` (and/or the consuming `bin/` script). The Composer PSR-4 autoloader (`"": "inc/"`) is **not** relied on for `inc/`.

### Router (`inc/Router.php`)

Pattern-matching router:

```php
Router::get('/servers/{id}', function ($params) {
    $serverId = (int)$params['id'];
    // ...
});

Router::post('/api/clients/{id}/revoke', function ($params) {
    requireApiAuth();
    // ...
});
```

Methods: `Router::get()`, `Router::post()`, `Router::put()`, `Router::delete()`. Route variables use `{name}` and are passed to the closure as an associative array. Matching is exact (no trailing slash). The last route with no match returns `404 Not Found`.

### Configuration (`inc/Config.php`)

Reads `.env` via `Config::load()` and falls back to `getenv()`. Access values with:

```php
$value = Config::get('KEY', 'default');
```

`Config::load()` also `putenv()`s every key, so `getenv()` works too.

### Database (`inc/DB.php`)

Singleton PDO connection. Reads `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` from `Config`/env (not hardcoded):

```php
$pdo = DB::conn();
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$user = $stmt->fetch(); // FETCH_ASSOC by default
```

Options: `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES => false`, `SET NAMES utf8mb4`.

### Authentication (`inc/Auth.php`)

Session-based auth for the admin UI (with optional LDAP fallback):

```php
Auth::login($email, $password);   // bool; tries LDAP first, then local DB
Auth::logout();
Auth::check();                    // bool — session has user_id?
Auth::user();                     // ?array — current user row
Auth::isAdmin();                  // bool
Auth::seedAdmin($email, $pass);   // idempotent; creates the admin and re-syncs the password if ADMIN_PASSWORD changes
Auth::listUsers();                // array
Auth::setRole($userId, $role);    // 'admin' | 'user'
Auth::getUserByEmail($email);     // ?array
```

The admin account is auto-seeded on first request from `ADMIN_EMAIL`/`ADMIN_PASSWORD`. `ADMIN_PASSWORD` is authoritative: when it changes in `.env`, the admin's password is re-synced on the next request. The sync is detected via a SHA-256 marker stored in the `settings` table (namespace `admin_seed`, key `password_marker`), so a password changed through the UI is preserved unless `.env` itself changes — no bcrypt re-hash runs on every request. Self-registration is off unless `ALLOW_REGISTRATION=true`.

In `index.php`, route handlers use helpers: `requireAuth()`, `requireAdmin()`, `requireApiAuth()`, `getAuthUser()`.

### API auth (`inc/JWT.php`)

The `/api/*` routes authenticate via JWT (`firebase/php-jwt` v7), falling back to session. Token:

```bash
curl -X POST http://localhost:8082/api/auth/token \
  -d "email=admin@amnez.ia&password=admin1234"
```

> Exception: `GET /api/protocols` (and the other `/api/protocols*` admin routes) use **session admin auth, not JWT**.

Persistent API tokens are managed via `/api/tokens`.

### Views (`inc/View.php`)

Twig rendering:

```php
View::init(__DIR__ . '/../templates', $globals);  // called once in index.php
View::render('servers/view.twig', ['server' => $server]);
```

Auto-escaping is enabled (`autoescape => 'html'`). Custom Twig helpers are registered: the `t()` translation function, `trans` filter, `getFlag()` and `bytes_format` filter.

### Models — `VpnServer` and `VpnClient`

These are global classes, not framework models. `VpnServer`:

```php
$serverId = VpnServer::create([/* name, host, port, username, auth_type, ... */]);
$server = new VpnServer($serverId);
$server->deploy();                    // SSH + docker to the remote host
$server->testConnection();            // bool
$server->getData();                   // ?array
$server->executeCommand($cmd);        // runs over SSH
$server->createBackup($userId);       // int backup id
$server->restoreBackup($backupId);
VpnServer::listByUser($userId);
VpnServer::listAll();
```

`VpnClient`:

```php
$clientId = VpnClient::create($serverId, $userId, $name, $expiresInDays, $protocolId);
$client = new VpnClient($clientId);
$client->getConfig();                  // config text
$client->getQRCode();                  // data URI
$client->revoke();
$client->restore();
$client->syncStats();
$client->setTrafficLimit($limitBytes);
VpnClient::setExpiration($clientId, $expiresAt);
VpnClient::extendExpiration($clientId, $days);
VpnClient::getExpiringClients($days);
VpnClient::disableExpiredClients();
VpnClient::disableClientsOverLimit();
VpnClient::listByServer($serverId);
VpnClient::listByUser($userId);
```

### Feature controllers (`controllers/`)

Larger features are split into controllers (`SettingsController`, `ProtocolManagementController`, `AIController`, `LogsController`, `ScenarioController`). These are global classes too, required **lazily inside the route closure**:

```php
Router::get('/settings/protocols', function () {
    require_once __DIR__ . '/../controllers/ProtocolManagementController.php';
    (new ProtocolManagementController())->index();
});
```

> When adding a controller, require it in the route closure. The PSR-4 prefix only maps `"" => "inc/"`, so `controllers/` is **not** autoloadable.

## Migrations

SQL files in `migrations/` are applied **alphabetically** (by MySQL's `docker-entrypoint-initdb.d`) on a fresh volume. Keep them **idempotent** — use `ON DUPLICATE KEY UPDATE` for INSERTs.

The admin is **not** created by a migration; it is seeded at runtime by `Auth::seedAdmin`.

## Adding a Feature

### Example: add a per-server "note" field

**1. Add a migration** (`migrations/073_add_server_note.sql`):

```sql
ALTER TABLE vpn_servers ADD COLUMN note TEXT NULL;
```

**2. Add a method to the class** (`inc/VpnServer.php`):

```php
public function setNote(string $note): bool {
    $stmt = DB::conn()->prepare('UPDATE vpn_servers SET note = ? WHERE id = ?');
    return $stmt->execute([$note, $this->getId()]);
}
```

**3. Add a route** (`public/index.php`):

```php
Router::post('/servers/{id}/note', function ($params) {
    requireAuth();
    $server = new VpnServer((int)$params['id']);
    $server->setNote(trim($_POST['note'] ?? ''));
    redirect('/servers/' . $server->getId());
});
```

**4. Add the field to a template** (`templates/servers/view.twig`).

**5. Verify** with `docker compose exec web php -l public/index.php` and manual testing.

## Code Style

The codebase uses global classes with no namespaces. Keep new code consistent with the surrounding files:

- **PHP**: plain procedural-ish class methods, prepared statements, `password_hash()`/`password_verify()`.
- **SQL**: prepared statements everywhere; uppercase keywords.
- **Twig**: rely on auto-escaping; use `{{ var|raw }}` only for trusted content.
- **Config**: read via `Config::get()`, never hardcode credentials.

Example — safe DB access:

```php
$stmt = DB::conn()->prepare('SELECT * FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();
```

## Security Notes

- **No CSRF protection** (documented TODO). Session auth is used for admin UI; JWT for `/api/*`.
- Passwords are hashed with `password_hash(..., PASSWORD_DEFAULT)` (bcrypt/argon2 depending on PHP).
- SQL injection is prevented via prepared statements and `EMULATE_PREPARES => false`.
- XSS is mitigated by Twig auto-escaping.
- Report vulnerabilities privately — see [`SECURITY.md`](SECURITY.md).

## Docker Services

- **web** — PHP 8.2 Apache, mounts the project at `/var/www/html`, exposes `8082:80`.
- **db** — MySQL 8.0, exposes `3309:3306`, runs migrations from `./migrations`.
- **dind** — Docker-in-Docker; `web` sets `DOCKER_HOST=tcp://dind:2375` and deploys protocols to remote servers over SSH + the docker CLI.

### Cron jobs (baked into the Dockerfile)

| Schedule | Command | Purpose |
|----------|---------|---------|
| hourly   | `bin/check_expired_clients.php` | Disable expired clients |
| hourly   | `bin/check_traffic_limits.php` | Disable clients over their traffic limit |
| `*/30`   | `bin/sync_ldap_users.php` | LDAP user sync |
| `*/3`    | `bin/monitor_metrics.sh` | Keep the metrics collector alive |
| `*/3`    | `bin/telegram_bot_monitor.sh` | Keep the Telegram bot alive |

Restart cron if needed: `docker compose exec web service cron start`.

## CLI / Cron Scripts (`bin/`)

| Script | Purpose |
|--------|---------|
| `check_expired_clients.php` | Disable expired clients |
| `check_traffic_limits.php` | Disable clients over traffic limit |
| `collect_metrics.php` | Collect metrics (run by `monitor_metrics.sh`) |
| `sync_ldap_users.php` | Sync LDAP users |
| `translate.php` / `translate_all.php` | Translation helpers |
| `telegram_bot.php` | Telegram bot (long-polling) |
| `telegram_bot_monitor.sh` | Keepalive wrapper for the bot |
| `check_server_ssh_fields.php` | Inspect SSH fields on servers |

## Debugging

### Logs

```bash
docker compose logs -f web        # web container
docker compose logs -f db         # database
docker compose exec web tail -f /var/log/cron.log          # cron output
docker compose exec web tail -f /var/log/metrics_collector.log
docker compose exec web tail -f /var/log/ldap_sync.log
```

### Quick checks

```bash
docker compose exec web php -l public/index.php            # lint one file
docker compose exec web php -r "require 'inc/Config.php'; require 'inc/DB.php'; var_dump(DB::conn() instanceof PDO);"
```

## Deployment / Updating

- `./update.sh` (repo root) is the in-place auto-updater for deployed panels: `./update.sh [--force] [--skip-backup] [--rollback[=VERSION]]`.
- For a fresh install, see [`README.md`](README.md).
- Production checklist: change the default admin password, set a strong `JWT_SECRET`, set up HTTPS in front of Apache, and review `.env`.

## Resources

- [PHP Documentation](https://www.php.net/docs.php)
- [MySQL Reference](https://dev.mysql.com/doc/)
- [Twig Documentation](https://twig.symfony.com/doc/)
- [Docker Documentation](https://docs.docker.com/)
- [WireGuard Protocol](https://www.wireguard.com/)
- [Amnezia VPN GitHub](https://github.com/amnezia-vpn/amnezia-client)

---

Happy coding! 🚀
