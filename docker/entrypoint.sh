#!/bin/sh
# Start FrankenPHP, in whichever mode APP_RUNTIME asks for.
#
#     worker   boot the application once, serve many requests per process
#     classic  run the front controller afresh for each request
#
# Worker is the default, because that is what this image is for. Classic is
# still there: it is the mode to compare against when something behaves
# differently, and the mode that behaves like php-fpm did.
set -eu

cd /workspace/app

# The root is public/ and only public/. It is what makes the front controller
# findable, and it is the security boundary: with the root left at the project
# directory, Caddy would serve src/, vendor/ and storage/ as documents.
set -- --listen "${LISTEN:-:8080}" --root /workspace/app/public

if [ "${APP_RUNTIME:-worker}" = "worker" ]; then
    # FrankenPHP defaults to two workers per CPU, which is right for throughput
    # and wrong for reading logs. Set it to 1 to prove something that depends on
    # one process serving many requests in order.
    set -- "$@" --worker "/workspace/app/public/index.php${FRANKENPHP_WORKERS:+,${FRANKENPHP_WORKERS}}"

    if [ "${APP_ENV:-}" = "local" ]; then
        # The source is bind-mounted so an edit needs no rebuild — but a worker
        # is holding the previous Application in memory, and restarting it is
        # the one thing a rebuild cannot do for you. Watch, and let the worker
        # restart itself.
        #
        # Two globs, because there are two live trees: this application, and the
        # framework mounted over its `src` (see docker-compose.yml). Watching
        # only the first is how an edit to `principle/src` looks like it did
        # nothing.
        set -- "$@" --watch="/workspace/app/src/**/*.php"
        set -- "$@" --watch="/workspace/app/vendor/iam-adty/empira-principle/src/**/*.php"
    fi
fi

exec frankenphp php-server run "$@"
