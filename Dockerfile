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

# `/principle` and `/auth`, deliberately outside `/workspace`: at runtime the
# workspace is bind-mounted over `/workspace`, and a build-time path underneath
# that mount is a trap for whoever reads this next.
#
# Both are Composer path packages that are not published, so each needs naming
# here as well as in `composer.json`. An application's dependency on a module is
# exactly as real as its dependency on the framework, and the module is what
# brings the `users` table — so an image that installed the framework but not
# the module would boot into an application with no way to sign anybody in.
COPY principle /principle
COPY auth /auth

# The lock is copied as well as the manifest, and that is the whole point: an
# application that commits its lock is reproducible only if the build reads it.
# `composer install` uses it when present and resolves afresh when it is not, so
# a build that copied only `composer.json` produced an image pinned to whatever
# the libraries' `main` happened to be that afternoon — a different commit from
# the one the host had installed, and nothing in the output said so.
COPY app/composer.json ./composer.json
COPY app/composer.lock ./composer.lock

# Say what is wrong rather than failing three COPYs later with no explanation.
# The build context is the workspace root, so a missing sibling is the one
# failure here that a cloner of `app` alone cannot fix by retrying.
RUN for d in /principle /auth; do \
        [ -d "$d" ] || { \
            echo "empira: $d is missing from the build context." >&2; \
            echo "This image builds from the workspace root, so clone or place" >&2; \
            echo "empira-principle and empira-auth beside app/ and run from there:" >&2; \
            echo "    docker compose -f app/docker-compose.yml up -d --build" >&2; \
            exit 1; \
        }; \
    done

RUN composer config repositories.principle \
        '{"type":"path","url":"/principle","options":{"symlink":false,"versions":{"iam-adty/empira-principle":"dev-main"}}}' \
    && composer config repositories.empira-auth \
        '{"type":"path","url":"/auth","options":{"symlink":false,"versions":{"iam-adty/empira-auth":"dev-main"}}}' \
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