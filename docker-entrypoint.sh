#!/bin/sh
set -eu

port="${PORT:-8080}"
case "$port" in
  ''|*[!0-9]*) echo "PORT must be numeric" >&2; exit 1 ;;
esac
if [ "$port" -lt 1024 ] || [ "$port" -gt 65535 ]; then
  echo "PORT must be between 1024 and 65535" >&2
  exit 1
fi
sed -ri "s/^Listen [0-9]+$/Listen ${port}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${port}>/" /etc/apache2/sites-available/000-default.conf
database_path="${DATABASE_PATH:-/var/www/html/shop_database.sqlite}"
case "$database_path" in
  sqlite:*) database_path="${database_path#sqlite:}" ;;
esac
case "$database_path" in
  /*)
    database_dir=$(dirname "$database_path")
    mkdir -p "$database_dir" 2>/dev/null || true
    chown www-data:www-data "$database_dir" 2>/dev/null || true
    if [ -f "$database_path" ]; then chown www-data:www-data "$database_path" 2>/dev/null || true; fi
    ;;
esac
if [ -d /data ]; then
  chown www-data:www-data /data 2>/dev/null || true
fi
exec apache2-foreground
