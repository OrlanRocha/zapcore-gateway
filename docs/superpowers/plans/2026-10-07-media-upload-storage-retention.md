# Media Upload and Storage Retention Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add secure 256 MiB local media uploads to the dashboard and API, plus administrator-controlled storage monitoring and oldest-first retention.

**Architecture:** PHP validates and stores uploads below `storage/media/outgoing`, then queues a relative internal path or the existing remote URL. The worker resolves local paths inside the shared media root and builds the Baileys payload. A PHP retention service powers an administrator page, dry-run/manual actions, and a fixed system schedule that reads safe interval settings from the database.

**Tech Stack:** PHP 8.2 MVC, PDO/MySQL, vanilla JavaScript, Bootstrap, Font Awesome, Node.js 20, TypeScript, Baileys, PHPUnit, Node test runner, Docker Compose, cron/systemd, Postman JSON.

**Spec:** `docs/superpowers/specs/2026-10-07-media-upload-storage-retention-design.md`

## Global Constraints

- Exact application upload limit: 268,435,456 bytes (256 MiB).
- Keep JSON `media_url` requests backward compatible.
- Accept exactly one source: uploaded `media` file or `media_url`.
- Store files only below `backend-php/storage/media`; never trust client paths or MIME values.
- Retention deletes media files only, oldest first, and never deletes messages, database history, sessions, or pending/processing queue media.
- Percent mode cleans from threshold to target; absolute MB/GB mode has no percentage safety setting.
- Only administrators can view or mutate storage settings and trigger retention.
- Cron configuration is a constrained interval, never arbitrary shell input.
- Preserve unrelated working-tree content, including the separate `wiki-seed/` repository.
- Every completed implementation is documented, committed, pushed, tagged, released, and deployed after verification.

## Review Focus

- A request containing both a file and URL must fail with `422`, not select one silently; cover in Task 3 API tests.
- A declared image whose detected MIME is a document must fail and remove its temporary/staged file; cover in Task 2 tests.
- A 256 MiB file is accepted while 256 MiB plus one byte returns `413`; cover boundary behavior without allocating the whole file in Task 2 tests.
- Retention must not follow symlinks or delete paths outside the media root; cover canonical-path and symlink cases in Task 6 tests.
- Concurrent cron/manual cleanup must allow only one runner and leave an auditable skipped/locked result; cover in Task 6 tests.

---

### Task 1: Test Harness and Storage Schema

**Files:**
- Modify: `backend-php/composer.json`
- Modify: `backend-php/composer.lock`
- Create: `backend-php/phpunit.xml`
- Create: `backend-php/tests/bootstrap.php`
- Create: `backend-php/tests/Unit/StorageSchemaTest.php`
- Create: `backend-php/database/storage_retention_migration.sql`
- Modify: `backend-php/database/schema.sql`
- Modify: `docker-compose.yml`

**Interfaces:**
- Produces tables `storage_settings`, `storage_cleanup_runs`; adds `message_media.removed_at`, `removal_reason`, `storage_origin`, and a queue-safe media lookup index.
- Produces `composer test` as the canonical PHP test command.

- [ ] **Step 1: Add PHPUnit and the test bootstrap**

Add `phpunit/phpunit:^11` under `require-dev`, a `test` Composer script, autoload bootstrap, and isolated environment helpers without production credentials.

- [ ] **Step 2: Add a failing schema contract test**

Assert that the migration and base schema define the two settings/history tables, removed-media fields, supported interval constraint, percent/absolute modes, and `BIGINT` media sizes.

- [ ] **Step 3: Run the schema test and verify RED**

Run: `cd backend-php && vendor/bin/phpunit tests/Unit/StorageSchemaTest.php`

Expected: FAIL because the storage schema additions do not exist.

- [ ] **Step 4: Implement the migration and update the canonical schema**

Use idempotent SQL compatible with MariaDB 11/MySQL 8. Seed one settings row with disabled retention, percent threshold 80, target 75, and interval 60 minutes.

- [ ] **Step 5: Mount shared outgoing media consistently in Docker**

Keep PHP storage at its existing path and set worker `MEDIA_STORAGE_PATH=/app/storage/media`; document the named/bind volume responsibility in Compose.

- [ ] **Step 6: Run tests and verify GREEN**

Run: `cd backend-php && composer test`

Expected: all schema contract tests pass.

- [ ] **Step 7: Commit**

```bash
git add backend-php/composer.json backend-php/composer.lock backend-php/phpunit.xml backend-php/tests backend-php/database/storage_retention_migration.sql backend-php/database/schema.sql docker-compose.yml
git commit -m "test: add storage feature harness and schema"
```

