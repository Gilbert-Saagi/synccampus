# PHP + Apache image for the API (used by Render, works on any Docker host).
FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql pdo_mysql \
    && rm -rf /var/lib/apt/lists/* \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && sed -i 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf

# Render provides PORT at runtime; Apache reads ${PORT} from the environment.
ENV PORT=8080
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/synccampus
COPY . .

# Bring the database schema up to date, then serve.
CMD ["sh", "-c", "php bin/migrate.php || echo 'Migration failed - check DATABASE_URL'; exec apache2-foreground"]
