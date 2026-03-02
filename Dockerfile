# BrainTrust IDE v3 — Container Image
# Base: php:8.2-apache (Debian Bookworm)
FROM php:8.2-apache

# ── System packages ──────────────────────────────────────────────────────────
RUN apt-get update && apt-get install -y \
    build-essential \
    python3 \
    python3-pip \
    git \
    curl \
    unzip \
    supervisor \
    && rm -rf /var/lib/apt/lists/*

# ── Node.js 20 LTS ───────────────────────────────────────────────────────────
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# ── PHP extensions ───────────────────────────────────────────────────────────
RUN docker-php-ext-install mysqli

# curl, mbstring, zip come pre-installed in php:8.2-apache — verify:
RUN php -m | grep -E 'curl|mbstring|zip' || true

# ── Apache modules ───────────────────────────────────────────────────────────
RUN a2enmod proxy proxy_http proxy_wstunnel rewrite headers

# ── Claude CLI ───────────────────────────────────────────────────────────────
# Installs to /usr/local/lib/node_modules/@anthropic-ai/claude-code/
RUN npm install -g @anthropic-ai/claude-code

# ── Gemini CLI ───────────────────────────────────────────────────────────────
RUN npm install -g @google/gemini-cli

# ── App files ────────────────────────────────────────────────────────────────
WORKDIR /var/www/html
COPY . .

# Remove any local dev artifacts
RUN rm -rf collabchat/projects/* \
           collabchat/logs/* \
           .git .claude

# ── Node.js deps (ws + node-pty — node-pty needs build-essential) ────────────
WORKDIR /var/www/html/collabchat
RUN npm install --omit=dev

# ── Directory structure ───────────────────────────────────────────────────────
RUN mkdir -p \
    /var/www/html/collabchat/projects \
    /var/www/html/collabchat/logs \
    /var/log/supervisor \
    /var/log/braintrust \
    /var/www/secure_config

# ── Configs ───────────────────────────────────────────────────────────────────
COPY docker/apache.conf        /etc/apache2/sites-available/000-default.conf
COPY docker/supervisord.conf   /etc/supervisor/conf.d/braintrust.conf
COPY docker/entrypoint.sh      /entrypoint.sh
RUN chmod +x /entrypoint.sh

# ── Permissions ───────────────────────────────────────────────────────────────
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/collabchat/projects

# Ports:
#   80   — Apache (HTTP, also proxies /ws and /terminal-ws)
#   8081 — WebSocket client (ws_server.js)
#   8083 — Terminal WebSocket (terminal_ws.js)
# Note: 8082 is internal PHP→Node notify only, not exposed
EXPOSE 80 8081 8083

ENTRYPOINT ["/entrypoint.sh"]