### Task 2: Secure Upload Storage Service

**Files:**
- Create: `backend-php/app/Services/MediaUploadService.php`
- Create: `backend-php/app/Services/StoredMedia.php`
- Create: `backend-php/tests/Unit/MediaUploadServiceTest.php`
- Modify: `backend-php/app/Core/Request.php`

**Interfaces:**
- Produces `Request::getUploadedFile(string $field): ?array`.
- Produces `MediaUploadService::store(array $upload, int $instanceId, string $mediaType): StoredMedia`.
- Produces `MediaUploadService::remove(StoredMedia|string $relativePath): void`.
- `StoredMedia` exposes `relativePath`, `displayName`, `mimeType`, and `size`.

- [ ] **Step 1: Write failing upload tests**

Cover valid image/document, malformed PHP upload error, zero-byte file, MIME/type mismatch, executable rejection, randomized relative path, sanitized display name, exact 256 MiB boundary through injectable size metadata, 256 MiB + 1 returning a dedicated oversized exception, and rollback removal.

- [ ] **Step 2: Run the focused test and verify RED**

Run: `cd backend-php && vendor/bin/phpunit tests/Unit/MediaUploadServiceTest.php`

Expected: FAIL because service/value object/request upload accessor are absent.

- [ ] **Step 3: Implement request upload access and typed upload errors**

Read `$_FILES` without HTML sanitization, normalize the PHP shape, and map size violations to a distinct exception carrying HTTP `413` semantics.

- [ ] **Step 4: Implement streamed validation and random storage**

Use `finfo`, `is_uploaded_file` in production with an injectable move strategy for tests, `random_bytes` names, instance/date directories, canonical-root checks, and no full-file memory load.

- [ ] **Step 5: Run tests and verify GREEN**

Run: `cd backend-php && composer test`

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add backend-php/app/Core/Request.php backend-php/app/Services/MediaUploadService.php backend-php/app/Services/StoredMedia.php backend-php/tests/Unit/MediaUploadServiceTest.php
git commit -m "feat: store validated media uploads"
```

### Task 3: Queue and API Upload Contract

**Files:**
- Modify: `backend-php/app/Services/QueueService.php`
- Modify: `backend-php/app/Controllers/ApiController.php`
- Modify: `backend-php/app/Controllers/MessageController.php`
- Modify: `backend-php/app/Core/Response.php`
- Create: `backend-php/tests/Unit/QueueMediaPayloadTest.php`
- Create: `backend-php/tests/Feature/MediaUploadApiTest.php`

**Interfaces:**
- Produces `QueueService::enqueueStoredMediaTo(Instance $instance, string $to, string $mediaType, StoredMedia $media, ?string $caption, ?string $chatType): array`.
- Existing `enqueueMediaTo(...)` remains the remote URL path.
- API/web controllers accept exactly one of uploaded `media` or `media_url` and return `413` or `422` consistently.

- [ ] **Step 1: Write failing queue and endpoint tests**

Assert local payload metadata, message-media association, upload-only success, URL-only backward compatibility, both-source rejection, missing-source rejection, authorization/instance checks, caption behavior, and staged-file removal if enqueue fails.

- [ ] **Step 2: Run focused tests and verify RED**

Run: `cd backend-php && vendor/bin/phpunit tests/Unit/QueueMediaPayloadTest.php tests/Feature/MediaUploadApiTest.php`

Expected: FAIL because local queue method and multipart branches are absent.

- [ ] **Step 3: Implement local queue payload and media association atomically**

Store only relative internal paths in queue JSON. Insert `message_media` with `storage_origin='outgoing'` inside the same transaction as message/queue creation.

- [ ] **Step 4: Implement API and authenticated web multipart handling**

Share source validation between controllers, preserve JSON behavior, return the existing `201` shape for API and current web JSON success shape, and map upload size to `413`.

- [ ] **Step 5: Run tests and verify GREEN**

Run: `cd backend-php && composer test`

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add backend-php/app/Services/QueueService.php backend-php/app/Controllers/ApiController.php backend-php/app/Controllers/MessageController.php backend-php/app/Core/Response.php backend-php/tests
git commit -m "feat: accept uploaded media in chat and API"
```

### Task 4: Worker Local Media Resolution

**Files:**
- Create: `worker-baileys/src/media/LocalMediaResolver.ts`
- Create: `worker-baileys/src/media/LocalMediaResolver.test.ts`
- Modify: `worker-baileys/src/queues/SendQueueWorker.ts`
- Modify: `worker-baileys/package.json`
- Modify: `worker-baileys/package-lock.json`
- Modify: `worker-baileys/tsconfig.json`

