# E3 - Docker networking and secrets hardening

## Scope

E3 hardens the repository configuration only. It does not deploy, access the VPS, rotate real credentials, recreate volumes, modify MySQL data, or touch WhatsApp sessions.

Base: `a6658c3f02de9f7deebb3ed4f901e13765ca2fa9`
Branch: `codex/security-docker-secrets-e3`

## Confirmed previous risks

- MySQL published `3306` on all host interfaces.
- Redis published `6379` on all host interfaces and had no authentication.
- The Compose file and examples contained predictable passwords and shared-secret placeholders.
- PHP and Node accepted unsafe defaults or empty credentials.
- Every container shared one bridge network.
- Fixed `container_name` values prevented isolated Compose test projects.
- Redis existed in Compose but no PHP or Node consumer was found.

## Implemented topology

| Service | Ingress | Application | Data | Host publication | Internet egress |
| --- | --- | --- | --- | --- | --- |
| PHP | yes | yes | yes | `8080:80` | yes |
| Baileys worker | no | yes | yes | none; exposes 3333 internally | yes through `application` |
| Scheduler | no | yes | yes | none | required by its current runtime extension installation |
| MySQL | no | no | yes | none by default | no |
| Redis | no | no | yes | none | no |

The `data` network is internal. The `application` network is deliberately not internal so Baileys can reach WhatsApp, DNS, HTTPS APIs, and approved webhook destinations. The PHP endpoint remains published on port 8080 for compatibility.

Optional MySQL administration requires explicitly adding `docker-compose.mysql-admin.yml`. It binds only `127.0.0.1:${MYSQL_ADMIN_PORT:-3306}:3306`.

Redis is behind profile `redis` because no application consumer currently exists. If enabled, a root bootstrap copies the read-only ACL secret into a container-local `0400` file owned by `redis`, then immediately drops privileges through `su-exec`. The default user remains disabled.

## Secret contract

Compose reads secret source paths from `.env` and mounts files under `/run/secrets`. The official MySQL image consumes `MYSQL_PASSWORD_FILE` and `MYSQL_ROOT_PASSWORD_FILE`. PHP and Node implement equivalent `_FILE` support.

The loaders reject:

- a missing value;
- an empty or whitespace-only value;
- simultaneous direct and `_FILE` values;
- a missing, non-regular, or unreadable file;
- known repository placeholders.

Errors include only the variable name. Secret contents are never included. Source files should be owned by the deployment operator with mode `0600`; service mounts request mode `0440`. Compose standalone may not honor `uid`, `gid`, or `mode` for file-backed secrets, so effective permissions must be inspected during deployment. Administrators with Docker or root access can still read container environment and mounted data; Compose Secrets do not protect against host administrators.

## Persistence and compatibility

The Compose key remains exactly `zapcore_db_data` and the mount remains `/var/lib/mysql`. Existing initialization scripts and media bind mounts are preserved. The effective Docker volume name still depends on the Compose project name; production must reuse its current project name.

Changing `MYSQL_PASSWORD_FILE` or `MYSQL_ROOT_PASSWORD_FILE` does not change credentials inside an already initialized MySQL data directory. Rotation must follow the separate runbook.

No E1 remote-media, webhook, URL validation, queue, or worker media code changed. E2 npm overrides and deterministic `npm ci` remain present.

## Validation

Automated tests cover secret loading, invalid files, placeholders, Compose ports, networks, volume key, service membership, file secrets, Redis profile/ACL, and loopback administration. `scripts/validate-compose-linux.sh` performs rendered Compose, build, isolated startup, PHP/worker database connectivity, persistence restart, Redis unauthenticated rejection, and published-port checks.

Local results on 2026-10-24:

- Node: 28 discovered, 26 passed, 0 failed, 2 skipped because Windows cannot enforce the Linux symlink/permission scenarios.
- TypeScript: build passed.
- PHPUnit: 94 tests and 264 assertions passed.
- npm audit, including production-only audit: zero known vulnerabilities.
- Composer audit: zero advisories and zero abandoned packages.
- Shell syntax: Redis entrypoint and Linux Compose validation script passed `bash -n`.

The audit discovered new advisories after the E2 baseline. E3 minimally resolves `body-parser` to `1.20.9` and the test-only YAML parser to `2.8.3`; the E1/E2 floors remain `follow-redirects@1.16.1` and `music-metadata@11.16.1`.

Docker is unavailable on the implementation host. Runtime Compose validation, image builds, Linux secret permissions, and the symlink test remain pending in Linux CI and must not be treated as locally approved.

## E5 and E6 dependencies

- E5 should separate migration privileges from the runtime application user. E3 keeps the existing application database identity to avoid an unplanned privilege migration.
- E6 should replace runtime package installation and `npm run dev` with production images. E3 intentionally preserves these build/runtime behaviors.

## Residual risks

- Source secret file permissions and Compose mount modes require deployment-host verification.
- The application database user may still possess schema privileges inherited from the current deployment until E5.
- Runtime images still install or run development tooling until E6.
- Redis authenticated-client behavior requires Linux Docker validation before enabling the profile.
