#!/usr/bin/env bash
# =============================================================================
#  Проверка данных в БД панели Amnezia VPN
#  Запускать из корня проекта (там, где docker-compose.yml и .env):
#      ./check-db.sh
# =============================================================================

set -euo pipefail
cd "$(dirname "$0")"

[ -f .env ] || { echo "✗ Нет .env — запустите из корня проекта"; exit 1; }
set -a
# shellcheck disable=SC1091
source ./.env
set +a

DB_ROOT="${DB_ROOT_PASSWORD:-rootpassword}"
DB_NAME="${DB_DATABASE:-amnezia_panel}"

echo "=== Сводка (количество записей) ==="
docker compose exec -T db mysql --default-character-set=utf8mb4 -uroot -p"$DB_ROOT" "$DB_NAME" -e "
SELECT
  (SELECT COUNT(*) FROM users)        AS users,
  (SELECT COUNT(*) FROM vpn_servers)  AS servers,
  (SELECT COUNT(*) FROM vpn_clients)  AS clients;
" 2>/dev/null

echo ""
echo "=== Пользователи (id, email, role, status) ==="
docker compose exec -T db mysql --default-character-set=utf8mb4 -uroot -p"$DB_ROOT" "$DB_NAME" \
  -e "SELECT id, email, role, status FROM users ORDER BY id;" 2>/dev/null

echo ""
echo "=== Серверы (id, user_id, name, host, port, status) ==="
docker compose exec -T db mysql --default-character-set=utf8mb4 -uroot -p"$DB_ROOT" "$DB_NAME" \
  -e "SELECT id, user_id, name, host, port, status FROM vpn_servers ORDER BY id;" 2>/dev/null

echo ""
echo "=== Клиенты (id, server_id, user_id, name, status) ==="
docker compose exec -T db mysql --default-character-set=utf8mb4 -uroot -p"$DB_ROOT" "$DB_NAME" \
  -e "SELECT id, server_id, user_id, name, status FROM vpn_clients ORDER BY id LIMIT 100;" 2>/dev/null
