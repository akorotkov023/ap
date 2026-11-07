# AGENTS.md

Amnezia VPN Web Panel — plain PHP 8.2 + MySQL 8.0, no framework. Custom micro-router, Twig templates, Dockerized. Not Laravel/Symfony.

## Commands

There is **no test/lint/typecheck/build tooling** and no CI. No composer scripts, no PHPUnit. Verify changes manually.

```bash
cp .env.local .env          # first time; .env is gitignored and required
docker compose up -d --build
docker compose exec web composer install
# app: http://localhost:8082  (default admin: admin@amnez.ia / admin)
```

- DB migrations are auto-applied by the MySQL entrypoint **only on a fresh volume**. For an existing install, apply manually:
  `for f in migrations/*.sql; do docker compose exec -T db mysql ... "$DB_DATABASE" < "$f"; done`
- Quick smoke check: `docker compose exec web php -l <file>` (php lint) — that's the only fast static check available.
- `./update.sh` (root) is the in-place auto-updater for deployed panels: `./update.sh [--force] [--skip-backup] [--rollback[=VERSION]]`. It's deployment tooling, not part of the app runtime.
- Telegram bot (`bin/telegram_bot.php`, long-polling) reuses `VpnClient` to create a client and send its `.conf`. Auto-started by `bin/telegram_bot_monitor.sh` (from `/start.sh` + a `*/3` cron keepalive, runs as `www-data`) and keeps running inside the `web` container. Config via `.env`: `TELEGRAM_BOT_TOKEN` + `TELEGRAM_ALLOWED_IDS=<telegram_id|@username>,…` (allow-listed senders act as the `ADMIN_EMAIL` admin). The bot re-reads `.env` on every poll/message (`TelegramBot::reloadEnv()`), so allow-list changes take effect without a restart; PID/log live in `/var/www/html/logs/`. The `/servers` page shows a live status badge + a «Перезапустить» button (admin, `POST /servers/telegram-bot/restart` → monitor `restart` mode). `--once` is debug-only — don't run it while the daemon is polling (Telegram 409s concurrent getUpdates).

## Architecture

- `public/index.php` — the single entrypoint (≈4500 lines). **All routes live here** via `Router::get/post/put/delete`. New routes go here.
- `inc/` — core classes (`VpnServer`, `VpnClient`, `InstallProtocolManager`, `ServerMonitoring`, `Auth`, `DB`, `JWT`, `QrUtil`, etc.). Classes are **global (no namespaces)** despite the PSR-4 `"" => "inc/"` autoload entry. `index.php` and `bin/*` scripts `require_once` each class explicitly — the autoloader is not relied on for `inc/`. When adding a new `inc/` class, add an explicit `require_once` (in `index.php` and/or the consuming `bin/` script).
- `controllers/` — larger feature controllers (`SettingsController`, `ProtocolManagementController`, `AIController`, `LogsController`, `ScenarioController`), required lazily **inside** route closures in `index.php`. Exception: `ScenarioController` is instantiated in the `/admin/scenarios*` routes but never `require_once`'d anywhere, and the PSR-4 `"" => "inc/"` prefix doesn't cover `controllers/` — so those routes currently throw `Class "ScenarioController" not found`. (When adding a controller, require it in the route closure like the others.)
- `templates/` — Twig templates. `migrations/*.sql` — applied alphabetically; keep migrations idempotent via `ON DUPLICATE KEY UPDATE`. `bin/` — cron/CLI scripts.

## Gotchas

- `inc/Config.php` reads `.env` (`Config::load`) and falls back to `getenv`. `DB_HOST` is `db` inside Docker, defaults to `127.0.0.1` outside. `DB.php` (unlike old docs) reads from `Config`/env, not hardcoded values.
- Admin user is **auto-seeded** on first request from `ADMIN_EMAIL`/`ADMIN_PASSWORD` (`Auth::seedAdmin` in `index.php`). `ADMIN_PASSWORD` is authoritative **at startup**: `/start.sh` runs `bin/sync_admin_password.php` (`Auth::syncAdminPassword`), which creates the admin or updates its password when `.env` changes (detected via a SHA-256 marker in `settings`). It runs once at container start — not per request — to avoid lock contention on `users`. To apply a `.env` password change, rebuild/restart the `web` container or run the bin script manually. No separate registration unless `ALLOW_REGISTRATION=true`.
- Docker stack has three services: `web`, `db`, and `dind` (Docker-in-Docker). `web` sets `DOCKER_HOST=tcp://dind:2375` and deploys protocols to remote servers over SSH + the docker CLI.
- Cron jobs are baked into `Dockerfile` (hourly expiry/traffic checks, 30-min LDAP sync, 3-min metrics monitor). Restart via `docker compose exec web service cron start` if needed.
- `composer.lock` is **gitignored** (unusual) but present locally. `vendor/` gitignored.
- `keyd/` is an unrelated, untracked foreign git repo (rvaiya/keyd keyboard remapper) — do not modify or rely on it.
- `run_migration_053.php` is a one-off migration helper at repo root, not a general tool.
- Cloudflare WARP must be installed **last** on a server (after AWG/XRay/AIVPN), per README.
- No CSRF protection (documented TODO); session auth for admin UI, JWT (`inc/JWT.php`, firebase/php-jwt v7) for `/api/*` — note `/api/protocols` uses session admin auth, not JWT.

## Style

- inc/classes use global classes, no namespaces; methods referenced in `DEVELOPER.md`/`PROJECT_STRUCTURE.md` are partially stale — trust the actual `inc/*.php` sources over prose docs.
- Docs exist but drift: `PROJECT_STRUCTURE.md`, `DEVELOPER.md`, and `README*` describe older layouts (e.g. `test_qr.php`, `CHANGELOG.md` no longer exist). Verify against the code.
