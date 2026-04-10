#!/usr/bin/env bash
# =============================================================================
# Decoupled.io — DevPanel initialization script
#
# Runs on first deploy (or when the database is empty). Installs Drupal using
# the dc_core install profile, which sets up all custom modules, OAuth
# consumers, mail system, GraphQL, JSON:API write mode, and more.
#
# On subsequent deploys (database already exists), just runs drush updatedb
# to apply any new update hooks.
# =============================================================================
if [ -n "${DEBUG_SCRIPT:-}" ]; then
  set -x
fi
set -eu -o pipefail
cd $APP_ROOT

LOG_FILE="logs/init-$(date +%F-%T).log"
mkdir -p logs
exec > >(tee $LOG_FILE) 2>&1

TIMEFORMAT=%lR
export COMPOSER_NO_AUDIT=1
export COMPOSER_NO_DEV=1

# Install VSCode Extensions if requested.
if [ -n "${DP_VSCODE_EXTENSIONS:-}" ]; then
  IFS=','
  for value in $DP_VSCODE_EXTENSIONS; do
    time code-server --install-extension $value
  done
fi

#== Remove root-owned files.
echo
echo 'Remove root-owned files.'
time sudo rm -rf lost+found

#== Composer install.
echo
echo 'Composer install.'
if [ -f composer.json ]; then
  if composer show --locked cweagans/composer-patches ^2 &> /dev/null; then
    if [ "${DP_REGENERATE_PATCHES_LOCK:-0}" = "1" ]; then
      echo 'Regenerating patches.lock.json.'
      time composer patches:lock
      echo
    fi
  fi
fi
time composer clear-cache
time composer -n install --no-dev --optimize-autoloader --no-progress

#== Create required directories.
echo 'Ensure private files directory exists and is writable.'
mkdir -p private
sudo chown -R www-data:www-data private 2>/dev/null || \
  sudo chown -R www:www private 2>/dev/null || \
  chmod -R 777 private || :
chmod -R 775 private || :

if [ ! -d config/sync ]; then
  echo
  echo 'Create the config sync directory.'
  time mkdir -p config/sync
fi

#== Create public files directory (must exist + be writable by web server).
echo
echo 'Ensure public files directory exists and is writable.'
mkdir -p web/sites/default/files/media-icons/generic || :
sudo chown -R www-data:www-data web/sites/default/files 2>/dev/null || \
  sudo chown -R www:www web/sites/default/files 2>/dev/null || \
  chmod -R 777 web/sites/default/files || :
chmod -R 775 web/sites/default/files || :

#== Ensure settings.php exists and is writable (drush site:install needs to write to it).
if [ ! -f web/sites/default/settings.php ]; then
  echo
  echo 'Create settings.php from default.settings.php.'
  cp web/sites/default/default.settings.php web/sites/default/settings.php
  # Append DevPanel settings include if not already present.
  if ! grep -q 'settings.devpanel.php' web/sites/default/settings.php; then
    cat >> web/sites/default/settings.php <<'SETTINGS_EOF'

/**
 * Load DevPanel override configuration, if available.
 */
$devpanel_settings = dirname($app_root) . '/.devpanel/settings.devpanel.php';
if (getenv('DP_APP_ID') !== FALSE && file_exists($devpanel_settings)) {
  include $devpanel_settings;
}
SETTINGS_EOF
  fi
fi
chmod 666 web/sites/default/settings.php || :
chmod 775 web/sites/default || :

#== Generate hash salt.
if [ ! -f .devpanel/salt.txt ]; then
  echo
  echo 'Generate hash salt.'
  time openssl rand -hex 32 > .devpanel/salt.txt
fi

#== Install or update Drupal.
echo
if [ -z "$(drush status --field=db-status 2>/dev/null)" ]; then
  echo '========================================='
  echo ' Installing Drupal with dc_core profile'
  echo '========================================='

  # Install Drupal with the dc_core install profile.
  # This runs dc_core.install which sets up:
  # - OAuth consumers (Next.js Frontend, Next.js Viewer, MCP Agent)
  # - OAuth key generation
  # - Mail system (Resend HTTP API via dc_mail)
  # - GraphQL + JSON:API with write support
  # - Pathauto patterns
  # - Caching defaults (page_cache, dynamic_page_cache)
  # - Content moderation, multilingual, etc.
  time drush -n site:install dc_core \
    --account-name=admin \
    --account-pass=admin \
    --account-mail=admin@example.com \
    --site-name="Decoupled CMS" \
    --site-mail=noreply@decoupled.io

  echo
  echo 'Clearing cache...'
  time drush -n cr || :

  echo
  echo 'Generating login link...'
  drush -n uli || :
else
  echo 'Database exists — running updatedb.'
  time drush -n updb
fi

#== Warm up caches.
echo
echo 'Run cron.'
time drush cron
echo
echo 'Populate caches.'
time drush cache:warm &> /dev/null || :
time .devpanel/warm

#== Finish.
INIT_DURATION=$SECONDS
INIT_HOURS=$(($INIT_DURATION / 3600))
INIT_MINUTES=$(($INIT_DURATION % 3600 / 60))
INIT_SECONDS=$(($INIT_DURATION % 60))
printf "\nTotal elapsed time: %d:%02d:%02d\n" $INIT_HOURS $INIT_MINUTES $INIT_SECONDS
