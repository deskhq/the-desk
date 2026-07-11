#!/bin/sh
set -e

# Named volumes mount in empty, so ensure the writable runtime directories exist
# before caching or linking anything.
mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/app/public \
    bootstrap/cache

# Run migrations automatically on start. Only the `app` service sets
# AUTORUN_MIGRATIONS=true; the reverb/queue/scheduler services leave it unset so
# four containers don't race to migrate the same database.
if [ "${AUTORUN_MIGRATIONS:-false}" = "true" ]; then
    echo "Running database migrations..."
    php artisan migrate --force

    # Rebuild the search index if it is empty (e.g. after a Meilisearch version
    # bump rotated its data volume). No-op once the index is populated.
    echo "Syncing search index..."
    php artisan search:sync
fi

# Cache config/routes/events against the runtime environment (compose injects it
# at container start, so this must happen here, not at build time) and link
# public storage. All idempotent and per-container.
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan storage:link

exec "$@"
