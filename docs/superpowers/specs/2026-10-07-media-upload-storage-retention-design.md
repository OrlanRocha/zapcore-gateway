# Media Upload and Storage Retention Design

## Objective

Allow dashboard and API clients to send WhatsApp media from a local upload
without requiring a public URL, while preserving URL-based sends. Add an
administrator-controlled storage panel and automatic retention so media cannot
fill the server disk.

## Scope

- Replace the chat media select with mode buttons for text, image, video,
  audio, and document.
- Add click-to-upload and drag-and-drop media selection to the instance chat.
- Accept uploads up to 256 MB in the web chat and public API.
- Keep the existing remote `media_url` flow.
- Store uploaded media outside the public web root and send it through the
  shared worker storage.
- Add administrator storage metrics, retention settings, dry-run, manual
  cleanup, cron execution, and execution history.
- Retain message records when their media file is removed.

## Non-goals

- Cloud or object storage providers.
- Restoring media after retention deletes it.
- Deleting messages, users, instances, WhatsApp sessions, or database rows as
  part of storage retention.
- Changing WhatsApp's own per-media limits. A file accepted by ZapCore can
  still be rejected by WhatsApp and will follow normal queue failure handling.

## User Interface

### Chat composer

The composer uses a compact segmented button row with familiar icons and text:
Text, Image, Video, Audio, and Document. Text mode shows the existing message
field. A media mode additionally shows:

- a drop zone that opens the native file picker when clicked;
- selected file name, MIME/type, and formatted size;
- a remove-file icon button;
- an alternative URL field;
- caption text for image, video, and document where supported;
- the existing send command.

The file and URL inputs are mutually exclusive. Selecting a file clears the
URL; entering a URL clears the file selection after user confirmation is not
needed because no upload has occurred yet. Drag-over, selected, uploading,
queued, validation-error, and disabled states must be visually distinct and
keyboard accessible.

The control follows the current quiet operational style. It reuses the current
palette and type, uses existing Font Awesome icons, keeps radii at or below the
current component radius, and avoids adding decorative cards.

### Storage administration

An administrator-only Storage page displays:

- partition capacity, used, free, and used percentage;
- media directory size and file count;
- outgoing and incoming media totals when available;
- retention status, last execution, next expected execution, and last result;
- recent cleanup history.

Controls include enabled/disabled, limit mode, threshold, cron interval,
percentage safety target, save, dry-run, and run-now.

Supported intervals are constrained options: 5, 15, 30, or 60 minutes; 6
hours; and daily. The application does not accept arbitrary shell cron text.

When mode is `percent`, threshold and target percentage are shown. The target
must be lower than the threshold. Defaults are 80% and 75%.

When mode is `absolute`, the administrator selects MB or GB and one maximum
value. No percentage safety field is displayed or stored for this mode.

## Public API Contract

`POST /api/messages/media` keeps the current JSON request:

```json
{
  "instance_uuid": "uuid",
  "to": "5511999999999",
  "chat_type": "user",
  "media_type": "image",
  "media_url": "https://files.example.com/image.jpg",
  "caption": "Optional caption"
}
```

It also accepts `multipart/form-data`:

- `instance_uuid` required;
- `to` required;
- `chat_type` optional under the current rules;
- `media_type` required;
- `media` required when `media_url` is absent;
- `media_url` optional alternative to `media`;
- `caption` optional;
- `file_name` optional display name.

Exactly one of `media` and `media_url` must be supplied. Success retains the
existing `201` response shape. Validation failures use `422`; oversized request
or file uses `413`; authentication and instance access retain current behavior.

The authenticated web endpoint `/instances/{id}/chat/send` accepts the same
multipart media fields while continuing to accept JSON text and URL requests.

## Upload Validation and Storage

The maximum file size is 256 MiB (268,435,456 bytes). Nginx and PHP request
limits use a small protocol margin above this value while application
validation enforces the exact limit.

Validation uses the uploaded temporary file, `finfo`, and an allowlist:

- image: common safe raster formats supported by WhatsApp/Baileys;
- video: MP4 and other explicitly supported video MIME types;
- audio: supported audio MIME types;
- document: a documented allowlist excluding executable and script formats.

Client file names are metadata only, normalized for display, and never used as
filesystem paths. Files receive random server names and are stored under a
date/instance hierarchy in `backend-php/storage/media/outgoing`. All resolved
paths must remain below the configured media root.

If queue creation fails after a file is stored, the new file is removed. Once
queued, the file is retained for history and managed only by retention.

## Queue and Worker Flow

The queue payload represents one of two sources:

- remote: the existing `{ url }` Baileys payload after SSRF and content checks;
- local: an internal `local_media_path` reference relative to the shared media
  root, plus MIME and display name metadata.

