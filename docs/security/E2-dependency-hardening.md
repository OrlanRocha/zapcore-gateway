# E2 - Dependency hardening

## Scope and Git base

- Base branch: `codex/security-ssrf-hardening`
- Base commit: `4692d37aa3ec225e2522eebb42f3834b855053b2`
- Implementation branch: `codex/security-dependencies-e2`
- The branch intentionally starts at the completed E1 commit so its SSRF boundary is preserved.
- E2 does not merge into `main`, deploy, modify the VPS, touch production data, or modify WhatsApp sessions.

## Findings before E2

The `main` lockfile produced two npm audit findings on 2026-10-23:

| Dependency | Resolved version | Introduced by | Severity | Advisory | Fixed version |
| --- | --- | --- | --- | --- | --- |
| `follow-redirects` | 1.16.0 | `axios@1.20.0` | High | `GHSA-q3jh-wrc6-jgq8`, `GHSA-8r9p-f939-6h3c` | 1.16.1 |
| `music-metadata` | 11.15.0 | `@whiskeysockets/baileys@6.7.24` | Moderate | `GHSA-f94x-6692-553q`, `GHSA-5gfj-9q3v-qfp3` | 11.16.0 or later |

The E1 lockfile had already resolved the patched versions as part of its final security verification. E2 makes those security floors explicit and regression-tested without changing direct dependency versions.

## Resolution

- Preserve `axios@1.20.0` and `@whiskeysockets/baileys@6.7.24`.
- Add compatible npm overrides for `follow-redirects@^1.16.1` and `music-metadata@^11.16.1`.
- Preserve the existing `qs@^6.16.0` override.
- Resolve exactly `follow-redirects@1.16.1` and `music-metadata@11.16.1` in `package-lock.json`.
- Replace Docker's `npm install` with `npm ci` so image builds fail on manifest/lockfile drift.
- Add a policy regression test for the overrides, exact lockfile resolutions, and Docker installation command.
- Stabilize the existing executable-upload test with a valid DOS header. The previous four-byte fixture was identified as `text/plain` by PHP `finfo` on Windows and did not represent an executable. No PHP production code changed.

The parent ranges accept these versions:

- Axios declares `follow-redirects@^1.16.0`.
- Baileys declares `music-metadata@^11.7.0`.

No major version migration or Baileys API change was required.

## Modified files

- `worker-baileys/package.json`
- `worker-baileys/Dockerfile`
- `worker-baileys/src/security/DependencyPolicy.test.ts`
- `backend-php/tests/Unit/MediaUploadServiceTest.php`
- `docs/security/E2-dependency-hardening.md`
- `docs/superpowers/plans/2026-10-23-security-dependencies-e2.md`

`worker-baileys/package-lock.json` remains byte-for-byte at the E1 resolution because it already contains the exact corrected artifacts and integrity hashes. `npm ci` confirms that it is consistent with the E2 manifest.

## Verification results

| Check | Before E2 | After E2 |
| --- | --- | --- |
| `npm audit --package-lock-only` on `main` | 1 high, 1 moderate | Not applicable to E2 branch |
| `npm audit --json` | 0 on E1-derived base | 0 |
| `npm audit --omit=dev --json` | Not recorded | 0 |
| `npm ci` | Passed | Passed, 222 packages installed |
| Node tests | 14 passed, 1 skipped | 17 passed, 1 skipped |
| TypeScript build | Passed | Passed |
| PHPUnit | Initial run exposed an invalid MIME fixture | 90 passed, 236 assertions |
| Composer audit | 0 advisories | 0 advisories, 0 abandoned packages |

The skipped Node test verifies rejection of a symlink escaping the media root. Windows denied symlink creation, so the test is reported as skipped and is not counted as passed. It should run in Linux CI.

Docker uses the same Node 20 major as before. Node 20 satisfies Baileys (`>=20`) and the corrected transitive dependencies. A local Docker image build could not be executed because the Docker CLI is not installed on the validation host; the Dockerfile policy is covered by an automated test, but Linux CI must perform the image build.

## E1 compatibility

- The E1 local-media boundary tests pass for image, audio, video, and document payloads.
- Manipulated payloads containing both a local path and remote URL remain rejected.
- Absolute paths, traversal, missing files, directories, and oversized files remain rejected.
- The worker has no new remote media download path.
- `downloadMediaMessage` remains an inbound Baileys operation and is not used to fetch queued URL media.
- No authentication, session persistence, reconnection, group-event, queue, or MySQL implementation changed in E2.

## Residual risks

- `npm audit` detects only advisories known to the configured npm registry at execution time.
- Caret overrides allow future compatible releases when the lockfile is deliberately regenerated; `package-lock.json` remains the reproducibility boundary.
- The symlink escape test and Docker build require Linux CI for complete environment coverage.
- No live WhatsApp account or production MySQL instance was used. Compatibility is established through unchanged public APIs, TypeScript compilation, and controlled automated tests.
- The development-oriented Docker command (`npm run dev`) remains unchanged because production image redesign is outside E2.

## Reproduction

```powershell
cd worker-baileys
npm ci
npm audit --json
npm audit --omit=dev --json
npm ls follow-redirects music-metadata axios @whiskeysockets/baileys --all
npm test
npm run build

cd ..\backend-php
composer install --no-interaction --prefer-dist
composer audit --format=json
vendor\bin\phpunit.bat

cd ..
git diff --check
git status --short
```

On Linux CI, also run `docker build -t zapcore-worker:e2 worker-baileys` and confirm the symlink test executes rather than skips.
