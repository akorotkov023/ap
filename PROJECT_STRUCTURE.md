# Project Structure

Complete file structure of the Amnezia VPN Web Panel with descriptions.

> This document reflects the **current** layout. Some files referenced by older docs
> (`CHANGELOG.md`, `TESTING.md`, `test_qr.php`, `public/.htaccess`, `.env.example`)
> no longer exist.

```
amnezia-vpn-web-panel/
│
├── 📄 README.md                  # Main documentation (English)
├── 📄 README_RU.md               # Russian documentation
├── 📄 README_ZH.md               # Chinese documentation
├── 📄 API_EXAMPLES.md            # REST API usage examples (curl, Python, JS)
├── 📄 DEVELOPER.md               # Developer guide
├── 📄 PROJECT_STRUCTURE.md       # This file
├── 📄 RELEASE_NOTES.md           # Release notes (v2.0.1)
├── 📄 SECURITY.md                # Security / vulnerability reporting policy
├── 📄 LDAP_SETUP.md              # LDAP/Active Directory integration guide
├── 📄 LICENSE                    # MIT License
├── 📄 AGENTS.md                  # AI-agent working notes
│
├── 🐳 Docker / Environment
│   ├── docker-compose.yml        # Services: web, db, dind
│   ├── Dockerfile                # PHP 8.2 Apache image + cron + start.sh
│   ├── apache.conf               # Apache virtual host (document root: public/)
│   ├── my.cnf                    # MySQL configuration
│   ├── .env.local                # Environment template (copy to .env)
│   ├── .env                      # Actual environment (gitignored)
│   ├── .dockerignore
│   └── .gitignore
│
├── 📦 Dependencies
│   ├── composer.json             # PHP dependencies + PSR-4 autoload ("" => "inc/")
│   └── composer.lock             # Locked versions (gitignored, present locally)
│
├── 🖥️ Frontend (Entry Point)
│   └── public/
│       └── index.php             # Single entry point — ALL routes live here (~4500 lines)
│
├── 🧩 Backend (Core Classes — global, no namespaces)
│   └── inc/
│       ├── Router.php            # Micro-router (get/post/put/delete, {param} matching)
│       ├── Config.php            # .env loader + getenv fallback
│       ├── DB.php                # PDO singleton (reads Config/env)
│       ├── Auth.php              # Session auth, admin seeding, roles
│       ├── JWT.php               # JWT API auth (firebase/php-jwt v7)
│       ├── View.php              # Twig rendering + custom helpers
│       ├── Translator.php        # Multi-language translation
│       ├── VpnServer.php         # Server management & SSH deployment
│       ├── VpnClient.php         # Client management, config & QR
│       ├── QrUtil.php            # Amnezia-compatible QR encoding
│       ├── PanelImporter.php     # Import from wg-easy / 3x-ui
│       ├── InstallProtocolManager.php  # Protocol install scripts
│       ├── ProtocolService.php   # Protocol CRUD
│       ├── ServerMonitoring.php  # Metrics & monitoring
│       ├── BackupLibrary.php     # Server backup / restore
│       ├── Ssh.php               # SSH command execution
│       ├── LdapSync.php          # LDAP/AD authentication & sync
│       ├── OpenRouterService.php # AI integration (OpenRouter)
│       ├── TelegramBot.php       # Telegram bot (long-polling)
│       ├── Logger.php            # Logging
│       └── Awg31Parameters.php   # AWG obfuscation parameters
│
├── 🎛️ Feature Controllers (global classes, required lazily in route closures)
│   └── controllers/
│       ├── SettingsController.php
│       ├── ProtocolManagementController.php
│       ├── AIController.php
│       ├── LogsController.php
│       └── ScenarioController.php
│
├── 🖼️ Templates (Twig)
│   └── templates/
│       ├── layout.twig           # Base layout
│       ├── login.twig            # Login page
│       ├── register.twig         # Registration page
│       ├── dashboard.twig        # Dashboard
│       ├── settings.twig         # Settings
│       ├── settings_old.twig     # Legacy settings page
│       ├── servers/
│       │   ├── index.twig        # Server list
│       │   ├── create.twig       # Add-server form
│       │   ├── deploy.twig       # Deployment progress
│       │   ├── view.twig         # Server details & clients
│       │   └── monitoring.twig   # Server monitoring
│       ├── clients/
│       │   └── view.twig         # Client config & QR code
│       ├── settings/
│       │   ├── ldap.twig
│       │   ├── logs.twig
│       │   ├── protocol_form.twig
│       │   ├── protocols_management.twig
│       │   ├── protocol_template_editor.twig
│       │   ├── scenario_form.twig
│       │   ├── scenarios.twig
│       │   └── scenario_view.twig
│       ├── ai/
│       │   └── preview_generation.twig
│       └── tools/
│           └── qr_decode.twig
│
├── 💾 Database Migrations
│   └── migrations/               # SQL files, applied alphabetically (idempotent)
│       ├── 000_create_user.sql … 072_repair_mojibake_translations.sql
│       └── README.md             # Migration guide
│
├── 🔧 CLI / Cron Scripts
│   └── bin/
│       ├── check_expired_clients.php   # Disable expired clients (hourly)
│       ├── check_traffic_limits.php    # Disable over-limit clients (hourly)
│       ├── collect_metrics.php         # Metrics collector
│       ├── monitor_metrics.sh          # Metrics keepalive (every 3 min)
│       ├── sync_ldap_users.php         # LDAP sync (every 30 min)
│       ├── translate.php               # Translation helper
│       ├── translate_all.php           # Translate everything
│       ├── telegram_bot.php            # Telegram bot (long-polling)
│       ├── telegram_bot_monitor.sh     # Bot keepalive (every 3 min)
│       └── check_server_ssh_fields.php # Inspect server SSH fields
│
├── 🧪 Dev / Debug Scripts
│   └── scripts/                  # Contract tests, smoke tests, diagnostics, cleanup
│
├── 📖 Protocol Docs
│   └── docs/
│       └── awg31.md              # AWG31 protocol documentation
│
├── 📥 Import Examples
│   └── examples/
│       ├── README.md
│       ├── wg-easy-backup-example.json
│       └── 3x-ui-backup-example.json
│
├── 🧾 Runtime Directories
│   ├── backups/                  # Server backups (writable)
│   ├── logs/                     # Application / bot logs (writable)
│   └── migration_export/         # DB export helper (untracked)
│
└── 🧰 Deployment Tooling (repo root)
    ├── update.sh                 # In-place auto-updater for deployed panels
    ├── migrate-to-server.sh      # Migration helper
    ├── run_migration_053.php     # One-off migration helper (not a general tool)
    └── check-db.sh               # DB connectivity check
```

