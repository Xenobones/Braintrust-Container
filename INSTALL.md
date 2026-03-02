# BrainTrust IDE v3 — Installation & Requirements Guide

*A zero-to-BrainTrust guide for setting up your own instance.*

---

## Table of Contents

1. [Server Requirements](#1-server-requirements)
2. [System Dependencies](#2-system-dependencies)
3. [API Keys & CLI Tools](#3-api-keys--cli-tools)
4. [Project Files](#4-project-files)
5. [Secure Configuration File](#5-secure-configuration-file)
6. [Database Setup](#6-database-setup)
7. [Directory Permissions](#7-directory-permissions)
8. [Node.js Dependencies](#8-nodejs-dependencies)
9. [Systemd Services](#9-systemd-services)
10. [Apache Virtual Host](#10-apache-virtual-host)
11. [Docker Images](#11-docker-images)
12. [Create First User](#12-create-first-user)
13. [Verify Installation](#13-verify-installation)
14. [Troubleshooting](#14-troubleshooting)

---

## 1. Server Requirements

| Component | Minimum | Tested On |
|-----------|---------|-----------|
| OS | Ubuntu 22.04+ | Ubuntu 24.04 LTS |
| RAM | 4 GB | 16 GB |
| Disk | 20 GB free | SSD recommended |
| CPU | 2 cores | 8+ cores |
| Network | Internet access | LAN + internet |

BrainTrust is a self-hosted web application. It runs entirely on your own server — no cloud required beyond API calls to Anthropic and Google.

---

## 2. System Dependencies

Install everything in one shot:

```bash
sudo apt update && sudo apt install -y \
    apache2 \
    php8.3 php8.3-cli php8.3-mysql php8.3-curl php8.3-mbstring php8.3-xml php8.3-zip \
    mysql-server \
    nodejs npm \
    docker.io \
    git \
    curl \
    unzip
```

### Minimum versions required

| Package | Minimum | Install command |
|---------|---------|-----------------|
| PHP | 8.2 | `apt install php8.3` |
| Apache | 2.4 | `apt install apache2` |
| MySQL | 8.0 | `apt install mysql-server` |
| Node.js | 18 LTS | See note below |
| npm | 9+ | Comes with Node |
| Docker | 24+ | `apt install docker.io` |
| Git | Any | `apt install git` |

> **Node.js note:** Ubuntu's default `nodejs` package may be outdated. For Node 20 LTS (recommended):
> ```bash
> curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
> sudo apt install -y nodejs
> ```

### Enable and start services

```bash
sudo systemctl enable --now apache2
sudo systemctl enable --now mysql
sudo systemctl enable --now docker
sudo usermod -aG docker www-data   # allow Apache user to run Docker
```

---

## 3. API Keys & CLI Tools

BrainTrust requires API keys and CLI tools for both AI models.

### Anthropic (Claude)

1. Sign up at https://console.anthropic.com
2. Create an API key (starts with `sk-ant-api03-...`)
3. Install the Claude Code CLI:
   ```bash
   npm install -g @anthropic-ai/claude-code
   ```
4. Authenticate the CLI (run once as `www-data` or your user):
   ```bash
   sudo -u www-data claude auth
   ```
   Follow the browser OAuth flow. This saves credentials so the CLI can run non-interactively.

### Google (Gemini)

1. Sign up at https://aistudio.google.com
2. Create an API key (starts with `AIzaSy...`)
3. Install the Gemini CLI:
   ```bash
   npm install -g @google/gemini-cli
   ```
4. Authenticate:
   ```bash
   sudo -u www-data gemini auth
   ```

### GitHub (optional)

Only needed if you want the GitHub integration feature (push projects to GitHub).

1. Go to GitHub → Settings → Developer Settings → Personal Access Tokens → Tokens (classic)
2. Generate a token with `repo` scope checked
3. Note the token (starts with `ghp_...`) — you'll add it to the config file below

---

## 4. Project Files

Clone the repository into your web root:

```bash
cd /var/www/html
sudo git clone https://github.com/Xenobones/Braintrust-IDE-V3 braintrust-IDE-3
sudo chown -R www-data:www-data braintrust-IDE-3
```

### Directory structure after clone

```
/var/www/html/braintrust-IDE-3/
├── collabchat/              ← Main app directory
│   ├── braintrust.php       ← IDE interface
│   ├── braintrust_projects.php
│   ├── braintrust_api.php
│   ├── braintrust_logic.js
│   ├── braintrust_style.css
│   ├── ws_server.js         ← WebSocket server
│   ├── terminal_ws.js       ← Terminal WebSocket server
│   ├── ai_manager.js        ← AI session manager
│   ├── api/                 ← PHP API endpoints
│   ├── projects/            ← User project sandboxes (create this!)
│   └── node_modules/        ← After npm install
├── login.php
├── login.html
├── process_login.php
└── INSTALL.md
```

Create the projects sandbox directory:

```bash
sudo mkdir -p /var/www/html/braintrust-IDE-3/collabchat/projects
sudo chown www-data:www-data /var/www/html/braintrust-IDE-3/collabchat/projects
sudo chmod 755 /var/www/html/braintrust-IDE-3/collabchat/projects
```

---

## 5. Secure Configuration File

The config file lives **outside** the web root so it is never served publicly.

```bash
sudo mkdir -p /var/www/secure_config
```

Create `/var/www/secure_config/braintrust_config.php`:

```php
<?php
// ── Database ──────────────────────────────────────────────────────────────
$servername   = "localhost";
$db_username  = "braintrust_user";
$db_password  = "YOUR_DB_PASSWORD_HERE";
$db_name      = "braintrust_ide";

$conn = new mysqli($servername, $db_username, $db_password, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ── AI API Keys ────────────────────────────────────────────────────────────
define('CLAUDE_API_KEY', 'sk-ant-api03-YOUR_KEY_HERE');
define('GEMINI_API_KEY', 'AIzaSy-YOUR_KEY_HERE');

// ── OpenAI (legacy, not used by default) ──────────────────────────────────
define('OPENAI_API_KEY', '');
define('OPENAI_MODEL',   'gpt-4o');

// ── File Paths ────────────────────────────────────────────────────────────
define('PROJECTS_ROOT', '/var/www/html/braintrust-IDE-3/collabchat/projects/');

// ── GitHub Integration (optional) ─────────────────────────────────────────
define('GITHUB_TOKEN',    'ghp_YOUR_TOKEN_HERE');
define('GITHUB_USERNAME', 'your_github_username');
?>
```

Secure the file:

```bash
sudo chown www-data:www-data /var/www/secure_config/braintrust_config.php
sudo chmod 640 /var/www/secure_config/braintrust_config.php
```

---

## 6. Database Setup

### Create database and user

```bash
sudo mysql -u root
```

```sql
CREATE DATABASE braintrust_ide
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER 'braintrust_user'@'localhost' IDENTIFIED BY 'YOUR_DB_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON braintrust_ide.* TO 'braintrust_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

> Use the same password you put in `braintrust_config.php`.

### Create tables

Connect as the new user and run the schema:

```bash
mysql -u braintrust_user -p braintrust_ide
```

Paste and run the following SQL:

```sql
-- Users
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `full_name` varchar(120) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `ccmc_staff` tinyint(1) NOT NULL DEFAULT '0',
  `role` varchar(50) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'User',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Projects
CREATE TABLE `braintrust_projects` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `project_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `git_repo` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_opened` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `braintrust_projects_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions
CREATE TABLE `braintrust_sessions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `owner_id` int unsigned NOT NULL,
  `session_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT 'Untitled Session',
  `pinned_context` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_archived` tinyint(1) DEFAULT '0',
  `current_file` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `project_id` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `owner_id` (`owner_id`),
  KEY `project_id` (`project_id`),
  CONSTRAINT `braintrust_sessions_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`),
  CONSTRAINT `braintrust_sessions_ibfk_2` FOREIGN KEY (`project_id`) REFERENCES `braintrust_projects` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messages
CREATE TABLE `braintrust_messages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `session_id` int unsigned NOT NULL,
  `sender_type` enum('human','claude','gemini','chatgpt','system') COLLATE utf8mb4_unicode_ci NOT NULL,
  `sender_user_id` int unsigned DEFAULT NULL,
  `parent_message_id` int unsigned DEFAULT NULL,
  `message_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_data` mediumtext COLLATE utf8mb4_unicode_ci,
  `is_summary` tinyint(1) DEFAULT '0',
  `summary_range` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token_count` int DEFAULT '0',
  `cost` decimal(10,6) DEFAULT '0.000000',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `bookmarked` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `session_id` (`session_id`),
  KEY `sender_user_id` (`sender_user_id`),
  KEY `idx_parent_msg` (`parent_message_id`),
  CONSTRAINT `braintrust_messages_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `braintrust_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `braintrust_messages_ibfk_2` FOREIGN KEY (`sender_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Turn State
CREATE TABLE `braintrust_turn_state` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `session_id` int unsigned NOT NULL,
  `current_turn` enum('human','claude','gemini') COLLATE utf8mb4_unicode_ci DEFAULT 'human',
  `claude_enabled` tinyint(1) DEFAULT '1',
  `gemini_enabled` tinyint(1) DEFAULT '1',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_id` (`session_id`),
  CONSTRAINT `braintrust_turn_state_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `braintrust_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Whiteboard
CREATE TABLE `braintrust_whiteboard` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `session_id` int unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `diagram_type` enum('mermaid','svg','html') COLLATE utf8mb4_unicode_ci DEFAULT 'mermaid',
  `version_number` int DEFAULT '1',
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by_type` enum('human','claude','gemini') COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by_user_id` int unsigned DEFAULT NULL,
  `message_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `session_id` (`session_id`),
  CONSTRAINT `braintrust_whiteboard_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `braintrust_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Context History (v1 legacy, kept for API fallback path)
CREATE TABLE `braintrust_context_history` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `session_id` int unsigned NOT NULL,
  `context_snapshot` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `session_id` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 7. Directory Permissions

The Apache user (`www-data`) needs write access to several locations:

```bash
# Main app
sudo chown -R www-data:www-data /var/www/html/braintrust-IDE-3

# Project sandbox (AI creates files here)
sudo chown -R www-data:www-data /var/www/html/braintrust-IDE-3/collabchat/projects
sudo chmod 755 /var/www/html/braintrust-IDE-3/collabchat/projects

# Secure config (read-only for www-data)
sudo chown root:www-data /var/www/secure_config
sudo chmod 750 /var/www/secure_config
sudo chown root:www-data /var/www/secure_config/braintrust_config.php
sudo chmod 640 /var/www/secure_config/braintrust_config.php

# Terminal history (global fallback location)
sudo touch /var/www/.terminal_history
sudo chown www-data:www-data /var/www/.terminal_history
```

---

## 8. Node.js Dependencies

Install the required npm packages:

```bash
cd /var/www/html/braintrust-IDE-3/collabchat
sudo -u www-data npm install
```

This installs:
- `ws` ^8.19 — WebSocket server
- `node-pty` ^1.1 — PTY terminal (requires native compilation; needs `build-essential` installed)

If `node-pty` fails to compile, install build tools first:

```bash
sudo apt install -y build-essential python3
cd /var/www/html/braintrust-IDE-3/collabchat
sudo -u www-data npm install
```

---

## 9. Systemd Services

BrainTrust requires three background services. Create each file as shown below, then enable and start them.

### Service 1: WebSocket Server (port 8081/8082)

`/etc/systemd/system/braintrust-ws.service`:

```ini
[Unit]
Description=BrainTrust IDE WebSocket Server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html/braintrust-IDE-3/collabchat
ExecStart=/usr/bin/node ws_server.js
Restart=always
RestartSec=5
Environment=NODE_ENV=production

[Install]
WantedBy=multi-user.target
```

### Service 2: Terminal WebSocket Server (port 8083)

`/etc/systemd/system/braintrust-terminal-ws.service`:

```ini
[Unit]
Description=BrainTrust Terminal WebSocket Server (PTY)
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html/braintrust-IDE-3/collabchat
ExecStart=/usr/bin/node /var/www/html/braintrust-IDE-3/collabchat/terminal_ws.js
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

### Service 3: AI Session Manager

`/etc/systemd/system/braintrust-ai-manager.service`:

```ini
[Unit]
Description=BrainTrust v3 AI Session Manager
After=network.target braintrust-ws.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html/braintrust-IDE-3/collabchat
ExecStart=/usr/bin/node /var/www/html/braintrust-IDE-3/collabchat/ai_manager.js
Restart=always
RestartSec=3
StandardOutput=journal
StandardError=journal
SyslogIdentifier=braintrust-ai-manager

[Install]
WantedBy=multi-user.target
```

### Enable and start all three

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now braintrust-ws.service
sudo systemctl enable --now braintrust-terminal-ws.service
sudo systemctl enable --now braintrust-ai-manager.service
```

### Verify they're running

```bash
sudo systemctl status braintrust-ws.service
sudo systemctl status braintrust-terminal-ws.service
sudo systemctl status braintrust-ai-manager.service
```

All three should show `active (running)`. If any fail, check logs:

```bash
sudo journalctl -u braintrust-ai-manager.service -n 50
```

---

## 10. Apache Virtual Host

Enable required Apache modules:

```bash
sudo a2enmod rewrite proxy proxy_wstunnel headers
sudo systemctl restart apache2
```

Create a virtual host config at `/etc/apache2/sites-available/braintrust.conf`:

```apache
<VirtualHost *:80>
    ServerName braintrust.local
    DocumentRoot /var/www/html/braintrust-IDE-3

    <Directory /var/www/html/braintrust-IDE-3>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # WebSocket proxy — main WS server
    ProxyPreserveHost On
    RewriteEngine On
    RewriteCond %{HTTP:Upgrade} websocket [NC]
    RewriteCond %{HTTP:Connection} upgrade [NC]
    RewriteRule ^/ws$ ws://localhost:8081/ [P,L]

    # WebSocket proxy — terminal PTY
    RewriteCond %{HTTP:Upgrade} websocket [NC]
    RewriteCond %{HTTP:Connection} upgrade [NC]
    RewriteRule ^/terminal-ws$ ws://localhost:8083/ [P,L]

    ErrorLog ${APACHE_LOG_DIR}/braintrust_error.log
    CustomLog ${APACHE_LOG_DIR}/braintrust_access.log combined
</VirtualHost>
```

Enable the site:

```bash
sudo a2ensite braintrust.conf
sudo systemctl reload apache2
```

> **Accessing the IDE:** Add `192.168.x.x  braintrust.local` to your `/etc/hosts` (or the client machines' hosts file), then browse to `http://braintrust.local`. Or just use the server IP directly: `http://192.168.x.x/braintrust-IDE-3/`.

---

## 11. Docker Images

BrainTrust uses Docker to sandbox code execution. Pull the images in advance so they're ready:

```bash
sudo docker pull python:3.11-slim
sudo docker pull php:8.2-cli
```

Verify Docker works as `www-data`:

```bash
sudo -u www-data docker run --rm python:3.11-slim python3 -c "print('Docker OK')"
```

Should output: `Docker OK`

If you get a permission error, ensure www-data is in the docker group and restart:

```bash
sudo usermod -aG docker www-data
sudo systemctl restart apache2
```

---

## 12. Create First User

BrainTrust has no self-registration page — accounts are created by an admin via the management UI, or manually in the database.

### Option A: Via the add_user.php script

```
http://your-server/braintrust-IDE-3/add_user.php
```

Fill in username, full name, email, and password.

### Option B: Manually in MySQL

```bash
mysql -u braintrust_user -p braintrust_ide
```

```sql
INSERT INTO users (username, full_name, email, password_hash, role)
VALUES (
    'ajax',
    'Brother Ajax',
    'ajax@laravelia.org',
    '$2y$10$HASH_GENERATED_BELOW',
    'Admin'
);
```

Generate the bcrypt hash first:

```bash
php -r "echo password_hash('your_password_here', PASSWORD_BCRYPT);"
```

Copy the output hash into the SQL above.

---

## 13. Verify Installation

Work through this checklist after completing setup:

- [ ] Browse to the server IP — login page loads
- [ ] Log in with your new user account
- [ ] Projects page appears — create a test project
- [ ] IDE loads — all three panels visible (Explorer, Chat, Editor)
- [ ] Chat area shows "Human" turn indicator
- [ ] Type a message and send — Claude responds (green dot = CLI mode)
- [ ] Gemini responds after Claude (green dot = CLI mode)
- [ ] Click the terminal tab — bash prompt appears
- [ ] Run `ls` in the terminal — project files listed
- [ ] Create a Python file and run it — Docker executes it
- [ ] Open the Canvas (🖼️) — drawing tools work
- [ ] Open the Whiteboard (🔲) — Mermaid diagram renders

### Check service ports

```bash
ss -tlnp | grep -E '8081|8082|8083'
```

Should show three listening sockets for Node.js.

---

## 14. Troubleshooting

### "Not authenticated" on every API call
The session cookie isn't carrying. Make sure `session_start()` in PHP can write to the session directory. Check: `sudo chown www-data:www-data /var/lib/php/sessions`

### Terminal shows "WebSocket connection failed"
The terminal WS service isn't running or Apache isn't proxying it. Check:
```bash
sudo systemctl status braintrust-terminal-ws.service
curl http://localhost:8083/   # should return something (not refused)
```

### Claude/Gemini CLI returns errors
The `www-data` user's CLI credentials may not be set up. Test:
```bash
sudo -u www-data claude --version
sudo -u www-data gemini --version
```
If either fails with auth errors, re-run the auth step from Section 3.

### Docker: "permission denied"
```bash
sudo usermod -aG docker www-data
sudo systemctl restart apache2 braintrust-terminal-ws.service
```

### Files not appearing in Explorer
The explorer auto-refreshes when a new message arrives. If files are created outside the chat (e.g., manual upload), click the **🔄 Refresh** button in the Explorer panel.

### "Connection failed" MySQL error
Verify the credentials in `braintrust_config.php` match what was set in MySQL. Test:
```bash
mysql -u braintrust_user -p'YOUR_PASSWORD' braintrust_ide -e "SELECT 1"
```

### node_modules missing / npm errors
```bash
cd /var/www/html/braintrust-IDE-3/collabchat
sudo -u www-data npm install
sudo systemctl restart braintrust-ws.service braintrust-terminal-ws.service braintrust-ai-manager.service
```

---

## Quick Reference: Service Commands

```bash
# Restart all BrainTrust services
sudo systemctl restart braintrust-ws braintrust-terminal-ws braintrust-ai-manager

# View live logs
sudo journalctl -u braintrust-ws.service -f
sudo journalctl -u braintrust-terminal-ws.service -f
sudo journalctl -u braintrust-ai-manager.service -f

# Check all at once
sudo systemctl status braintrust-ws braintrust-terminal-ws braintrust-ai-manager
```

---

*BrainTrust IDE v3 — Created by Shannon Hensley*
*For support: https://github.com/Xenobones/Braintrust-IDE-V3/issues*
