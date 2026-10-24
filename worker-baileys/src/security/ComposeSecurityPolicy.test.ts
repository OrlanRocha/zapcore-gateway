import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { parse } from 'yaml'

const repositoryRoot = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..')

interface ComposeSecretMount {
  source: string
  target: string
  mode: number
}

interface ComposeService {
  build?: string | { context?: string; dockerfile?: string }
  command?: string | string[]
  container_name?: string
  environment?: Record<string, unknown>
  entrypoint?: string[]
  expose?: number[]
  networks?: string[] | Record<string, unknown>
  ports?: string[]
  profiles?: string[]
  secrets?: ComposeSecretMount[]
  volumes?: string[]
}

interface ComposeDocument {
  networks: Record<string, { internal?: boolean }>
  secrets: Record<string, unknown>
  services: Record<string, ComposeService>
  volumes: Record<string, unknown>
}

function compose(name = 'docker-compose.yml'): ComposeDocument {
  return parse(readFileSync(join(repositoryRoot, name), 'utf8'), { version: '1.1' }) as ComposeDocument
}

function networkNames(service: ComposeService): string[] {
  return Array.isArray(service.networks) ? service.networks : Object.keys(service.networks || {})
}

test('base compose keeps database services private and preserves persistence', () => {
  const document = compose()
  const mysql = document.services.mysql
  const redis = document.services.redis
  const worker = document.services['baileys-worker']

  assert.equal(mysql.ports, undefined)
  assert.equal(redis.ports, undefined)
  assert.equal(worker.ports, undefined)
  assert.deepEqual(worker.expose, [3333])
  assert.ok(document.volumes.zapcore_db_data !== undefined)
  assert.ok(mysql.volumes?.includes('zapcore_db_data:/var/lib/mysql'))
  for (const service of Object.values(document.services)) {
    assert.equal(service.container_name, undefined)
  }
})

test('services use least-privilege ingress, application and data networks', () => {
  const document = compose()

  assert.deepEqual(Object.keys(document.networks).sort(), ['application', 'data', 'ingress'])
  assert.equal(document.networks.data.internal, true)
  assert.deepEqual(networkNames(document.services['php-app']).sort(), ['application', 'data', 'ingress'])
  assert.deepEqual(networkNames(document.services['baileys-worker']).sort(), ['application', 'data'])
  assert.deepEqual(networkNames(document.services['storage-scheduler']).sort(), ['application', 'data'])
  assert.deepEqual(networkNames(document.services.mysql), ['data'])
  assert.deepEqual(networkNames(document.services.redis), ['data'])
  assert.notEqual(document.networks.application.internal, true)
})

test('compose uses file secrets and contains no inline passwords', () => {
  const document = compose()
  const unsafeValues = new Set(['zapcore_pass', 'root_pass', 'change_worker_secret', 'change_internal_secret'])

  for (const secret of ['app_key', 'internal_secret', 'worker_secret', 'mysql_app_password', 'mysql_root_password', 'redis_acl']) {
    assert.ok(document.secrets[secret], `${secret} must be declared`)
  }
  assert.equal(document.services.mysql.environment?.MYSQL_PASSWORD, undefined)
  assert.equal(document.services.mysql.environment?.MYSQL_ROOT_PASSWORD, undefined)
  assert.equal(document.services.mysql.environment?.MYSQL_PASSWORD_FILE, '/run/secrets/mysql_app_password')
  assert.equal(document.services.mysql.environment?.MYSQL_ROOT_PASSWORD_FILE, '/run/secrets/mysql_root_password')
  for (const service of Object.values(document.services)) {
    for (const value of Object.values(service.environment || {})) {
      assert.equal(unsafeValues.has(String(value)), false)
    }
  }
})

test('PHP services use prebuilt images with database extensions', () => {
  const document = compose()
  assert.deepEqual(document.services['php-app'].build, {
    context: './backend-php',
    dockerfile: 'Dockerfile',
  })
  assert.deepEqual(document.services['storage-scheduler'].build, {
    context: './backend-php',
    dockerfile: 'Dockerfile.scheduler',
  })
  assert.doesNotMatch(String(document.services['php-app'].command || ''), /docker-php-ext-install/)
  assert.doesNotMatch(String(document.services['storage-scheduler'].command || ''), /docker-php-ext-install/)
})

test('redis is optional and requires its read-only ACL secret', () => {
  const redis = compose().services.redis

  assert.deepEqual(redis.profiles, ['redis'])
  assert.deepEqual(redis.entrypoint, ['/usr/local/bin/zapcore-redis-entrypoint.sh'])
  assert.ok(redis.volumes?.includes('./docker/redis-entrypoint.sh:/usr/local/bin/zapcore-redis-entrypoint.sh:ro'))
  const secret = redis.secrets?.find((entry) => entry.source === 'redis_acl')
  assert.equal(secret?.target, 'redis_acl')
  assert.equal(secret?.mode, 0o440)
})

test('administrative MySQL override binds only to loopback', () => {
  const override = compose('docker-compose.mysql-admin.yml')
  assert.deepEqual(override.services.mysql.ports, ['127.0.0.1:${MYSQL_ADMIN_PORT:-3306}:3306'])
})

test('secret files are ignored and examples are intentionally unusable', () => {
  const gitignore = readFileSync(join(repositoryRoot, '.gitignore'), 'utf8')
  const rootExample = readFileSync(join(repositoryRoot, '.env.example'), 'utf8')
  const backendExample = readFileSync(join(repositoryRoot, 'backend-php', '.env.example'), 'utf8')
  const workerExample = readFileSync(join(repositoryRoot, 'worker-baileys', '.env.example'), 'utf8')

  assert.match(gitignore, /^secrets\/\*$/m)
  assert.match(gitignore, /^!secrets\/examples\/$/m)
  assert.match(rootExample, /^APP_KEY_SECRET_FILE=/m)
  const values = `${rootExample}\n${backendExample}\n${workerExample}`
    .split(/\r?\n/)
    .filter((line) => line && !line.startsWith('#') && line.includes('='))
    .map((line) => line.slice(line.indexOf('=') + 1))
  for (const unsafe of ['zapcore_pass', 'root_pass', 'change_worker_secret', 'change_internal_secret']) {
    assert.equal(values.includes(unsafe), false)
  }
  assert.match(`${backendExample}\n${workerExample}`, /replace_with_invalid_placeholder/)
})
