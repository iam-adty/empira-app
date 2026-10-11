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
#
# Alpine, and in two stages. Both are about size, and the image is
# overwhelmingly base: of the previous 870MB, about 26MB was ours and the rest
# was Debian trixie plus the ~366MB of build tooling the PHP base image carries
# so that `install-php-extensions` can work. Alpine is a fraction of that.
#
# The split exists because **`rm` does not shrink an image**. Layers are
# append-only, so deleting Composer in a later layer leaves 3.66MB behind in the
# one before it and only adds a whiteout. The same is true of purging
# $PHPIZE_DEPS after building an extension: it frees the running filesystem and
# the image stays the size it was. Only a second stage actually removes a layer,
# which is what takes Composer and the `zip` extension — Composer's problem,
# not the runtime's — out of the shipped image.

# ── deps: everything Composer needs, none of it shipped ──
FROM dunglas/frankenphp:php8.4-alpine AS deps

# pdo_pgsql for the compose database. zip is here only so Composer can extract
# a dist package at all; without it, and without the unzip binary, it dies with
# a usage dump instead of an error.
RUN install-php-extensions pdo_pgsql zip

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# `/principle`, deliberately outside `/workspace`: at runtime the workspace is
# bind-mounted over `/workspace`, and a build-time path underneath that mount is
# a trap for whoever reads this next.
COPY principle /principle
COPY app/composer.json ./composer.json

RUN composer config repositories.principle \
        '{"type":"path","url":"/principle","options":{"symlink":false,"versions":{"iam-adty/empira-principle":"dev-main"}}}' \
    && composer install --no-interaction --no-progress --no-dev

COPY app/ .

RUN composer dump-autoload --no-interaction --no-dev

# ── runtime ──
FROM dunglas/frankenphp:php8.4-alpine

# Only what the application actually loads at request time. PHPUnit and
# franzl/studio are development tools, and the suite runs on the host — verified
# by the Dockerfile, entrypoint and compose file containing no reference to them.
RUN install-php-extensions pdo_pgsql

# WORKDIR is this package's place *inside the workspace*, not the root of it,
# because the suite shares a scratch folder with the framework: `/workspace/tmp`
# is `../tmp` next to this directory. Keeping the layout identical to the host
# is what lets one relative path mean one thing in both places.
WORKDIR /workspace/app

COPY --from=deps /app /workspace/app

RUN chmod +x /workspace/app/docker/entrypoint.sh

EXPOSE 8080

CMD ["/workspace/app/docker/entrypoint.sh"]