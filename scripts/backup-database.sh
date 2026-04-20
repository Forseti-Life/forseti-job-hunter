#!/bin/bash
# Backup database
BACKUP_DIR="../backups"
mkdir -p "$BACKUP_DIR"
BACKUP_FILE="$BACKUP_DIR/db-backup-$(date +%Y%m%d-%H%M%S).sql"

echo "Creating database backup..."
../vendor/bin/drush sql:dump --result-file="$BACKUP_FILE"
echo "Database backup created: $BACKUP_FILE"
