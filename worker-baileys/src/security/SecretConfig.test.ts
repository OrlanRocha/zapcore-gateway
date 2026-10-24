import assert from 'node:assert/strict'
import { chmodSync, mkdirSync, mkdtempSync, rmSync, symlinkSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { test } from 'node:test'
import { loadConfig, readSecret } from './SecretConfig'

test('reads direct and file-backed secrets', () => {
  const root = mkdtempSync(join(tmpdir(), 'zapcore-secrets-'))
  try {
    const secretFile = join(root, 'worker-secret')
    writeFileSync(secretFile, 'file-secret-value-1234567890\n', { mode: 0o600 })

    assert.equal(readSecret('WORKER_SECRET', { WORKER_SECRET: 'direct-secret-value-1234567890' }), 'direct-secret-value-1234567890')
    assert.equal(readSecret('WORKER_SECRET', { WORKER_SECRET_FILE: secretFile }), 'file-secret-value-1234567890')
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})

test('rejects missing, conflicting, empty, non-file and predictable secrets', () => {
  const root = mkdtempSync(join(tmpdir(), 'zapcore-secrets-'))
  try {
    const emptyFile = join(root, 'empty')
    const directory = join(root, 'directory')
    writeFileSync(emptyFile, ' \n', { mode: 0o600 })
    mkdirSync(directory, { mode: 0o700 })

    const invalidEnvironments = [
      {},
      { WORKER_SECRET: 'direct', WORKER_SECRET_FILE: emptyFile },
      { WORKER_SECRET_FILE: join(root, 'missing') },
      { WORKER_SECRET_FILE: emptyFile },
      { WORKER_SECRET_FILE: directory },
      { WORKER_SECRET: 'short' },
      { WORKER_SECRET: 'change_worker_secret' },
    ]

    for (const environment of invalidEnvironments) {
      assert.throws(
        () => readSecret('WORKER_SECRET', environment),
        (error: Error) => error.message.includes('WORKER_SECRET')
          && !error.message.includes('direct')
          && !error.message.includes('change_worker_secret'),
      )
    }
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})

test('rejects an unreadable secret file when the platform enforces permissions', (context) => {
  if (process.platform === 'win32') {
    context.skip('Windows does not reliably enforce POSIX file mode bits')
    return
  }

  const root = mkdtempSync(join(tmpdir(), 'zapcore-secrets-'))
  const secretFile = join(root, 'secret')
  try {
    writeFileSync(secretFile, 'secret', { mode: 0o600 })
    chmodSync(secretFile, 0o000)
    assert.throws(() => readSecret('WORKER_SECRET', { WORKER_SECRET_FILE: secretFile }))
  } finally {
    chmodSync(secretFile, 0o600)
    rmSync(root, { recursive: true, force: true })
  }
})

test('rejects symbolic link secret files', (context) => {
  if (process.platform === 'win32') {
    context.skip('Windows test environments do not reliably permit symlink creation')
    return
  }

  const root = mkdtempSync(join(tmpdir(), 'zapcore-secrets-'))
  try {
    const target = join(root, 'target')
    const link = join(root, 'link')
    writeFileSync(target, 'file-secret-value-1234567890\n', { mode: 0o600 })
    symlinkSync(target, link)
    assert.throws(() => readSecret('WORKER_SECRET', { WORKER_SECRET_FILE: link }))
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})

test('loads a complete production configuration from file secrets', () => {
  const root = mkdtempSync(join(tmpdir(), 'zapcore-secrets-'))
  try {
    const files = Object.fromEntries(['APP_KEY', 'WORKER_SECRET', 'PHP_INTERNAL_SECRET', 'DB_PASSWORD'].map((name) => {
      const path = join(root, name.toLowerCase())
      writeFileSync(path, `${name.toLowerCase()}-secure-value-1234567890\n`, { mode: 0o600 })
      return [`${name}_FILE`, path]
    }))

    const config = loadConfig({
      NODE_ENV: 'production',
      WORKER_HOST: '0.0.0.0',
      WORKER_PORT: '3333',
      PHP_API_URL: 'http://php-app:80',
      MEDIA_STORAGE_PATH: '/app/storage/media',
      DB_HOST: 'mysql',
      DB_PORT: '3306',
      DB_DATABASE: 'zapcore_gateway',
      DB_USERNAME: 'zapcore',
      ...files,
    })

    assert.equal(config.workerSecret, 'worker_secret-secure-value-1234567890')
    assert.equal(config.db.password, 'db_password-secure-value-1234567890')
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})
