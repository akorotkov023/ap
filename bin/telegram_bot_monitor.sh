#!/bin/bash

# Monitor and (re)start the Telegram bot if it is not running.
# Mirrors bin/monitor_metrics.sh — run by cron every 3 minutes so the bot is
# restarted automatically if it dies. The bot itself is a long-running
# long-polling process (php bin/telegram_bot.php).
#
# Runs as www-data (same user as the web UI) so the panel can also restart the
# bot from the /servers page. Everything lives under /var/www/html/logs,
# which is owned by www-data.
#
# Usage:
#   telegram_bot_monitor.sh            # start if not running
#   telegram_bot_monitor.sh restart    # force kill + start
#   telegram_bot_monitor.sh stop       # kill (used by the panel before restart)

SCRIPT_PATH="/var/www/html/bin/telegram_bot.php"
LOG_FILE="/var/www/html/logs/telegram_bot.log"
PID_FILE="/var/www/html/logs/telegram_bot.pid"
# Separate lock file from the bot so the started bot never inherits this lock.
MONITOR_LOCK_FILE="/var/www/html/logs/telegram_bot_monitor.lock"

log_message() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG_FILE"
}

# Prevent multiple monitor/restart instances.
exec 200>"$MONITOR_LOCK_FILE"
if ! flock -n 200; then
    log_message "Another monitor instance is running, exiting"
    exit 0
fi

is_running() {
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE")
        if ps -p "$PID" > /dev/null 2>&1; then
            if ps -p "$PID" -o args= | grep -q "telegram_bot.php"; then
                return 0
            fi
        fi
    fi
    # Catch orphan processes not tracked by the PID file.
    if pgrep -f "telegram_bot.php" > /dev/null 2>&1; then
        pgrep -f "telegram_bot.php" | head -1 > "$PID_FILE"
        return 0
    fi
    return 1
}

start_bot() {
    log_message "Starting Telegram bot..."
    # Detach (setsid) and drop the monitor's lock fd so the bot holds no flock.
    setsid /usr/local/bin/php "$SCRIPT_PATH" >> "$LOG_FILE" 2>&1 200>&- </dev/null &
    echo $! > "$PID_FILE"
    log_message "Telegram bot started with PID: $(cat $PID_FILE)"
}

stop_bot() {
    # SIGKILL so it stops immediately. The bot keeps no state that needs a
    # graceful shutdown (its in-memory per-chat state and getUpdates offset
    # reset on restart anyway), and a lingering process would cause a
    # Telegram 409 conflict with the newly started one.
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE")
        if ps -p "$PID" > /dev/null 2>&1; then
            kill -9 "$PID" 2>/dev/null || true
        fi
        rm -f "$PID_FILE"
    fi
    # Safety net for any orphaned bot process not tracked by the PID file.
    pkill -9 -f "telegram_bot.php" 2>/dev/null || true
}

case "${1:-}" in
    restart)
        log_message "Restart requested"
        stop_bot
        sleep 1
        start_bot
        ;;
    stop)
        stop_bot
        ;;
    *)
        if is_running; then
            # Already running — nothing to do (silent to keep cron log clean).
            :
        else
            log_message "Telegram bot is not running - starting it"
            start_bot
        fi
        ;;
esac
