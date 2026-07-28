FROM php:8.4-fpm-bookworm

ARG UID=1000
ARG GID=1000

# System packages.
# chromium is here for spatie/laravel-pdf (Browsershot) — contracts must render
# Arabic RTL correctly, which headless Chrome does and dompdf does not.
# The fonts-* packages are what stop Arabic text rendering as empty boxes.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip zip curl ca-certificates gnupg \
        chromium \
        fonts-liberation fonts-dejavu-core fonts-noto-core \
        fonts-kacst fonts-hosny-amiri \
    && rm -rf /var/lib/apt/lists/*

# install-php-extensions resolves build deps and PECL versions itself. Doing this
# by hand with pecl breaks on PHP 8.4 (redis has no REST metadata). Fetched over
# HTTPS rather than COPY --from, because this host cannot reach Docker Hub.
RUN curl -fsSL -o /usr/local/bin/install-php-extensions \
        https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions \
    && chmod +x /usr/local/bin/install-php-extensions

RUN install-php-extensions \
        pdo_pgsql pgsql \
        redis \
        gd zip intl bcmath exif pcntl opcache

# Node + puppeteer for Browsershot. Chromium comes from apt above, so tell
# puppeteer not to download its own copy (saves ~300MB and avoids arch mismatch).
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium \
    CHROME_PATH=/usr/bin/chromium
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/* \
    && npm install -g puppeteer@23 \
    && npm cache clean --force

RUN curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && php /tmp/composer-setup.php --install-dir=/usr/bin --filename=composer \
    && rm /tmp/composer-setup.php

# Run as the host user so bind-mounted files stay writable from both sides.
RUN groupmod -o -g ${GID} www-data \
    && usermod  -o -u ${UID} -g ${GID} www-data \
    && mkdir -p /var/www/html \
    && chown -R www-data:www-data /var/www/html

WORKDIR /var/www/html
USER www-data

EXPOSE 9000
CMD ["php-fpm"]
