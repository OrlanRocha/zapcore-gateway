#!/bin/sh
set -eu

runtime_dir=/run/zapcore-secrets
install -d -o root -g www-data -m 0750 "$runtime_dir"

copy_secret() {
  name="$1"
  source="/run/secrets/$name"
  target="$runtime_dir/$name"
  if [ ! -f "$source" ] || [ -L "$source" ] || [ ! -r "$source" ] || [ ! -s "$source" ]; then
    echo "Required secret $name is missing, empty, or unreadable." >&2
    exit 1
  fi
  install -o root -g www-data -m 0440 "$source" "$target"
}

for secret in app_key internal_secret worker_secret mysql_app_password; do
  copy_secret "$secret"
done

exec docker-php-entrypoint "$@"
