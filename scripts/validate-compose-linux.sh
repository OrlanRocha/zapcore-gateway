#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
project="zapcore-e3-ci-${CI_RUN_ID:-$$}"
secret_dir="$(mktemp -d)"
rendered="$(mktemp)"

cleanup() {
  docker compose -p "$project" --profile redis down --remove-orphans || true
  rm -rf "$secret_dir"
  rm -f "$rendered"
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
docker compose -p "$project" up -d --wait mysql php-app baileys-worker storage-scheduler

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

docker compose -p "$project" restart mysql
docker compose -p "$project" up -d --wait mysql
docker compose -p "$project" exec -T mysql sh -c '
MYSQL_PWD="$(cat /run/secrets/mysql_app_password)" mysql -u"$MYSQL_USER" "$MYSQL_DATABASE" -Nse "SELECT COUNT(*) FROM e3_ci_probe WHERE id=1" | grep -qx 1
'

docker compose -p "$project" --profile redis up -d redis
if docker compose -p "$project" exec -T redis redis-cli ping 2>/dev/null | grep -qx PONG; then
  echo 'Redis accepted an unauthenticated command' >&2
  exit 1
fi

if docker compose -p "$project" port mysql 3306 2>/dev/null | grep -q .; then
  echo 'MySQL unexpectedly publishes a host port' >&2
  exit 1
fi
if docker compose -p "$project" --profile redis port redis 6379 2>/dev/null | grep -q .; then
  echo 'Redis unexpectedly publishes a host port' >&2
  exit 1
fi

echo 'E3 isolated Compose validation passed; the dedicated test volume is intentionally retained.'
