#!/bin/sh
# Run after creating the empty MySQL database and user.
# Example: mysql -u konferencia -p konferencia < database/schema.sql
set -eu
echo 'Import schema with: mysql -u konferencia -p konferencia < database/schema.sql'
