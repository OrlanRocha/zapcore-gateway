# E3 Docker and Secrets Security Design

## Intent

Harden the repository's Docker topology and credential loading without changing application behavior, production data, WhatsApp sessions, or the effective MySQL volume identity.

## Goals

- Remove default host publication of MySQL and Redis.
- Separate ingress, application, and data traffic while preserving worker Internet egress.
- Load sensitive values from read-only Compose secrets with fail-fast validation in PHP and Node.
- Keep Redis disabled unless explicitly selected and require a read-only Redis 7 ACL file when enabled.
- Preserve the E1 SSRF controls, E2 dependency controls, media storage, queues, sessions, and retention scheduler.
- Provide static policy tests and a Linux CI/runtime validation script.
- Document a reversible production migration and credential rotation procedure without executing it.

## Non-goals

- No deployment, VPS access, credential rotation, database migration, volume recreation, production image redesign, or E5 migration-user refactor.
- No new external secret manager, orchestrator, Vault, Kubernetes, or Swarm dependency.
- No Redis integration where none currently exists.

## Topology

- `ingress`: `php-app` only; publishes HTTP `8080:80` for compatibility.
- `application`: `php-app` and `baileys-worker`; non-internal so the worker retains WhatsApp/DNS/HTTPS egress.
- `data`: `php-app`, `baileys-worker`, `storage-scheduler`, MySQL, and optional Redis; marked internal.
- The scheduler also joins `application` temporarily because its unchanged E6-bound startup installs `pdo_mysql` from external package repositories.
- MySQL and Redis expose container ports only to Docker networks and publish no host ports in the base Compose file.
- An explicit `docker-compose.mysql-admin.yml` override binds `${MYSQL_ADMIN_PORT:-3306}:3306` to `127.0.0.1` only.
- Redis uses profile `redis`; no service starts it by default because the current code has no Redis consumer.

## Secrets

- Compose secrets are backed by files below `secrets/`, which is ignored except for non-secret templates in `secrets/examples/`.
- MySQL consumes `MYSQL_PASSWORD_FILE` and `MYSQL_ROOT_PASSWORD_FILE`, supported by the official MySQL image entrypoint.
- PHP and Node accept direct variables for non-container development and corresponding `*_FILE` variables for Compose.
- A value and its `_FILE` form are mutually exclusive. Missing, empty, non-regular, unreadable, or whitespace-only secret files fail startup with an error naming only the variable, never its value or path content.
- Required production secrets are `APP_KEY`, `INTERNAL_SECRET`, `WORKER_SECRET`, and `DB_PASSWORD`; the Compose contract supplies all four through files.
- Redis receives an ACL file as a read-only secret and starts with `--aclfile`; the template disables the default user and contains an intentionally invalid placeholder credential.

## Persistence

- The Compose volume key remains exactly `zapcore_db_data:` and its MySQL mount remains `/var/lib/mysql`.
- Existing bind mounts for media, application files, schema initialization, and retention remain present.
- Operators must retain the existing Compose project name during migration; changing only `MYSQL_*_FILE` does not rotate users in an initialized MySQL volume.

## Validation

- PHP unit tests cover direct values, `_FILE` values, conflicts, and missing/empty/unreadable files.
- Node tests cover the same contract and production configuration validation without logging secret data.
- Compose policy tests parse the YAML structure and assert networks, ports, secrets, profiles, volumes, and service connectivity.
- Linux CI renders Compose with safe fixture secrets, runs the same policy against rendered JSON, builds images, starts an isolated project, checks connectivity and published ports, and removes only its dedicated resources.
- Docker runtime checks remain pending on hosts without Docker and are never reported as passed.

## Operational Migration

The runbook inventories the active Compose project and real volume names, requires a consistent backup plus restoration test, prepares restricted secret files, rotates MySQL credentials inside the initialized database, updates consumers together, recreates only containers, validates queues/sessions/media, and defines stop/rollback gates.
