#!/bin/bash
# =============================================================================
# DevPanel container startup — runs on each container restart.
# Imports a database dump if one exists, runs updatedb, and warms caches.
# =============================================================================

#== Import database dump if fresh container with no DB.
if [ -z "$(drush status --field=db-status 2>/dev/null)" ]; then
  if [[ -f "$APP_ROOT/.devpanel/dumps/db.sql.gz" ]]; then
    echo 'Import database dump...'
    drush sqlq --file="$APP_ROOT/.devpanel/dumps/db.sql.gz" --file-delete
  fi
fi

#== Sync volume if build directory exists.
if [[ -n "${DB_SYNC_VOL:-}" ]]; then
  if [[ ! -f "/var/www/build/.devpanel/init-container.sh" ]]; then
    echo 'Sync volume...'
    sudo chown -R 1000:1000 /var/www/build
    rsync -av --delete --delete-excluded $APP_ROOT/ /var/www/build
  fi
fi

#== Run pending database updates.
drush -n updb

#== Warm caches.
echo
echo 'Run cron.'
drush cron
echo
echo 'Populate caches.'
drush cache:warm &> /dev/null || :
$APP_ROOT/.devpanel/warm
