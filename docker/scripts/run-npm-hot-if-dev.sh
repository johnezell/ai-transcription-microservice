#!/bin/bash
# Script to run npm run hot only if APP_ENV is not production.

# Ensure APP_ENV is available, provide a default if not set (though it should be from .env)
: "${APP_ENV:=development}"

if [ "$APP_ENV" != "production" ] && [ "$APP_ENV" != "prod" ]; then
  echo "APP_ENV is '$APP_ENV'. Starting development server (npm run dev)..."
  cd /var/www
  # The -- --host=0.0.0.0 part is passed after npm run dev resolves
  exec npm run dev -- --host=0.0.0.0
else
  echo "APP_ENV is '$APP_ENV'. Development server will not start. This process will sleep indefinitely."
  # Sleep indefinitely so supervisor doesn't keep restarting it if autorestart is true
  while true; do sleep 3600; done
fi 