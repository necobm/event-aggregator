FROM php:8.4.14-cli AS app

ARG DEBIAN_FRONTEND=noninteractive

ENV APP_ENV=dev
ENV APP_DEBUG=1

RUN apt-get -qq -o Dpkg::Use-Pty=0 update \
 && apt-get -qqy -o Dpkg::Use-Pty=0 install --no-install-recommends \
      curl \
      git \
      libicu-dev \
      libicu76 \
      libpq-dev \
      libpq5 \
      unzip \
 && docker-php-ext-install -j$(nproc) \
      bcmath \
      intl \
      opcache \
      pdo \
      pdo_pgsql \
 && echo "[opcache]"                           | tee -a $PHP_INI_DIR/conf.d/docker-php-ext-opcache.ini \
 && echo "opcache.enable_cli=1"                | tee -a $PHP_INI_DIR/conf.d/docker-php-ext-opcache.ini \
 && echo "opcache.interned_strings_buffer=16"  | tee -a $PHP_INI_DIR/conf.d/docker-php-ext-opcache.ini \
 && echo "opcache.max_accelerated_files=40000" | tee -a $PHP_INI_DIR/conf.d/docker-php-ext-opcache.ini \
 && echo "opcache.memory_consumption=256"      | tee -a $PHP_INI_DIR/conf.d/docker-php-ext-opcache.ini \
 && echo "opcache.revalidate_freq=0"           | tee -a $PHP_INI_DIR/conf.d/docker-php-ext-opcache.ini \
 && echo "opcache.validate_timestamps=0"       | tee -a $PHP_INI_DIR/conf.d/docker-php-ext-opcache.ini \
 && apt-get -qqy -o Dpkg::Use-Pty=0 autoremove --purge libpq-dev libicu-dev \
 && rm -rf /var/lib/apt/lists/*

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_MEMORY_LIMIT=2G

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN echo "max_execution_time=30" | tee -a $PHP_INI_DIR/php.ini \
 && echo "memory_limit=256M"     | tee -a $PHP_INI_DIR/php.ini \
 && echo "error_reporting=E_ALL & ~E_DEPRECATED & ~E_STRICT" | tee -a $PHP_INI_DIR/php.ini \
 && echo "log_errors=On"         | tee -a $PHP_INI_DIR/php.ini \
 && echo "short_open_tag=Off"    | tee -a $PHP_INI_DIR/php.ini

RUN echo "date.timezone=Europe/Madrid" | tee $PHP_INI_DIR/conf.d/date.ini \
 && echo "expose_php=off"              | tee $PHP_INI_DIR/conf.d/expose.ini

RUN composer config --global --no-plugins allow-plugins.symfony/flex true \
 && composer global require symfony/flex

WORKDIR /app

CMD ["tail", "-f", "/dev/null"]
