# Security SSRF Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent user-controlled webhook and outbound-media URLs from reaching non-public services, including through redirects or DNS rebinding.

**Architecture:** Centralize URL and address validation in PHP, execute pinned cURL requests through one bounded client, and materialize remote media as existing `StoredMedia` before queueing. The Node worker accepts only local media for outbound media queue payloads and explicitly fails legacy remote-URL rows.

**Tech Stack:** PHP 8.2, ext-curl, ext-fileinfo, PHPUnit 11, Node.js 20, TypeScript, Baileys, Node test runner.

**Spec:** `docs/superpowers/specs/2026-10-23-security-ssrf-hardening-design.md`

## Global Constraints

- Preserve public API request fields and webhook HMAC behavior.
- Remote media is limited to exactly 16 MiB and at most three redirects.
- Webhooks follow no redirects.
- Validate every A and AAAA result and pin every effective cURL connection.
- Disable untrusted proxies and restrict protocols to HTTP/HTTPS.
- Do not rewrite existing data, deploy, merge, or modify the VPS.
- Preserve the primary checkout and its untracked `wiki-seed/` directory.

## Review Focus

- Internationalized, trailing-dot, mixed-case, and malformed hostnames fail consistently rather than bypassing canonical checks; covered in Task 1 URL-policy tests.
- A redirect that changes only port or scheme receives a fresh DNS decision and pinned connection; covered in Task 2 redirect tests.
- A response without `Content-Length`, or with a false small value, stops at the streaming byte limit; covered in Task 2 integration tests.
- Concurrent cleanup after a queue failure removes only the file created by that request; covered in Task 3 cleanup tests.
- A manipulated local payload containing both `local_media_path` and a remote URL cannot trigger network access; covered in Task 4 worker tests.

---

### Task 1: Canonical Public URL Policy

**Files:**
- Create: `backend-php/app/Services/Network/PublicUrlException.php`
- Create: `backend-php/app/Services/Network/ResolvedUrl.php`
- Create: `backend-php/app/Services/Network/PublicUrlPolicy.php`
- Test: `backend-php/tests/Unit/PublicUrlPolicyTest.php`

**Interfaces:**
- Produces: `PublicUrlPolicy::resolve(string $url): ResolvedUrl`
- Produces: `ResolvedUrl::{url,scheme,host,port,addresses}` and `resolveEntry(string $address): string`
- Produces: `PublicUrlException` with safe public messages.

- [ ] Write parameterized failing tests for HTTP/HTTPS acceptance; userinfo, non-HTTP schemes, invalid DNS, ambiguous IPv4, localhost, loopback, private, link-local, multicast, reserved, metadata, ULA, IPv4-mapped IPv6, and mixed public/private A+AAAA rejection.
- [ ] Run `C:\xampp\php\php.exe backend-php/vendor/bin/phpunit -c backend-php/phpunit.xml backend-php/tests/Unit/PublicUrlPolicyTest.php` and confirm failures are caused by missing policy classes.
- [ ] Implement strict parsing, injected A/AAAA resolution, canonical address checks, and immutable resolution output.
- [ ] Re-run the focused test and complete PHP suite; expect all tests to pass.
- [ ] Commit with `feat(security): add public URL policy`.

### Task 2: DNS-Pinned Bounded HTTP Client

**Files:**
- Create: `backend-php/app/Services/Network/HttpRequest.php`
- Create: `backend-php/app/Services/Network/HttpResponse.php`
- Create: `backend-php/app/Services/Network/HttpTransport.php`
- Create: `backend-php/app/Services/Network/CurlHttpTransport.php`
- Create: `backend-php/app/Services/Network/SafeHttpClient.php`
- Test: `backend-php/tests/Unit/SafeHttpClientTest.php`
- Test: `backend-php/tests/Integration/CurlHttpTransportTest.php`
- Create: `backend-php/tests/Support/LocalHttpServer.php`

**Interfaces:**
- Consumes: `PublicUrlPolicy::resolve()` and `ResolvedUrl::resolveEntry()` from Task 1.
- Produces: `SafeHttpClient::download(string $url, resource $target, int $maxBytes, int $maxRedirects = 3): HttpResponse`.
- Produces: `SafeHttpClient::postJson(string $url, string $body, array $headers, int $maxResponseBytes): HttpResponse`, which rejects redirects.

- [ ] Write failing unit tests for absolute/relative redirects, public-to-private redirect blocking, three-hop limit, resolution changes at each hop, no webhook redirects, and safe DNS/cURL failures.
- [ ] Write failing integration tests proving real cURL pinning, proxy suppression, protocol restriction, timeouts, header limits, and body streaming limits without Internet access.
- [ ] Run focused unit/integration tests and confirm expected RED failures.
- [ ] Implement request/response values, cURL transport, manual redirect resolution, one-resolution-per-hop pinning, and bounded write/header callbacks.
- [ ] Run focused tests and the complete PHP suite; expect all tests to pass.
- [ ] Commit with `feat(security): add pinned safe HTTP client`.

### Task 3: Materialize Remote Media Before Queueing

