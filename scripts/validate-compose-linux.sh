#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
project="zapcore-e3-ci-${CI_RUN_ID:-$$}"
secret_dir="$(mktemp -d)"
rendered="$(mktemp)"

cleanup() {
  status=$?
  if [ "$status" -ne 0 ]; then
    docker compose -p "$project" --profile redis ps -a || true
    docker compose -p "$project" --profile redis logs --no-color --tail 100 || true
  fi
  docker compose -p "$project" --profile redis down -v --remove-orphans || true
  rm -rf "$secret_dir"
  rm -f "$rendered"
  return "$status"
}
trap cleanup EXIT

if env \
  -u APP_URL \
  -u MYSQL_DATABASE \
  -u MYSQL_USER \
  -u APP_KEY_SECRET_FILE \
  -u INTERNAL_SECRET_FILE \
  -u WORKER_SECRET_FILE \
  -u MYSQL_APP_PASSWORD_FILE \
  -u MYSQL_ROOT_PASSWORD_FILE \
  docker compose -p "$project" config >/dev/null 2>&1; then
  echo 'Compose unexpectedly accepted missing required configuration.' >&2
  exit 1
fi

write_secret() {
  local name="$1"
  local value="$2"
  printf '%s\n' "$value" >"$secret_dir/$name"
  chmod 600 "$secret_dir/$name"
}

write_secret app_key "$(openssl rand -hex 32)"
write_secret internal_secret "$(openssl rand -hex 32)"
write_secret worker_secret "$(openssl rand -hex 32)"
write_secret mysql_app_password "$(openssl rand -hex 32)"
write_secret mysql_root_password "$(openssl rand -hex 32)"
redis_password="$(openssl rand -hex 32)"
printf 'user default off\nuser zapcore on >%s ~* +@all\n' "$redis_password" >"$secret_dir/redis-users.acl"
chmod 600 "$secret_dir/redis-users.acl"

export APP_URL='http://127.0.0.1:18080'
export MYSQL_DATABASE='zapcore_gateway'
export MYSQL_USER='zapcore'
export APP_KEY_SECRET_FILE="$secret_dir/app_key"
export INTERNAL_SECRET_FILE="$secret_dir/internal_secret"
export WORKER_SECRET_FILE="$secret_dir/worker_secret"
export MYSQL_APP_PASSWORD_FILE="$secret_dir/mysql_app_password"
export MYSQL_ROOT_PASSWORD_FILE="$secret_dir/mysql_root_password"
export REDIS_ACL_FILE="$secret_dir/redis-users.acl"

cd "$repo_root"
docker compose -p "$project" --profile redis config --format json >"$rendered"
node - "$rendered" <<'NODE'
const fs = require('node:fs')
const compose = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'))
for (const name of ['mysql', 'redis']) {
  if (compose.services[name].ports) throw new Error(`${name} unexpectedly publishes a host port`)
}
if (!compose.volumes || !compose.volumes.zapcore_db_data) throw new Error('zapcore_db_data is missing')
if (!compose.networks.data.internal) throw new Error('data network must be internal')
NODE

docker compose -p "$project" build

empty_secret="$secret_dir/empty"
: >"$empty_secret"
chmod 600 "$empty_secret"
if APP_KEY_SECRET_FILE="$empty_secret" docker compose -p "$project" run --rm --no-deps php-app php -v >/dev/null 2>&1; then
  echo 'PHP unexpectedly accepted an empty mounted secret.' >&2
  exit 1
fi
if APP_KEY_SECRET_FILE="$secret_dir/missing" docker compose -p "$project" run --rm --no-deps php-app php -v >/dev/null 2>&1; then
  echo 'PHP unexpectedly accepted a missing mounted secret.' >&2
  exit 1
fi

docker compose -p "$project" up -d --wait mysql php-app baileys-worker storage-scheduler

for service in mysql php-app baileys-worker storage-scheduler; do
  test "$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' "$project-$service-1")" = healthy
done

volume_name="${project}_zapcore_db_data"
test "$(docker volume inspect -f '{{index .Labels "com.docker.compose.volume"}}' "$volume_name")" = zapcore_db_data
test "$(docker volume inspect -f '{{index .Labels "com.docker.compose.project"}}' "$volume_name")" = "$project"

