# E3 production migration and credential rotation runbook

This runbook is a future authorized operation. Do not execute it as part of E3.

## Stop conditions

Stop before changing anything if the active Compose project, MySQL volume, backup destination, current credentials, maintenance owner, or tested restore procedure cannot be positively identified. Stop after each phase if health, queue depth, sessions, free disk, or backup evidence differs from the recorded baseline.

## 1. Inventory

1. Record Docker Engine and Compose versions.
2. Identify the exact Compose files, project name, working directory, and environment files used by production.
3. Record `docker compose ps`, container image digests, networks, port bindings, and health states.
4. Record `docker volume inspect` for the effective volume backing `zapcore_db_data`; do not infer its name from this repository.
5. Record media and Baileys session paths, owners, permissions, sizes, and filesystem free space.
6. Record queue counts by status, connected instance UUIDs, and retention scheduler state without exporting message content or secrets.
7. Identify current application, root, and any migration/admin database identities and grants.

## 2. Backup and restoration proof

1. Take a transactionally consistent MySQL backup using the currently authorized account.
2. Back up Compose configuration, secret files through an approved secret channel, media, and Baileys sessions separately.
3. Record checksums, timestamps, encryption method, owner, and retention location.
4. Restore the MySQL backup into an isolated non-production instance and run schema/table-count checks.
5. Restore representative media/session metadata without connecting the copied sessions to WhatsApp.
6. Stop if restoration is not proven before the maintenance window.

## 3. Prepare secret files

1. Archive legacy `backend-php/.env` and `worker-baileys/.env` files outside the deployment tree, then remove direct secret assignments from the deployment copies. Abort if any direct/`*_FILE` pair coexists for `APP_KEY`, `INTERNAL_SECRET`, `WORKER_SECRET`, `PHP_INTERNAL_SECRET`, or `DB_PASSWORD`.
2. Generate independent high-entropy values for app key, internal secret, worker secret, MySQL application password, MySQL root password, and Redis ACL credentials if Redis will be enabled.
3. Store each value in its own root-owned file outside Git; apply mode `0600` and restrict the parent directory.
4. Create the deployment `.env` with only non-secret values and absolute secret-file paths.
5. Run `docker compose config` and inspect the rendered services, networks, volumes, secrets, and host bindings. Do not print secret files.
6. Verify the effective volume name matches the inventory exactly. Stop on any mismatch.

## 4. Rotate initialized MySQL credentials

Changing `MYSQL_*_FILE` alone has no effect on users stored in an initialized volume.

1. Keep the old credentials available in the approved recovery channel until validation completes.
2. Connect using the currently working administrative identity over a local or Docker-internal path.
3. Verify server UUID, database name, expected tables, and effective data directory before issuing any account change.
4. Rotate the application account with `ALTER USER` to the newly generated application password.
5. Test a new independent connection with the new application credential before closing the administrative session.
6. Rotate the root/admin credential only after the application credential is proven and recovery access is confirmed.
7. Do not change grants during E3. Record runtime grants for the E5 least-privilege migration.

## 5. Coordinated consumer update

1. Update the secret files atomically while preserving ownership and mode.
2. Re-render Compose and confirm MySQL/Redis still have no default host ports.
3. Recreate only PHP, worker, scheduler, and MySQL containers as required; never run `docker compose down -v` and never recreate the data volume.
4. Preserve the original Compose project name.
5. Start MySQL first and wait for readiness, then PHP and scheduler, then the worker.

## 6. Validation gates

1. PHP connects to MySQL and its health/API endpoint responds without leaking configuration.
2. Worker connects to MySQL and PHP, its health endpoint is healthy, and no reconnect loop appears.
3. Scheduler performs a dry run or remains correctly disabled according to stored settings.
4. Queue counts and states match the pre-change baseline; process a controlled test message only with authorization.
5. Existing WhatsApp instances reconnect without deleting or recreating session files.
6. Validate authorized text and media send/receive, E1 local-media rules, and webhook delivery.
7. Confirm `ss`/firewall and `docker compose port` show no public 3306 or 6379 binding.
8. If Redis is enabled, verify unauthenticated commands fail and the intended ACL user succeeds.
9. Monitor authentication failures, disk, queue age, and reconnect events through the agreed observation window.

## 7. Rollback

Rollback is coordinated, not automatic.

- **MySQL authentication failure:** keep the active administrative session open; restore the previous application password with `ALTER USER`, restore previous secret files atomically, and recreate only consumers.
- **PHP/worker/scheduler connection failure:** stop the affected consumer, verify its mounted secret and non-secret DB settings, restore the prior secret file if needed, and restart only that consumer.
- **Redis startup failure:** disable the optional `redis` profile; no current application component depends on it.
- **Queue or WhatsApp regression:** stop the worker before further queue changes, preserve sessions and queue rows, restore the prior worker container/configuration, then verify reconnect and queue state.
- **Volume mismatch:** stop immediately before starting MySQL. Restore the original project name/Compose selection; never initialize a replacement volume as a shortcut.
- **Data inconsistency:** stop all writers, retain evidence, and follow the tested database restore procedure under the incident owner. Do not improvise destructive SQL.

Retire previous credentials only after all validation gates and the observation window pass, and record who authorized retirement.