**Interfaces:**
- Produces `resolveLocalMedia(payload: QueueMediaPayload, storageRoot: string): Promise<ResolvedMediaPayload>`.
- Consumes `local_media_path`, `mime_type`, `file_name`, and media type from Task 3 queue JSON.
- Returns a Baileys payload containing a bounded `Buffer`; URL payloads pass through unchanged.

- [ ] **Step 1: Write failing Node tests**

Use the Node test runner to cover image/document conversion, missing file, directory input, traversal, absolute path, symlink escape, and 256 MiB + 1 rejection using filesystem metadata without allocating the file.

- [ ] **Step 2: Run tests and verify RED**

Run: `cd worker-baileys && npm test -- LocalMediaResolver.test.ts`

Expected: FAIL because resolver and test script are absent.

- [ ] **Step 3: Implement the resolver and queue integration**

Resolve real paths beneath `MEDIA_STORAGE_PATH`, reject non-regular files and oversize files before reading, map metadata to the selected Baileys payload, and preserve remote URL payload behavior.

- [ ] **Step 4: Run tests, audit, and build**

Run: `cd worker-baileys && npm test && npm audit && npm run build`

Expected: tests pass, zero vulnerabilities, TypeScript exits zero.

- [ ] **Step 5: Commit**

```bash
git add worker-baileys/src/media worker-baileys/src/queues/SendQueueWorker.ts worker-baileys/package.json worker-baileys/package-lock.json worker-baileys/tsconfig.json
git commit -m "feat: send media from shared local storage"
```

### Task 5: Chat Composer Modes and Drag-and-Drop

**Files:**
- Modify: `backend-php/app/Views/instances/show.php`
- Modify: `backend-php/public/css/style.css`
- Create: `backend-php/public/js/chat-media-composer.js`
- Create: `backend-php/tests/Unit/ChatComposerMarkupTest.php`

**Interfaces:**
- Produces `window.ChatMediaComposer` initialization for mode, file, URL, drag/drop, validation, FormData submit, reset, and accessible state updates.
- Consumes the multipart web endpoint from Task 3.

- [ ] **Step 1: Write failing markup contract tests**

Assert five mode buttons, hidden file input, labeled drop zone, 256 MB copy, selected-file region, URL alternative, live error/status region, and external script inclusion.

- [ ] **Step 2: Run the focused test and verify RED**

Run: `cd backend-php && vendor/bin/phpunit tests/Unit/ChatComposerMarkupTest.php`

Expected: FAIL against the current select/URL-only composer.

- [ ] **Step 3: Implement the semantic composer markup and restrained styles**

Reuse current typography/palette and Font Awesome icons. Maintain stable button/drop-zone dimensions, visible focus, mobile wrapping, reduced motion, and no nested cards.

- [ ] **Step 4: Implement composer behavior**

Use `FormData` for uploaded media, JSON for text/URL where compatible, mutual exclusion, file type/size preflight, drag events, disabled upload state, progress text, error handling, and reset after queue success.

- [ ] **Step 5: Run PHP tests and browser verification**

Run: `cd backend-php && composer test`.

Then use Playwright at desktop and mobile widths to verify click selection, drag/drop, mode switching, URL fallback, keyboard focus, no overflow, and existing chat refresh behavior.

Expected: tests pass and screenshots show no overlap or clipped controls.

- [ ] **Step 6: Commit**

```bash
git add backend-php/app/Views/instances/show.php backend-php/public/css/style.css backend-php/public/js/chat-media-composer.js backend-php/tests/Unit/ChatComposerMarkupTest.php
git commit -m "feat: add media modes and drag-and-drop upload"
```

### Task 6: Storage Metrics and Retention Engine

**Files:**
- Create: `backend-php/app/Services/StorageMetricsService.php`
- Create: `backend-php/app/Services/MediaRetentionService.php`
- Create: `backend-php/app/Models/StorageSetting.php`
- Create: `backend-php/app/Models/StorageCleanupRun.php`
- Create: `backend-php/tests/Unit/StorageMetricsServiceTest.php`
- Create: `backend-php/tests/Unit/MediaRetentionServiceTest.php`
- Modify: `backend-php/app/Controllers/ApiController.php`
- Modify: `backend-php/app/Controllers/MessageController.php`