docker compose -p "$project" exec -T php-app sh -c '
for file in /run/zapcore-secrets/*; do
  test "$(stat -c %a "$file")" = 440
  su -s /bin/sh www-data -c "test -r $file"
done
'

media_probe="e3-media-$project"
cache_probe="e3-cache-$project"
docker compose -p "$project" exec -T php-app sh -c "printf media-ok > /var/www/html/storage/media/$media_probe && printf cache-ok > /var/www/html/storage/cache/$cache_probe"
docker compose -p "$project" exec -T baileys-worker sh -c "grep -qx media-ok /app/storage/media/$media_probe"
docker compose -p "$project" up -d --wait --force-recreate php-app
docker compose -p "$project" exec -T php-app sh -c "grep -qx media-ok /var/www/html/storage/media/$media_probe && grep -qx cache-ok /var/www/html/storage/cache/$cache_probe"
rm -f "$repo_root/backend-php/storage/media/$media_probe" "$repo_root/backend-php/storage/cache/$cache_probe"

docker compose -p "$project" exec -T php-app php -r '
$password = trim(file_get_contents(getenv("DB_PASSWORD_FILE")));
$pdo = new PDO("mysql:host=mysql;dbname=" . getenv("DB_DATABASE"), getenv("DB_USERNAME"), $password);
$pdo->exec("CREATE TABLE IF NOT EXISTS e3_ci_probe (id INT PRIMARY KEY)");
$pdo->exec("INSERT IGNORE INTO e3_ci_probe (id) VALUES (1)");
echo "php-mysql-ok\n";
'

docker compose -p "$project" exec -T baileys-worker node - <<'NODE'
const fs = require('node:fs')
const mysql = require('mysql2/promise')
;(async () => {
  const connection = await mysql.createConnection({
    host: process.env.DB_HOST,
    user: process.env.DB_USERNAME,
    database: process.env.DB_DATABASE,
    password: fs.readFileSync(process.env.DB_PASSWORD_FILE, 'utf8').trim(),
  })
  await connection.query('SELECT 1')
  await connection.end()
  console.log('worker-mysql-ok')
})().catch((error) => { console.error(error.message); process.exit(1) })
NODE

docker compose -p "$project" up -d --wait --force-recreate mysql
docker compose -p "$project" exec -T mysql sh -c '
MYSQL_PWD="$(cat /run/secrets/mysql_app_password)" mysql -u"$MYSQL_USER" "$MYSQL_DATABASE" -Nse "SELECT COUNT(*) FROM e3_ci_probe WHERE id=1" | grep -qx 1
'

docker compose -p "$project" --profile redis up -d --wait redis
if docker compose -p "$project" exec -T redis redis-cli ping 2>/dev/null | grep -qx PONG; then
  echo 'Redis accepted an unauthenticated command' >&2
  exit 1
fi

docker compose -p "$project" exec -T redis sh -c '
password="$(sed -n "s/^user zapcore on >\\([^ ]*\\).*/\\1/p" /run/secrets/redis_acl)"
test -n "$password"
REDISCLI_AUTH="$password" redis-cli --user zapcore ping | grep -qx PONG
'

docker compose -p "$project" exec -T baileys-worker node - <<'NODE'
const controller = new AbortController()
const timeout = setTimeout(() => controller.abort(), 15000)
fetch('https://web.whatsapp.com', { signal: controller.signal })
  .then((response) => {
    clearTimeout(timeout)
    if (response.status < 100) process.exit(1)
    console.log('baileys-egress-ok')
  })
  .catch((error) => { console.error(error.message); process.exit(1) })
NODE

if docker compose -p "$project" port mysql 3306 2>/dev/null | grep -q .; then
  echo 'MySQL unexpectedly publishes a host port' >&2
  exit 1
fi
if docker compose -p "$project" --profile redis port redis 6379 2>/dev/null | grep -q .; then
  echo 'Redis unexpectedly publishes a host port' >&2
  exit 1
fi

echo 'E3 isolated Compose validation passed; all test resources will be removed.'
