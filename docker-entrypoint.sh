#!/bin/sh
set -e

# ============================================================
# Volume persistente da Railway (montar em /data no painel)
# Mantém database.sqlite e logs vivos entre deploys/restarts.
# ============================================================
mkdir -p /data/logs

# Cria o banco vazio na primeira vez (as tabelas são criadas
# automaticamente pelo próprio checkout via CREATE TABLE IF NOT EXISTS)
[ -f /data/database.sqlite ] || touch /data/database.sqlite

# Aponta os caminhos do código para o volume, SEM precisar editar PHP:
# checkout/database.sqlite  -> /data/database.sqlite
# checkout/logs             -> /data/logs
rm -rf /var/www/html/checkout/logs
ln -sfn /data/logs /var/www/html/checkout/logs
rm -f /var/www/html/checkout/database.sqlite
ln -sfn /data/database.sqlite /var/www/html/checkout/database.sqlite

# Permissões (Apache roda como www-data)
chown -R www-data:www-data /data /var/www/html
chmod -R 775 /data

# ============================================================
# Garante UM ÚNICO MPM (prefork) — a imagem base php:apache às
# vezes vem com prefork E event, o que quebra o Apache.
# ============================================================
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* 2>/dev/null || true
a2enmod mpm_prefork >/dev/null 2>&1 || true

# ============================================================
# Porta dinâmica da Railway (ela injeta $PORT em runtime)
# ============================================================
PORT="${PORT:-80}"
sed -ri "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
