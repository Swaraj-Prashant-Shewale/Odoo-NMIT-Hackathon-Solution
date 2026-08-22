# ---------------------------------------------------------------------------
# Dayflow HRMS - shared PHP runtime
#
# One image serves every backend service, the API gateway and the web client.
# They differ only in which source directory is mounted and which environment
# variables they receive, which keeps builds fast and guarantees that all
# eleven containers run an identical, patched runtime.
# ---------------------------------------------------------------------------
FROM php:8.3-apache

LABEL org.opencontainers.image.title="Dayflow HRMS Runtime" \
      org.opencontainers.image.description="PHP 8.3 runtime shared by all Dayflow services"

# --- System packages and PHP extensions ------------------------------------
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpq-dev \
        libicu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        curl \
        ca-certificates; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        gd \
        intl \
        zip \
        opcache \
        bcmath; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

# --- Apache ----------------------------------------------------------------
# rewrite  : front-controller routing
# headers  : security response headers
# deflate  : compress text responses
RUN a2enmod rewrite headers deflate expires && \
    a2dismod -f autoindex status 2>/dev/null || true

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    DAYFLOW_SHARED=/var/www/shared

COPY docker/php/apache-site.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/security.conf    /etc/apache2/conf-available/dayflow-security.conf
COPY docker/php/php.ini          /usr/local/etc/php/conf.d/dayflow.ini
COPY docker/php/entrypoint.sh    /usr/local/bin/dayflow-entrypoint

RUN a2enconf dayflow-security && chmod +x /usr/local/bin/dayflow-entrypoint

# --- Least privilege --------------------------------------------------------
# Apache drops to www-data after binding; the writable paths are created and
# handed to that account so nothing in the container runs as root beyond the
# initial bind.
RUN mkdir -p /var/log/dayflow /var/www/storage/uploads /var/www/storage/mail /var/www/storage/cache && \
    chown -R www-data:www-data /var/log/dayflow /var/www/storage

WORKDIR /var/www/html

HEALTHCHECK --interval=15s --timeout=5s --start-period=40s --retries=5 \
    CMD curl --fail --silent http://localhost/health > /dev/null || exit 1

EXPOSE 80

ENTRYPOINT ["dayflow-entrypoint"]
CMD ["apache2-foreground"]