**Files:**
- Create: `backend-php/app/Services/RemoteMediaDownloadService.php`
- Modify: `backend-php/app/Services/MediaUploadService.php`
- Modify: `backend-php/app/Services/QueueService.php`
- Modify: `backend-php/app/Controllers/ApiController.php`
- Modify: `backend-php/app/Controllers/MessageController.php`
- Test: `backend-php/tests/Unit/RemoteMediaDownloadServiceTest.php`
- Test: `backend-php/tests/Unit/QueueRemoteMediaContractTest.php`
- Test: `backend-php/tests/Feature/MediaUploadApiTest.php`

**Interfaces:**
- Consumes: `SafeHttpClient::download()` from Task 2.
- Produces: `RemoteMediaDownloadService::download(string $url, int $instanceId, string $mediaType, ?string $fileName = null): StoredMedia`.
- Preserves: `QueueService::enqueueMediaTo(...)` public signature, now returning a queue backed by local media.

- [ ] Write failing tests for 16 MiB enforcement without trusting headers, MIME/type validation, temporary-file cleanup, promotion into outgoing storage, and orphan cleanup when queue creation throws.
- [ ] Add failing contract tests showing controllers keep `media_url` but queued payloads contain `local_media_path` and never contain the original URL.
- [ ] Run focused tests and confirm expected RED failures.
- [ ] Implement trusted temporary-file promotion in `MediaUploadService`, remote download orchestration, and queue cleanup boundaries.
- [ ] Run focused tests and the complete PHP suite; expect all tests to pass.
- [ ] Commit with `feat(security): materialize remote media before queueing`.

### Task 4: Close Worker Remote-Download Bypass

**Files:**
- Modify: `worker-baileys/src/media/LocalMediaResolver.ts`
- Modify: `worker-baileys/src/media/LocalMediaResolver.test.ts`
- Modify: `worker-baileys/src/queues/SendQueueWorker.ts`
- Test: `worker-baileys/src/queues/SendQueueWorker.test.ts`

**Interfaces:**
- Consumes: queue payloads from Task 3 containing `local_media_path`.
- Produces: permanent error code `UNSAFE_REMOTE_MEDIA` for legacy/manipulated outbound URL payloads.

- [ ] Write failing tests for image/audio/video/document URL payload rejection, mixed local+URL payload rejection, path traversal, symlink escape, and permanent classification of legacy rows.
- [ ] Run `npm test --prefix worker-baileys` and confirm expected RED failures at `resolveLocalMedia`, the real pre-Baileys consumption point.
- [ ] Implement fail-closed payload inspection and permanent queue failure handling without logging the unsafe URL.
- [ ] Run Node tests and `npm run build --prefix worker-baileys`; expect success.
- [ ] Commit with `fix(security): block remote media in worker queue`.

### Task 5: Harden Webhook Creation and Dispatch

**Files:**
- Modify: `backend-php/app/Controllers/WebhookController.php`
- Modify: `backend-php/app/Controllers/ApiController.php`
- Modify: `backend-php/app/Services/WebhookDispatcher.php`
- Test: `backend-php/tests/Unit/WebhookDispatcherTest.php`
- Test: `backend-php/tests/Feature/WebhookValidationTest.php`

**Interfaces:**
- Consumes: `PublicUrlPolicy::resolve()` and `SafeHttpClient::postJson()`.
- Preserves: HMAC SHA-256 over the exact JSON payload and `X-ZapCore-Signature`.
- Produces: bounded textual log bodies, generic blocked-destination errors, 30-day retention, and a per-webhook count ceiling.

- [ ] Write failing tests for controller validation, legacy private webhook blocking, DNS changes at dispatch, redirect rejection, signature preservation, no blocked response body, textual truncation/redaction, binary omission, and retention pruning.
- [ ] Run focused tests and confirm expected RED failures.
- [ ] Implement shared validation at both creation paths and safe dispatch with sanitized bounded logs.
- [ ] Run focused tests and the complete PHP suite; expect all tests to pass.
- [ ] Commit with `fix(security): harden webhook delivery`.

### Task 6: Documentation, Dependency Audit, and Final Verification

**Files:**
- Modify: `README.md`
- Modify: `docs/api.md`
- Modify: `docs/installation.md`
- Modify: `CHANGELOG.md`
- Modify if audit-safe: `worker-baileys/package-lock.json`

**Interfaces:**
- Documents the contracts and operational behavior produced by Tasks 1-5.

- [ ] Update documentation to explain receive-time URL downloads, the 16 MiB remote limit, redirect and public-network policy, legacy queue failure behavior, webhook restrictions, and residual inbound-Baileys risk.
- [ ] Run `npm audit fix --package-lock-only --prefix worker-baileys` only if it does not introduce a major dependency upgrade; inspect the resulting lock diff.
- [ ] Run PHPUnit, Node tests, TypeScript build, Composer audit, npm audit, and PHP syntax checks; record exact outcomes.
- [ ] Confirm `git diff --check`, inspect the complete branch diff, and verify the primary checkout still contains untouched `wiki-seed/`.
- [ ] Commit with `docs(security): document SSRF protections` or `chore(security): finalize SSRF hardening` as appropriate.