## Key Facts

- **Entry point**: `public/index.php` — a single file holding every route via `Router::get/post/put/delete`.
- **Classes**: global (no namespaces). New `inc/` classes need an explicit `require_once` in `index.php` / the consuming `bin/` script.
- **Autoload**: Composer PSR-4 maps `"" => "inc/"`, but it is not relied on for `inc/`; `controllers/` is **not** autoloadable and must be required inside route closures.
- **Config**: `inc/Config.php` reads `.env` and falls back to `getenv()`. `DB_HOST` is `db` inside Docker and defaults to `127.0.0.1` outside.
- **Admin**: auto-seeded on first request from `ADMIN_EMAIL` / `ADMIN_PASSWORD`.
- **Migrations**: applied alphabetically by the MySQL entrypoint on a fresh volume only; run manually for existing installs.
- **No tests / lint / CI**: the only static check is `php -l`.
- **No CSRF protection** (documented TODO). Session auth for the admin UI, JWT for `/api/*` (except `/api/protocols`, which uses session admin auth).

## Data Flow

### Server Deployment

```
POST /servers/create (or /api/servers/create)
    → VpnServer::create()          # insert DB record
    → VpnServer::deploy()          # SSH to remote host + docker CLI
        - install/verify Docker
        - pull protocol image (AWG/AWG2/XRay/AIVPN/...)
        - generate keys & config
        - start container, configure firewall/NAT
    → update DB with server details
```

### Client Creation

```
POST /servers/{id}/clients/create (or /api/clients/create)
    → VpnClient::create()
        - get server data
        - generate keys
        - assign next free IP
        - build config text
        - add peer to server (wg syncconf)
        - generate QR code (QrUtil)
        - insert DB record
    → return config + QR code
```

### Automatic Jobs (cron)

| When    | Job                              |
|---------|----------------------------------|
| hourly  | disable expired clients          |
| hourly  | disable over-traffic-limit clients |
| */30    | sync LDAP users                  |
| */3     | keep metrics collector alive     |
| */3     | keep Telegram bot alive          |

## Dependencies (Composer)

```json
{
  "require": {
    "php": ">=8.0",
    "twig/twig": "^3.8",
    "endroid/qr-code": "^4.8 || ^5.0",
    "firebase/php-jwt": "^7.0",
    "ext-pdo": "*",
    "ext-json": "*",
    "ext-curl": "*",
    "ext-gd": "*",
    "ext-sodium": "*"
  }
}
```

System (Docker image): `php:8.2-apache`, extensions `pdo_mysql mbstring exif pcntl bcmath gd ldap`, plus `sshpass`, `openssh-client`, `qrencode`, `cron`, `docker.io`.

---

**Maintainer**: Amnezia VPN Community
