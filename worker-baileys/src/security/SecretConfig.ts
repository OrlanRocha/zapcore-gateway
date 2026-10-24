import { accessSync, constants, readFileSync, statSync } from 'node:fs'

type Environment = Record<string, string | undefined>

const unsafeValues = new Set([
  'zapcore_pass',
  'root_pass',
  'change_worker_secret',
  'change_internal_secret',
  'change_me_32_chars_minimum_key_123',
])

function required(name: string, environment: Environment): string {
  const value = environment[name]?.trim() || ''
  if (!value) throw new Error(`Missing required configuration: ${name}`)
  return value
}
export function readSecret(name: string, environment: Environment = process.env): string {
  let value = environment[name]?.trim() || ''
  const fileName = `${name}_FILE`
  const file = environment[fileName]?.trim() || ''

  if (value && file) throw new Error(`Conflicting secret configuration: ${name}`)

  if (file) {
    try {
      accessSync(file, constants.R_OK)
      if (!statSync(file).isFile()) throw new Error('not a regular file')
      value = readFileSync(file, 'utf8').trim()
    } catch {
      throw new Error(`Unreadable secret file for ${name}`)
    }
  }

  if (!value) throw new Error(`Missing or empty secret: ${name}`)
  if (value.length < 24) throw new Error(`Secret is too short: ${name}`)
  if (unsafeValues.has(value.toLowerCase()) || value.toLowerCase().startsWith('replace_')) {
    throw new Error(`Unsafe placeholder secret: ${name}`)
  }
  return value
}

function optionalSecret(name: string, environment: Environment, strict: boolean): string {
  if (strict || environment[name] || environment[`${name}_FILE`]) return readSecret(name, environment)
  return ''
}

export function loadConfig(environment: Environment = process.env) {
  const production = (environment.NODE_ENV || 'development') === 'production'
  const requiredWhenProduction = (name: string, fallback: string): string => (
    production ? required(name, environment) : environment[name]?.trim() || fallback
  )

  return {
    nodeEnv: environment.NODE_ENV || 'development',
    workerPort: Number(requiredWhenProduction('WORKER_PORT', '3333')),
    workerHost: requiredWhenProduction('WORKER_HOST', '0.0.0.0'),
    workerSecret: optionalSecret('WORKER_SECRET', environment, production),
    phpApiUrl: requiredWhenProduction('PHP_API_URL', 'http://localhost:8080'),
    phpInternalSecret: optionalSecret('PHP_INTERNAL_SECRET', environment, production),
    mediaStoragePath: requiredWhenProduction('MEDIA_STORAGE_PATH', '/app/storage/media'),
    appKey: optionalSecret('APP_KEY', environment, production),
    db: {
      host: requiredWhenProduction('DB_HOST', '127.0.0.1'),
      port: Number(requiredWhenProduction('DB_PORT', '3306')),
      user: requiredWhenProduction('DB_USERNAME', 'root'),
      password: optionalSecret('DB_PASSWORD', environment, production),
      database: requiredWhenProduction('DB_DATABASE', 'zapcore_gateway'),
    },
  }
}
