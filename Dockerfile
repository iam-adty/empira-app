# The application, served by nginx through php-fpm.
#
# php-fpm rather than the built-in server because this is closer to how the
# thing would actually be deployed, and a demo that only works under
# `php -S` hides anything that would break behind a real web server.
#
# The build context is the *workspace root*, not this directory, because
# iam-adty/empira-principle is a Composer path package that is not published
# anywhere. A path package derives its version from a git branch, and a copy
# without .git has no branch to read — hence the pinned version below.
#
# The package is copied rather than symlinked: Composer makes a path symlink
# relative, and a relative symlink out of vendor/ does not survive being
# mounted over. A copy is self-contained and one image layer.
FROM php:8.4-fpm

# pdo_pgsql for the compose database; pdo_sqlite is built in. libpq5 is
# installed in its own right because purging libpq-dev would take it away and
# leave an extension that compiles and then fails to load.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql \
    && apt-get purge -y libpq-dev \
    && apt-get install -y --no-install-recommends libpq5 \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first, so an edit to a component does not re-download PHPUnit.
COPY principle /principle
COPY app/composer.json ./composer.json
# A previous build may have left a symlink where the package directory now has
# to be written, which Docker refuses to overwrite.
RUN rm -rf /var/www/html/vendor

RUN composer config repositories.principle \
        '{"type":"path","url":"/principle","options":{"symlink":false,"versions":{"iam-adty/empira-principle":"dev-main"}}}' \
    && composer install --no-interaction --no-progress

COPY app/ .

RUN composer dump-autoload --no-interaction

EXPOSE 9000
CMD ["php-fpm"]