**Interfaces:**
- Produces `StorageMetricsService::snapshot(): array` with partition/media bytes, percentage, and file count.
- Produces `MediaRetentionService::run(string $trigger, bool $dryRun, ?int $userId): array`.
- Produces settings validation for percent and absolute modes.
- Media list/download responses expose `media_removed`, `media_removed_at`, and no URL when removed.

- [ ] **Step 1: Write failing calculation and deletion tests**

Cover percent threshold/target bytes, absolute limit bytes, no-op below limit, oldest-first order, pending/processing exclusion, missing files, canonical-root rejection, symlink escape, partial failure, dry-run, and advisory-lock contention.

- [ ] **Step 2: Run focused tests and verify RED**

Run: `cd backend-php && vendor/bin/phpunit tests/Unit/StorageMetricsServiceTest.php tests/Unit/MediaRetentionServiceTest.php`

Expected: FAIL because services/models are absent.

- [ ] **Step 3: Implement metrics and validated settings models**

Use `disk_total_space`, `disk_free_space`, streamed directory iteration, byte units stored as integers, and exact allowed intervals `[5,15,30,60,360,1440]`.

- [ ] **Step 4: Implement locked retention execution**

Use a named MySQL advisory lock, select eligible records oldest first in bounded batches, verify each real path before unlink, mark removed metadata, and write complete/partial/skipped/failed run history.

- [ ] **Step 5: Update media reads for retention state**

Return `410 Gone` for an explicitly removed media download and render a stable removed marker in chat/API listings rather than a broken URL.

- [ ] **Step 6: Run tests and verify GREEN**

Run: `cd backend-php && composer test`

Expected: all tests pass.

- [ ] **Step 7: Commit**

```bash
git add backend-php/app/Services/StorageMetricsService.php backend-php/app/Services/MediaRetentionService.php backend-php/app/Models/StorageSetting.php backend-php/app/Models/StorageCleanupRun.php backend-php/app/Controllers/ApiController.php backend-php/app/Controllers/MessageController.php backend-php/tests
git commit -m "feat: add storage metrics and media retention"
```

### Task 7: Administrator Storage Panel and Scheduler

**Files:**
- Create: `backend-php/app/Controllers/StorageController.php`
- Create: `backend-php/app/Views/storage/index.php`
- Create: `backend-php/bin/storage-retention.php`
- Create: `backend-php/tests/Feature/StorageControllerTest.php`
- Create: `backend-php/tests/Unit/StorageRetentionCommandTest.php`
- Modify: `backend-php/routes/web.php`
- Modify: `backend-php/app/Views/layouts/app.php`
- Modify: `backend-php/public/css/style.css`
- Create: `deploy/zapcore-storage-retention.cron`
- Modify: `docker-compose.yml`

**Interfaces:**
- Adds admin routes `GET /storage`, `POST /storage/settings`, `POST /storage/dry-run`, and `POST /storage/cleanup`.
- Produces CLI exit codes: `0` completed/no-op, `2` locked/skipped, `1` failed.
- Consumes Task 6 metrics, settings, and retention service.

- [ ] **Step 1: Write failing authorization, validation, and command tests**

Assert non-admin denial, percent target lower than threshold, absolute mode ignores/rejects percentage fields, allowed intervals only, dry-run non-destructive behavior, manual audit user, disabled/not-due CLI no-op, and due CLI execution.

- [ ] **Step 2: Run focused tests and verify RED**

Run: `cd backend-php && vendor/bin/phpunit tests/Feature/StorageControllerTest.php tests/Unit/StorageRetentionCommandTest.php`

Expected: FAIL because routes/controller/CLI are absent.

- [ ] **Step 3: Implement controller, routes, and navigation**

Apply `AuthMiddleware` plus `AdminMiddleware`, existing CSRF behavior, server-side validation, JSON responses for actions, and Post/Redirect/Get for settings if the existing view pattern requires it.

- [ ] **Step 4: Implement the storage page**

Show partition/media metrics, configuration, next/last execution, manual/dry-run actions, and recent history. Dynamically show the safety target only in percent mode; absolute mode shows MB/GB and no percentage safety field.

- [ ] **Step 5: Implement CLI and stable scheduler definitions**

The cron/system scheduler calls the PHP command every five minutes; the command reads enabled/due settings. Docker adds a scheduler service using the same command and no arbitrary cron input.

- [ ] **Step 6: Run tests and browser verification**

Run: `cd backend-php && composer test`.

Use Playwright to verify admin access, validation, mode-dependent fields, dry-run result, manual action confirmation, history rendering, mobile layout, and non-admin denial.

- [ ] **Step 7: Commit**

