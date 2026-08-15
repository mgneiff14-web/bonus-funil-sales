FROM php:8.2-apache

# Extensões necessárias:
# - pdo_sqlite: banco do checkout (database.sqlite)
# - (curl e mbstring já vêm habilitados na imagem oficial do PHP)
RUN apt-get update \
 && apt-get install -y --no-install-recommends libsqlite3-dev \
 && docker-php-ext-install pdo_sqlite \
 && a2enmod mpm_prefork rewrite headers \
 && rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* \
 && sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
 && printf '\n# Atrás de proxy (Railway): usar o Host do cliente e HTTPS, sem vazar a porta interna\nUseCanonicalName Off\nUseCanonicalPhysicalPort Off\nSetEnvIf X-Forwarded-Proto "https" HTTPS=on\n' >> /etc/apache2/apache2.conf \
 && rm -rf /var/lib/apt/lists/*

# Copia o projeto inteiro para a raiz do Apache
COPY . /var/www/html/

# Script de inicialização (porta dinâmica da Railway + volume persistente)
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh \
 && chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
