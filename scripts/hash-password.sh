#!/bin/bash
# Prints a password hash for admin_password_hash in config.php.
# The password comes from COMMENTS_ADMIN_PASSWORD, so it stays out of the shell history.
# Uses a local PHP if there is one, otherwise the official Docker image.
set -euo pipefail

if [[ -z ${COMMENTS_ADMIN_PASSWORD:-} ]]; then
  echo "Set COMMENTS_ADMIN_PASSWORD first." >&2
  exit 1
fi
export COMMENTS_ADMIN_PASSWORD

code='echo password_hash(getenv("COMMENTS_ADMIN_PASSWORD"), PASSWORD_DEFAULT);'
if command -v php >/dev/null; then
  hash=$(php -r "$code")
else
  hash=$(docker run --rm -e COMMENTS_ADMIN_PASSWORD php:8.5-cli php -r "$code")
fi

if [[ $hash != \$2y\$* ]]; then
  echo "Could not create the password hash." >&2
  exit 1
fi
printf '%s\n' "$hash"
