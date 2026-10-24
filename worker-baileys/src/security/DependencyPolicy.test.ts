import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const workerRoot = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

interface PackageManifest {
  overrides: Record<string, string>
}

interface LockfilePackage {
  version: string
}

interface PackageLock {
  packages: Record<string, LockfilePackage>
}

function readJson<T>(path: string): T {
  return JSON.parse(readFileSync(path, 'utf8')) as T
}

function assertEveryLockedVersion(
  packages: Record<string, LockfilePackage>,
  dependency: string,
  expectedVersion: string,
): void {
  const entries = Object.entries(packages).filter(([path]) => (
    path === `node_modules/${dependency}` || path.endsWith(`/node_modules/${dependency}`)
  ))

  assert.ok(entries.length > 0, `${dependency} must be present in the lockfile`)
  for (const [path, lockedPackage] of entries) {
    assert.equal(lockedPackage.version, expectedVersion, `${path} resolved an unsafe version`)
  }
}

test('pins security floors for vulnerable transitive dependencies', () => {
  const packageJson = readJson<PackageManifest>(join(workerRoot, 'package.json'))

  assert.equal(packageJson.overrides.qs, '^6.16.0')
  assert.equal(packageJson.overrides['follow-redirects'], '^1.16.1')
  assert.equal(packageJson.overrides['music-metadata'], '^11.16.1')
})

test('lockfile resolves only patched dependency versions', () => {
  const packageLock = readJson<PackageLock>(join(workerRoot, 'package-lock.json'))
  const packages = packageLock.packages

  assertEveryLockedVersion(packages, 'follow-redirects', '1.16.1')
  assertEveryLockedVersion(packages, 'music-metadata', '11.16.1')
  assertEveryLockedVersion(packages, 'body-parser', '1.20.9')
  assertEveryLockedVersion(packages, 'yaml', '2.8.3')
})

test('Docker build uses the reproducible npm installation command', () => {
  const dockerfile = readFileSync(join(workerRoot, 'Dockerfile'), 'utf8')
  const installCommands = dockerfile
    .split(/\r?\n/)
    .filter((line) => /^RUN\s+npm\s+(?:ci|install)(?:\s|$)/.test(line))

  assert.deepEqual(installCommands, ['RUN npm ci'])
})
