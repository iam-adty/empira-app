# The application, served by FrankenPHP in worker mode.
#
# Worker mode boots the application once and keeps it in memory, so the
# per-request bootstrap — container, route table, migrations, the seeder's
# bcrypt — is paid once per process instead of once per request.
# public/index.php is both the front controller and the worker script, and
# the framework is written to be safe about serving many requests through one
# Application.
#
# The build context is the *workspace root*, not this directory, because
# iam-adty/empira-principle is a Composer path package that is not published
# anywhere. A path package derives its version from a git branch, and a copy
# without .git has no branch to read — hence the pinned version below.
#
# The package is copied rather than symlinked: Composer makes a path symlink
# relative, and a relative symlink out of vendor/ does not survive being
# mounted over. A copy is self-contained and one image layer.
FROM dunglas/frankenphp:php8.4

# pdo_pgsql for the compose database; pdo_sqlite is built in. The image ships
# install-php-extensions precisely so this stays one line. zip is here for
# Composer: without it, and without the unzip binary, it cannot download a
# dist package at all and `composer install` dies with a usage dump.
RUN install-php-extensions pdo_pgsql zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# WORKDIR is this package's place *inside the workspace*, not the root of it,
# because the suite shares a scratch folder with the framework: `/workspace/tmp`
# is `../tmp` next to this directory. Keeping the layout identical to the host
# is what lets one relative path mean one thing in both places.
WORKDIR /workspace/app

# Dependencies first, so an edit to a component does not re-download PHPUnit.
#
# The path package is copied to `/principle`, deliberately outside `/workspace`:
# at runtime the workspace is bind-mounted over `/workspace`, and a build-time
# path living underneath that mount is a trap for whoever reads this next.
COPY principle /principle
COPY app/composer.json ./composer.json
# A previous build may have left a symlink where the package directory now has
# to be written, which Docker refuses to overwrite.
RUN rm -rf /workspace/app/vendor

RUN composer config repositories.principle \
        '{"type":"path","url":"/principle","options":{"symlink":false,"versions":{"iam-adty/empira-principle":"dev-main"}}}' \
    && composer install --no-interaction --no-progress

COPY app/ .

RUN composer dump-autoload --no-interaction \
    && chmod +x /workspace/app/docker/entrypoint.sh

EXPOSE 8080

CMD ["/workspace/app/docker/entrypoint.sh"]
