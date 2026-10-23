# SSRF Hardening Design

## Intent

Eliminate server-side request forgery in user-controlled webhook and outbound remote-media URLs while preserving the public API and valid public HTTPS workflows.

## Goals

- Accept only canonical HTTP/HTTPS destinations whose complete A and AAAA result set is public.
- Pin each validated request to one of the validated addresses so DNS cannot change between policy evaluation and connection.
- Download outbound remote media in PHP before queue creation, with at most three manually validated redirects and a 16 MiB streaming limit.
- Make the worker consume only local media for queued outbound media.
- Validate webhooks both when created and when dispatched, with no redirects.
- Preserve webhook HMAC signatures and sanitize, bound, and retain delivery logs safely.
- Fail closed on malformed URLs, DNS failures, cURL failures, oversized bodies, unsupported MIME types, and legacy remote-media queue items.

## Non-Goals

- Changing inbound WhatsApp media downloads performed by Baileys; those URLs originate from WhatsApp protocol messages rather than application users.
- Migrating or rewriting existing messages and queue rows.
- Deploying, merging, or modifying the VPS.
- Building a general-purpose HTTP client for unrelated internal service calls configured by administrators.

## Constraints

- Preserve the `POST /api/messages/media` and web-panel `media_url` contracts.
- Preserve image, audio, video, and document delivery using the existing `StoredMedia` and local-media worker path.
- Preserve untracked `wiki-seed/` in the primary checkout.
- Tests must not depend on the public Internet.
- Use PHP 8.2, ext-curl, PHPUnit 11, Node 20, TypeScript, and the existing Node test runner.

## Approaches Considered

### Selected: PHP download before queueing

PHP validates and downloads remote media into controlled storage, then queues the existing local-media payload. This leaves one security boundary and prevents Baileys from independently resolving or redirecting a user URL.

### Rejected: duplicate policy in PHP and Node

This retains direct worker downloads but duplicates CIDR, DNS, redirect, timeout, and streaming rules across languages. The two implementations can drift and expose the same URL differently.

### Rejected: internal media proxy

The worker would stream media through PHP at send time. This keeps the queue dependent on a live second HTTP transfer and creates a larger internal API and authentication surface.

## Architecture

### Public URL policy

`PublicUrlPolicy` parses and canonicalizes URLs, rejects userinfo and ambiguous IP forms, resolves all A and AAAA records, rejects the destination when any address is non-public, and returns an immutable `ResolvedUrl` containing the canonical URL, hostname, port, and validated addresses. Literal IPv4 and IPv6 hosts pass through the same address policy. Resolution is injectable for deterministic tests.

### Safe HTTP client

`SafeHttpClient` consumes `ResolvedUrl`, disables environment proxies and automatic redirects, limits protocols to HTTP/HTTPS, pins cURL with `CURLOPT_RESOLVE`, and applies connection, total, header, and streaming body limits. Media redirects are handled manually by the caller-facing download method and every absolute or relative `Location` is resolved and revalidated. Webhook POST requests reject every redirect.

Tests use an injected transport for policy and redirect cases. A local integration server is reached only through a test policy result that pins a public test hostname to loopback, proving the real cURL request honors the pinned address without weakening production policy.

### Remote media

`RemoteMediaDownloadService` streams at most 16 MiB into a temporary file, verifies the detected MIME against the requested media type, promotes the file through `MediaUploadService`, and always removes temporary files. `QueueService::enqueueMediaTo()` downloads first and invokes the existing stored-media queue path. If queueing fails, it removes the promoted file to avoid orphans.

### Worker boundary and legacy queue rows

`resolveLocalMedia()` rejects every outbound media payload containing a remote URL when `local_media_path` is absent. The error uses a stable permanent-failure code. Existing legacy queue items are marked failed with a clear safe error and reported through the existing status callback; they are never downloaded and never silently discarded.

### Webhooks

Both webhook controllers validate destinations at creation. `WebhookDispatcher` validates again on every delivery, covering legacy rows and DNS changes. Delivery uses the safe client with redirects disabled and the existing HMAC header. Blocked requests store no response body. Authorized response bodies are accepted only for textual content, redacted, stripped of control characters, and truncated. Old logs are pruned by age and per-webhook count.

## Error Behavior

Public API and panel validation failures return safe client-facing messages without IP lists, response bodies, secrets, or cURL internals. Legacy webhook blocks produce a failed delivery log containing a generic policy error. Legacy remote-media rows become permanent failures with a migration-oriented message.

## Security Boundaries

- User-controlled remote URLs terminate in PHP and never reach Baileys.
- DNS results are policy input; the cURL connection uses only a pinned validated result.
- Every redirect is a new policy decision.
- Configured PHP-to-worker URLs remain internal configuration and are outside the user-controlled URL policy.
- Inbound WhatsApp media remains a Baileys protocol concern and is documented as a residual dependency risk.

## Verification

Unit tests cover URL syntax, IPv4/IPv6 ranges, mixed DNS answers, rebinding, redirect resolution, redirect limits, MIME, limits, cleanup, webhook legacy rows, signatures, and worker bypass attempts. Integration tests cover the real cURL pinning and streaming limit with local servers. The complete PHPUnit and Node suites, TypeScript build, Composer audit, and npm audit form the final gate.
