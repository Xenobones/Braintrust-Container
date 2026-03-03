#!/bin/bash
set -e

echo "=== BrainTrust IDE v3 — Container Startup ==="

# ── Validate required env vars ────────────────────────────────────────────────
REQUIRED_VARS=("DB_PASS" "CLAUDE_API_KEY" "GEMINI_API_KEY")
MISSING=0
for var in "${REQUIRED_VARS[@]}"; do
    if [ -z "${!var}" ]; then
        echo "ERROR: Required environment variable $var is not set."
        MISSING=1
    fi
done
if [ "$MISSING" -eq 1 ]; then
    echo "Set all required variables in your .env file. See .env.example."
    exit 1
fi

# ── Write braintrust_config.php with actual values baked in ──────────────────
# NOTE: unquoted heredoc (PHPEOF not 'PHPEOF') so bash substitutes real values.
# Apache/PHP running as www-data does not inherit container env vars, so we
# write the actual credentials directly into the file at startup.
mkdir -p /var/www/secure_config
cat > /var/www/secure_config/braintrust_config.php << PHPEOF
<?php
\$servername  = '${DB_HOST:-mysql}';
\$db_username = '${DB_USER:-braintrust_user}';
\$db_password = '${DB_PASS}';
\$db_name     = '${DB_NAME:-braintrust_ide}';

\$conn = new mysqli(\$servername, \$db_username, \$db_password, \$db_name);
if (\$conn->connect_error) {
    die("Connection failed: " . \$conn->connect_error);
}

define('CLAUDE_API_KEY',  '${CLAUDE_API_KEY}');
define('GEMINI_API_KEY',  '${GEMINI_API_KEY}');
define('OPENAI_API_KEY',  '${OPENAI_API_KEY:-}');
define('OPENAI_MODEL',    '${OPENAI_MODEL:-gpt-4o}');

define('PROJECTS_ROOT',     '/var/www/html/collabchat/projects/');
define('BT3_PROJECTS_ROOT', '/var/www/html/collabchat/projects/');

define('GITHUB_TOKEN',    '${GITHUB_TOKEN:-}');
define('GITHUB_USERNAME', '${GITHUB_USERNAME:-}');
?>
PHPEOF

# ── Write dev_db_config.php with actual values baked in ──────────────────────
cat > /var/www/secure_config/dev_db_config.php << PHPEOF
<?php
define('DEV_DB_HOST', '${DEV_DB_HOST:-mysql}');
define('DEV_DB_USER', '${DEV_DB_USER:-braintrust_user}');
define('DEV_DB_PASS', '${DB_PASS}');
define('DEV_DB_NAME', '${DEV_DB_NAME:-dev_projects}');
?>
PHPEOF

echo "[config] Credentials written to /var/www/secure_config/"

# ── Configure Claude CLI with API key ─────────────────────────────────────────
export ANTHROPIC_API_KEY="${CLAUDE_API_KEY}"

# ── Wait for MySQL ─────────────────────────────────────────────────────────────
echo "[db] Waiting for MySQL at ${DB_HOST:-mysql}..."
MAX_TRIES=30
COUNT=0
until php -r "
    \$c = new mysqli('${DB_HOST:-mysql}', '${DB_USER:-braintrust_user}', '${DB_PASS}', '${DB_NAME:-braintrust_ide}');
    exit(\$c->connect_error ? 1 : 0);
" 2>/dev/null; do
    COUNT=$((COUNT + 1))
    if [ "$COUNT" -ge "$MAX_TRIES" ]; then
        echo "[db] ERROR: MySQL not ready after ${MAX_TRIES} attempts. Check DB_* env vars."
        exit 1
    fi
    echo "[db] Attempt $COUNT/$MAX_TRIES — retrying in 2s..."
    sleep 2
done
echo "[db] MySQL connection OK."

# ── Ensure projects directory is writable ─────────────────────────────────────
mkdir -p /var/www/html/collabchat/projects
chown -R www-data:www-data /var/www/html/collabchat/projects
mkdir -p /var/log/braintrust

echo "[startup] Starting services via supervisord..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/braintrust.conf