Before sending a local source, the worker resolves it against
`MEDIA_STORAGE_PATH`, rejects traversal or paths outside that root, verifies it
is a regular file, enforces the 256 MiB limit, and reads it as a `Buffer`. It
then builds the Baileys image, video, audio, or document payload. Internal path
metadata must never be exposed through the public API.

Retention cannot remove media referenced by queue rows in `pending` or
`processing` state.

## Data Model

Add a singleton storage settings table containing:

- enabled;
- mode: `percent` or `absolute`;
- percent threshold and percent target, nullable outside percent mode;
- absolute bytes, nullable outside absolute mode;
- interval minutes constrained to supported values;
- last run timestamp and status;
- timestamps and updating administrator.

Add a cleanup run table containing start/end time, trigger (`cron`, `manual`,
or `dry_run`), mode, threshold snapshot, bytes/files before and after,
bytes/files selected or deleted, status, error summary, and administrator when
manually triggered.

Outgoing and incoming media continue using the existing media storage and
message association. A removed file is represented by retention metadata on
its media record rather than deleting the message. The UI and API return a
specific removed state instead of a broken download URL.

## Retention Algorithm

1. Acquire a database advisory lock so only one cleanup runs at a time.
2. Read validated settings and current disk/media metrics.
3. Determine whether cleanup is required:
   - percent mode: partition usage is at or above threshold;
   - absolute mode: media directory bytes exceed the configured limit.
4. Determine bytes to reclaim:
   - percent mode: enough to reach the configured target percentage;
   - absolute mode: only enough to return to the configured byte limit.
5. Select existing media files oldest first, excluding files referenced by
   pending or processing queue items.
6. In dry-run, report candidates without deleting or updating media records.
7. In cleanup, delete each verified in-root regular file, mark the media record
   removed with reason/time, and continue until the target is reached.
8. Record final metrics and release the advisory lock.

Missing files are marked removed without counting reclaimed bytes. Files whose
resolved path escapes the media root are never deleted and are logged as
errors. Partial failures do not roll back already deleted files; the run is
recorded as partial with per-file-safe continuation.

## Scheduling

Provide a PHP CLI command dedicated to retention. The system cron invokes a
stable wrapper every five minutes. The command checks whether retention is
enabled and whether the configured interval has elapsed, so changing frequency
in the panel does not rewrite crontab entries or accept shell input.

Production installation adds one root-owned cron entry or systemd timer that
runs the command as the application user. Docker provides a companion scheduler
service or equivalent documented cron loop using the same CLI command.

## Security

- Administrator authorization is mandatory for settings, history, dry-run,
  and manual cleanup.
- API token ownership and instance sharing rules remain unchanged.
- CSRF protection applies to web settings and manual actions.
- MIME is detected server-side; extension and browser MIME are not trusted.
- Executable/script uploads are rejected.
- Remote URL SSRF checks remain in place.
- File paths are random, relative, and canonicalized beneath the media root.
- Upload and cleanup operations use bounded memory where possible; PHP does not
  load the uploaded file into memory.
- Cleanup configuration cannot contain arbitrary cron expressions or commands.
- Cleanup history avoids secrets, tokens, captions, and contact content.

## Configuration

- Application maximum: 256 MiB.
- PHP `upload_max_filesize`: 256M.
- PHP `post_max_size`: 260M or greater.
- Nginx `client_max_body_size`: 260M or greater.
- Request timeout values must allow large uploads without weakening worker or
  internal API timeouts.
- `MEDIA_STORAGE_PATH` is shared consistently between PHP and worker.

## Testing and Verification

Automated coverage will include:

- upload validation by size, MIME, media type, missing source, dual source,
  malformed upload, unauthorized token, and inaccessible instance;
- JSON URL requests remain backward compatible;
- safe random path creation and rollback cleanup;
- worker local-path containment, missing file, oversize file, and payload
  conversion;
- retention threshold calculations for percent and MB/GB modes;
- oldest-first selection and exclusion of pending/processing queue files;
- dry-run non-destructive behavior;
- removed-media API/UI behavior;
- administrator-only settings and manual execution;
- UI file picker, drag/drop, mode switching, validation, and URL fallback.

Release verification includes PHP lint, TypeScript build, dependency audit,
API examples, browser checks at desktop/mobile widths, database migration on a
copy or transaction-safe environment, production deployment, service health,
upload/send smoke tests, retention dry-run, GitHub commit/tag/Release, and
post-deployment logs.

## Documentation and Release

Update README, API documentation, installation guidance, Postman collection,
environment examples, Docker configuration, changelog, and version. Publish a
new patch release after validation and deploy that exact tag to production with
a recoverable backup.
