#!/usr/bin/env bash
# =============================================================================
# Install system-level packages required by Decoupled.io on DevPanel.
# Runs once on container creation — installs npm, AVIF support, APCu, and
# uploadprogress PECL extension.
# =============================================================================
if [ -n "$DEBUG_SCRIPT" ]; then
    set -x
fi

# Install APT packages.
if ! command -v npm >/dev/null 2>&1; then
  sudo apt-get update
  sudo apt-get install -y jq nano npm
fi

# Enable AVIF support in GD extension if not already enabled.
if [ -z "$(php --ri gd | grep AVIF)" ]; then
  sudo apt-get update
  sudo apt-get install -y libavif-dev
  sudo docker-php-ext-configure gd --with-avif --with-freetype --with-jpeg --with-webp
  sudo docker-php-ext-install gd
fi

PECL_UPDATED=false
# Install APCu extension.
if ! php --ri apcu > /dev/null 2>&1; then
  $PECL_UPDATED || sudo pecl update-channels && PECL_UPDATED=true
  sudo pecl install apcu <<< ''
  echo 'extension=apcu.so' | sudo tee /usr/local/etc/php/conf.d/apcu.ini
fi
# Install uploadprogress extension.
if ! php --ri uploadprogress > /dev/null 2>&1; then
  $PECL_UPDATED || sudo pecl update-channels && PECL_UPDATED=true
  sudo pecl install uploadprogress
  echo 'extension=uploadprogress.so' | sudo tee /usr/local/etc/php/conf.d/uploadprogress.ini
fi
# Reload Apache if running.
if $PECL_UPDATED && sudo /etc/init.d/apache2 status > /dev/null 2>&1; then
  sudo /etc/init.d/apache2 reload
fi