```bash
git add backend-php/app/Controllers/StorageController.php backend-php/app/Views/storage/index.php backend-php/bin/storage-retention.php backend-php/tests backend-php/routes/web.php backend-php/app/Views/layouts/app.php backend-php/public/css/style.css deploy/zapcore-storage-retention.cron docker-compose.yml
git commit -m "feat: add storage administration and retention schedule"
```

### Task 8: Platform Limits and Documentation

**Files:**
- Modify: `php.ini`
- Modify: `README.md`
- Modify: `docs/api.md`
- Modify: `docs/installation.md`
- Modify: `docs/ZapCore-Gateway.postman_collection.json`
- Modify: `backend-php/.env.example`
- Modify: `worker-baileys/.env.example`
- Create: `backend-php/tests/Unit/PostmanMediaUploadContractTest.php`

**Interfaces:**
- Documents multipart field name `media`, exact 256 MiB application limit, JSON URL compatibility, retention modes, scheduler installation, and production Nginx `client_max_body_size 260M` requirement.

- [ ] **Step 1: Write a failing Postman/documentation contract test**

Assert the collection has both JSON URL and multipart upload requests, required fields, file field, and representative `413`/`422` behavior documentation.

- [ ] **Step 2: Run the test and verify RED**

Run: `cd backend-php && vendor/bin/phpunit tests/Unit/PostmanMediaUploadContractTest.php`

Expected: FAIL because multipart examples and retention docs are absent.

- [ ] **Step 3: Update PHP/environment limits and documentation**

Set `upload_max_filesize=256M`, `post_max_size=260M`; add media root configuration; explain host Nginx setting, migration, cron/systemd/Docker schedule, cleanup semantics, and 410 removed-media behavior.

- [ ] **Step 4: Update Postman collection**

Keep URL examples and add multipart image/document examples using Postman file fields without embedding local machine paths.

- [ ] **Step 5: Run contracts and JSON validation**

Run: `cd backend-php && composer test` and parse the Postman JSON with PHP or Node.

Expected: tests pass and collection parses successfully.

- [ ] **Step 6: Commit**

```bash
git add php.ini README.md docs/api.md docs/installation.md docs/ZapCore-Gateway.postman_collection.json backend-php/.env.example worker-baileys/.env.example backend-php/tests/Unit/PostmanMediaUploadContractTest.php
git commit -m "docs: document media uploads and storage retention"
```

### Task 9: Full Verification, Release, and Production Deployment

**Files:**
- Modify: `VERSION`
- Modify: `CHANGELOG.md`
- Modify: `worker-baileys/package.json`
- Modify: `worker-baileys/package-lock.json`
- Modify: `README.md`

**Interfaces:**
- Produces the next SemVer release, Git tag, GitHub Release, migrated production database, configured Nginx upload limit, installed scheduler, and deployed application.

- [ ] **Step 1: Run the complete local verification suite**

Run PHP tests/lint, Node tests/build/audit, Postman JSON parse, `git diff --check`, and Playwright desktop/mobile upload/storage flows.

Expected: zero failures, zero production/development dependency vulnerabilities, no layout regressions.

- [ ] **Step 2: Prepare the release metadata**

Record upload/API/retention changes in `CHANGELOG.md`, update README current version, and bump canonical/worker versions with `scripts/release.ps1` or equivalent verified steps.

- [ ] **Step 3: Commit, tag, push, and publish GitHub Release**

Use `chore(release): vX.Y.Z`, annotated tag `vX.Y.Z`, push `main` and tag, and publish notes covering schema migration, 256 MB limits, API compatibility, scheduler, security, and rollback.

- [ ] **Step 4: Back up and migrate production**

Create a recoverable code/config/database backup, validate target paths, apply the idempotent storage migration, configure Nginx 260M body limit, install/enable the scheduler, and deploy the exact release tag while preserving `.env` and media.

- [ ] **Step 5: Build and restart only affected services**

Install Composer and Node dependencies from locks, run production audits/builds, reload validated Nginx, restart worker/PHP only as required, and verify scheduler ownership/permissions.

- [ ] **Step 6: Run production smoke tests**

Verify services, version, database, HTTP/login/PWA, instance reconnect, small multipart upload and URL send queueing, a controlled file near boundary validation without sending it, storage page metrics, retention dry-run, scheduler no-op/due logic, logs, and external HTTP response.

- [ ] **Step 7: Report evidence and residual limits**

Provide release URL, commit/tag, backup location, migration result, test/audit counts, production version/services, upload/retention smoke results, and note that WhatsApp may enforce limits lower than ZapCore's 256 MiB acceptance.
