#!/bin/bash
# =============================================================================
# DevPanel re-configuration — runs on each deploy/re-deploy.
# Handles composer install, settings patching, DB/files import.
# =============================================================================

STATIC_FILES_PATH="$WEB_ROOT/sites/default/files/"
SETTINGS_FILES_PATH="$WEB_ROOT/sites/default/settings.php"

# Create static files directory.
if [ ! -d "$STATIC_FILES_PATH" ]; then
  mkdir -p $STATIC_FILES_PATH
fi

# Composer install.
if [[ -f "$APP_ROOT/composer.json" ]]; then
  cd $APP_ROOT && composer install --no-dev --optimize-autoloader
fi

# Generate hash salt.
echo 'Generate hash salt...'
DRUPAL_HASH_SALT=$(openssl rand -hex 32)
echo $DRUPAL_HASH_SALT > $APP_ROOT/.devpanel/salt.txt

# Secure file permissions.
[[ ! -d $STATIC_FILES_PATH ]] && sudo mkdir --mode 775 $STATIC_FILES_PATH || sudo chmod 775 -R $STATIC_FILES_PATH

# Extract static files from dump if fresh deploy.
if [ -z "$(drush status --field=db-status 2>/dev/null)" ]; then
  if [[ -f "$APP_ROOT/.devpanel/dumps/files.tgz" ]]; then
    echo 'Extract static files...'
    sudo mkdir -p $STATIC_FILES_PATH
    sudo tar xzf "$APP_ROOT/.devpanel/dumps/files.tgz" -C $STATIC_FILES_PATH
    sudo rm -rf $APP_ROOT/.devpanel/dumps/files.tgz
  fi

  # Import database dump.
  if [[ -f "$APP_ROOT/.devpanel/dumps/db.sql.gz" ]]; then
    echo 'Import database dump...'
    drush sqlq --file="$APP_ROOT/.devpanel/dumps/db.sql.gz" --file-delete
  fi
fi
