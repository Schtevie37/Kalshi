#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if ! command -v php >/dev/null || ! command -v mysql >/dev/null; then
  echo "Install PHP (cli, curl, mbstring, mysql) and MariaDB or MySQL first." >&2
  exit 1
fi

php -m | grep -qx curl || { echo "Enable the PHP curl extension." >&2; exit 1; }
php -m | grep -qx sodium || { echo "Enable the PHP sodium extension." >&2; exit 1; }
php -m | grep -qx pdo_mysql || { echo "Enable the PHP pdo_mysql extension." >&2; exit 1; }

if [[ ! -f config/config.php ]]; then
  PASS="$(php -r 'echo bin2hex(random_bytes(12));')"
  sudo mysql <<SQL
CREATE DATABASE IF NOT EXISTS kalshi_paper CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'kalshi'@'localhost' IDENTIFIED BY '${PASS}';
ALTER USER 'kalshi'@'localhost' IDENTIFIED BY '${PASS}';
GRANT ALL PRIVILEGES ON kalshi_paper.* TO 'kalshi'@'localhost';
FLUSH PRIVILEGES;
SQL
  PASS="$PASS" php -r '
    $pass = getenv("PASS");
    $config = [
      "db_host" => "127.0.0.1",
      "db_port" => 3306,
      "db_name" => "kalshi_paper",
      "db_user" => "kalshi",
      "db_pass" => $pass,
      "app_key" => base64_encode(random_bytes(32)),
      "demo_base_url" => "https://external-api.demo.kalshi.co/trade-api/v2",
    ];
    $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
    file_put_contents("config/config.php", $php);
    chmod("config/config.php", 0600);
  '
  echo "Wrote config/config.php"
fi

DB_PASS="$(php -r 'echo (require "config/config.php")["db_pass"];')"
mysql --user=kalshi --password="$DB_PASS" kalshi_paper < sql/schema.sql
echo "MySQL schema is ready."
echo "Start the desk with: php -S localhost:8080 -t public public/router.php"
