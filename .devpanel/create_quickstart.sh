#!/bin/bash
# =============================================================================
# DevPanel Quickstart Creator — exports database + files for Docker image.
# =============================================================================

echo -e "-------------------------------"
echo -e "| DevPanel Quickstart Creator |"
echo -e "-------------------------------\n"

WORK_DIR=$APP_ROOT
TMP_DIR=/tmp/devpanel/quickstart
DUMPS_DIR=$TMP_DIR/dumps
STATIC_FILES_DIR=$WEB_ROOT/sites/default/files

mkdir -p $DUMPS_DIR

# Export database.
cd $WORK_DIR
echo -e "> Export database to $APP_ROOT/.devpanel/dumps"
mkdir -p $APP_ROOT/.devpanel/dumps
drush cr --quiet
drush sql-dump --result-file=../.devpanel/dumps/db.sql --gzip --extra-dump=--no-tablespaces

# Compress static files.
cd $WORK_DIR
echo -e "> Compress static files"
tar czf $DUMPS_DIR/files.tgz -C $STATIC_FILES_DIR .

echo -e "> Store files.tgz to $APP_ROOT/.devpanel/dumps"
mkdir -p $APP_ROOT/.devpanel/dumps
mv $DUMPS_DIR/files.tgz $APP_ROOT/.devpanel/dumps/files.tgz
