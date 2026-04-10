#!/bin/bash
# =============================================================================
# DevPanel re-configuration — runs on each deploy/re-deploy.
# Handles composer install, settings patching, DB/files import.
# =============================================================================

STATIC_FILES_PATH="$WEB_ROOT/sites/default/files/"
SETTINGS_FILES_PATH="$WEB_ROOT/sites/default/settings.php"

# Create static files directory + media-icons subdirectory + fix ownership.
mkdir -p "${STATIC_FILES_PATH}media-icons/generic" || :
sudo chown -R www-data:www-data "$STATIC_FILES_PATH" 2>/dev/null || \
  sudo chown -R www:www "$STATIC_FILES_PATH" 2>/dev/null || \
  chmod -R 777 "$STATIC_FILES_PATH" || :

# Ensure settings.php exists with DevPanel include.
if [ ! -f "$SETTINGS_FILES_PATH" ]; then
  echo 'Create settings.php from default.settings.php.'
  cp "$WEB_ROOT/sites/default/default.settings.php" "$SETTINGS_FILES_PATH"
  if ! grep -q 'settings.devpanel.php' "$SETTINGS_FILES_PATH"; then
    cat >> "$SETTINGS_FILES_PATH" <<'SETTINGS_EOF'

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
chmod 666 "$SETTINGS_FILES_PATH" || :
chmod 775 "$WEB_ROOT/sites/default" || :

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
