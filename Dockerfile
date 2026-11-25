# Refresh the reviewed OCI digest and dated Debian snapshots together when patching.
FROM docker.io/library/php:8.6.0-apache-trixie@sha256:7394e376d8dec30bfa474614d37bfce171e4fdf57a1398df63afca152d396b14 AS platform

RUN set -eux; \
    rm -f /etc/apt/sources.list /etc/apt/sources.list.d/debian.sources; \
    printf '%s\n' \
      'deb [check-valid-until=no] https://snapshot.debian.org/archive/debian/20261124T150911Z/ trixie main' \
      'deb [check-valid-until=no] https://snapshot.debian.org/archive/debian-security/20261124T184346Z/ trixie-security main' \
      > /etc/apt/sources.list; \
    apt-get -o Acquire::Retries=3 update; \
    apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev libicu-dev libzip-dev libpq-dev libonig-dev; \
    if ! php -r 'exit(extension_loaded("mbstring") ? 0 : 1);'; then docker-php-ext-install -j"$(nproc)" mbstring; fi; \
    docker-php-ext-install -j"$(nproc)" intl zip pdo_mysql pdo_pgsql pcntl; \
    docker-php-source extract; \
    ( cd /usr/src/php/ext/gd; \
      phpize; \
      ./configure --with-php-config=/usr/local/bin/php-config --with-freetype --with-jpeg --with-webp; \
      make -j"$(nproc)"; \
      make install ); \
    docker-php-ext-enable gd; \
    docker-php-source delete; \
    rm "$PHP_INI_DIR/conf.d/docker-php-ext-pcntl.ini"; \
    php -d extension=pcntl -r 'exit(extension_loaded("pcntl") ? 0 : 1);'; \
    rm -rf /var/lib/apt/lists/*; \
    ln -s "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    printf '%s\n' 'variables_order=EGPCS' 'expose_php=Off' 'display_errors=Off' 'log_errors=On' 'cgi.fix_pathinfo=0' 'upload_max_filesize=6M' 'post_max_size=8M' > "$PHP_INI_DIR/conf.d/90-blessing.ini"; \
    php -r 'if (PHP_VERSION !== "8.6.0" || !defined("PASSWORD_ARGON2ID") || !function_exists("imagepng") || !function_exists("imagewebp") || extension_loaded("imagick")) exit(1); foreach (["ctype", "curl", "dom", "fileinfo", "filter", "gd", "hash", "iconv", "intl", "json", "libxml", "mbstring", "openssl", "pcre", "pdo", "pdo_sqlite", "pdo_mysql", "pdo_pgsql", "session", "sodium", "tokenizer", "xml", "xmlwriter", "zip"] as $e) if (!extension_loaded($e)) exit(1);'

FROM platform AS toolchain
ARG TARGETARCH
RUN set -eux; \
    case "$TARGETARCH" in \
      amd64) node_arch=x64; node_hash=fd8e59d5a511510f6a298afb548f18c7d2b1be404d8b4a27d94fbe49f56cb2d6 ;; \
      arm64) node_arch=arm64; node_hash=6ad1325edbdb5649c379b75a237147a666c95d4f9ae8d340fef2d1575d289ad2 ;; \
      *) echo 'Supported build architectures: amd64 and arm64.' >&2; exit 1 ;; \
    esac; \
    curl --proto '=https' --tlsv1.2 --fail --silent --show-error --retry 3 --location "https://nodejs.org/dist/v24.21.0/node-v24.21.0-linux-${node_arch}.tar.xz" --output /tmp/node.tar.xz; \
    echo "$node_hash  /tmp/node.tar.xz" | sha256sum -c -; \
    tar -xJf /tmp/node.tar.xz -C /usr/local --strip-components=1; \
    rm /tmp/node.tar.xz; \
    curl --proto '=https' --tlsv1.2 --fail --silent --show-error --retry 3 --location 'https://getcomposer.org/download/2.11.3/composer.phar' --output /usr/local/bin/composer; \
    echo '04f8b003da4e3a2459056591fbfebd02b4dc3e5adeb158616b90cf55a65113ec  /usr/local/bin/composer' | sha256sum -c -; \
    chmod 0755 /usr/local/bin/composer; \
    test "$(node --version)" = v24.21.0; \
    test "$(npm --version)" = 11.19.0; \
    COMPOSER_ALLOW_SUPERUSER=1 composer --version | grep -F 'Composer version 2.11.3 '
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /workspace

FROM toolchain AS build
WORKDIR /src
COPY package.json package-lock.json postcss.config.cjs webpack.config.ts tsconfig.json tsconfig.build.json ./
COPY tools/HtmlWebpackEnhancementPlugin.ts ./tools/
COPY resources ./resources
RUN npm ci --registry=https://registry.npmjs.org --ignore-scripts --no-audit --no-fund && npm run build
COPY composer.json composer.lock artisan LICENSE README.md README-zh.md .env.example ./
COPY app ./app
COPY bootstrap/app.php ./bootstrap/app.php
COPY config ./config
COPY database/migrations ./database/migrations
COPY database/factories ./database/factories
COPY routes ./routes
COPY public/index.php public/.htaccess ./public/
RUN set -eux; \
    mkdir -p bootstrap/cache; \
    composer validate --strict --no-check-publish; \
    composer install --prefer-dist --no-dev --no-interaction --no-progress --no-plugins --no-scripts --optimize-autoloader; \
    composer check-platform-reqs --no-dev; \
    php artisan package:discover --no-interaction --no-ansi

FROM platform AS runtime
WORKDIR /opt/blessing
COPY --from=build /src/app ./app
COPY --from=build /src/bootstrap ./bootstrap
COPY --from=build /src/config ./config
COPY --from=build /src/database/migrations ./database/migrations
COPY --from=build /src/public ./public
COPY --from=build /src/resources/lang ./resources/lang
COPY --from=build /src/resources/views ./resources/views
COPY --from=build /src/resources/misc ./resources/misc
COPY --from=build /src/routes ./routes
COPY --from=build /src/vendor ./vendor
COPY --from=build /src/artisan /src/composer.json /src/composer.lock /src/LICENSE ./
COPY deploy/apache/blessing.conf /etc/apache2/sites-available/blessing.conf
COPY deploy/apache/envvars /etc/apache2/blessing-envvars
COPY --chmod=0555 deploy/docker/entrypoint.sh /usr/local/bin/blessing-entrypoint
ENV APP_ENV=production APP_DEBUG=false LARAVEL_STORAGE_PATH=/var/lib/blessing APACHE_ENVVARS=/etc/apache2/blessing-envvars
RUN set -eux; \
    printf '%s\n' 'Listen 8080' > /etc/apache2/ports.conf; \
    a2enmod rewrite headers; \
    a2dissite 000-default; \
    a2ensite blessing; \
    a2disconf other-vhosts-access-log; \
    printf '%s\n' 'ServerName localhost' 'ServerTokens Prod' 'ServerSignature Off' 'TraceEnable Off' > /etc/apache2/conf-available/blessing-hardening.conf; \
    a2enconf blessing-hardening; \
    install -d -m 0700 -o www-data -g www-data /var/lib/blessing /tmp/blessing-apache; \
    su -s /bin/sh www-data -c '. /etc/apache2/blessing-envvars; apache2ctl configtest'
USER www-data
EXPOSE 8080
ENTRYPOINT ["blessing-entrypoint"]
CMD ["apache2-foreground"]
