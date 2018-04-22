#!/bin/sh

cd "$(dirname $0)/.."

chown -R root:www-data .
chmod -R 0700 .
chmod 0750 .
chmod -R 0750 app web vendor src var templates node_modules
chmod -R 0770 var/cache var/logs