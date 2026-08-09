# Filament Cloudflare Mail Sender Design

**Date:** 2026-08-09

**Status:** Approved direction encoded for written review

**Package:** `filament-cloudflare-mail-sender`

## Summary

This package adds an email composer and sent-email audit area to a host application's Filament panel. Authorized panel users can compose transactional email with Filament's Markdown editor, select known users or enter arbitrary recipient addresses, and send it through Laravel 13's first-party Cloudflare Email Sending API transport.

The package owns the Filament experience, rendering, queue orchestration, authorization hooks, and local audit records. Laravel owns the mail transport. Cloudflare owns delivery after accepting the API request.

## Goals

- Provide a polished Filament 5 email composition experience.
- Use Laravel Mail and Laravel's native Cloudflare REST API transport.
- Default the sender from the host application's environment-backed Laravel mail configuration.
- Suggest recipient addresses from the host application's user model while accepting valid raw email addresses.
- Use one Markdown source for editing, HTML delivery, and plain-text delivery.
- Queue sends safely, prevent duplicate submissions, and expose actionable failures.
- Store a useful, access-controlled audit history of messages initiated through the package.
- Ship with strict, reproducible package-quality gates modeled on the user's established Laravel repositories.

## Non-goals

- A custom Cloudflare transport, REST client, or SMTP integration.
- Newsletter, campaign, drip, audience, template-marketing, or bulk-send functionality.
- Incoming email, reply routing, mailbox management, or Cloudflare Email Routing.
- Claiming final recipient delivery from a successful Laravel send.
- Replacing the host application's normal mailer globally.
- Requiring a conventional integer `users.id` or a specific user table schema.
- Installing Laravel Boost in this package repository.

## Alternatives considered

### 1. Laravel's native Cloudflare API transport — selected

Laravel 13 includes `Illuminate\Mail\Transport\CloudflareTransport`. It uses Cloudflare's structured REST API and already maps sender, To, CC, BCC, Reply-To, subject, HTML, text, custom headers, attachments, and inline content. This keeps the package small and aligned with Laravel's supported configuration and failure behavior.

### 2. Package-owned Cloudflare transport — rejected

A custom transport could retain Cloudflare's full success response, including per-recipient delivery groups, but it would duplicate framework code and create a maintenance and security burden. The package's tracking requirement does not justify replacing first-party transport support.

### 3. Cloudflare SMTP — rejected

SMTP would work with Laravel Mail, but the API transport supports Cloudflare's structured feature set directly and is the requested integration. SMTP also provides less useful structured provider feedback.

## Compatibility contract

The first release targets one deliberate stack:

- PHP `^8.4`.
- Laravel components `^13.23`.
- Filament `^5.1`.
- Livewire `^4.0`, inherited through Filament.
- Symfony HTTP Client `^8.0`.
- Orchestra Testbench `^11.1` for development and testing.
- Pest `^5.0.1` with Pest's Laravel plugin `^5.0.1`.

Laravel 13.23 is the minimum because Pest's current Laravel plugin release requires it. Supporting older Laravel or Filament majors is explicitly deferred until a compatibility matrix and CI jobs prove them.

## Package shape

The package will use Composer package discovery and a focused service provider. The service provider will register:

- Published package configuration.
- Package views and translations.
- Package migrations.
- The package Mailable and queue job dependencies.
- Authorization and recipient-suggestion contracts with default implementations.
- An optional prune command for audit retention.

A Filament plugin class will register:

- A Compose Email page.
- A Sent Emails resource with list and view pages.
- Navigation labels, groups, icons, and sort order configurable by the host application.
- Plugin-level authorization and configuration overrides.

The host application explicitly attaches the plugin to each desired Filament panel. The package will not create or auto-register a panel.

## Host application configuration

The published `filament-cloudflare-mail-sender.php` configuration will define these stable areas:

- `mailer`: defaults to `cloudflare` and must refer to a configured Laravel mailer.
- `from.address` and `from.name`: default to `mail.from.address` and `mail.from.name`.
- `from.editable`: defaults to `true`. Set to `false` to lock From fields to configured/fallback values.
- `reply_to.address` and `reply_to.name`: optional defaults.
- `recipients.model`: defaults to the model configured for the active Laravel auth provider.
- `recipients.email_column`: defaults to `email`.
- `recipients.name_column`: defaults to `name`.
- `recipients.suggestion_limit`: defaults to `250`.
- `queue.connection`: defaults to the host application's default queue connection.
- `queue.name`: defaults to `default`.
- `queue.tries`: defaults to `3`.
- `queue.backoff`: defaults to `[60, 300, 900]` seconds.
- `attachments.enabled`: defaults to `true`.
- `attachments.disk`: defaults to the host application's default filesystem disk.
- `attachments.directory`: defaults to `filament-cloudflare-mail-sender/attachments`.
- `attachments.max_file_kib`: defaults to `4096`.
- `message.max_total_kib`: defaults to `5120`, matching Cloudflare's documented total message-size ceiling across content and attachments.
- `audit.store_body`: defaults to `true`.
- `audit.retention_days`: defaults to `null`, meaning records are retained until the host explicitly configures pruning.
- `navigation`: labels, group, icon, and sort values.

Cloudflare credentials remain in the host application's supported Laravel service configuration:

```php
'cloudflare' => [
    'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
    'token' => env('CLOUDFLARE_API_TOKEN'),
],
```

The package will never publish, copy, persist, display, or log those credential values.

## User experience

### Compose page

The page is a single responsive form with these sections:

1. Sender and Reply-To.
2. To, CC, and BCC recipients.
3. Subject.
4. Filament `MarkdownEditor` body.
5. Optional attachments.
6. A single Send action with confirmation.

The sender fields are populated from Laravel / package configuration and are editable by default. CC and BCC remain collapsed until used. The interface displays the number of unique recipients and warns when an address appears in more than one recipient group.

### Recipient inputs

Recipient fields use Filament tag-style inputs. Suggestions are built from the configured user model's email and display-name columns, capped by `recipients.suggestion_limit`. Suggestions display `Name <email@example.com>` while the stored value is the normalized email address.

Users may enter addresses not present in the user model. Every tag is trimmed, lowercased for comparison, validated as an email address, deduplicated case-insensitively, and checked across To, CC, and BCC. At least one To address is required.

The combined unique To, CC, and BCC count may not exceed 50, matching Cloudflare Email Sending's current REST API recipient limit. The package validates this before queueing so provider rejection is actionable in the panel.

The package does not store user foreign keys for recipients. This keeps audit snapshots accurate even when a user changes their email address or is deleted.

### Markdown rendering

Filament's `MarkdownEditor` is the only body editor. Its toolbar provides the rich editing experience while Markdown remains the canonical stored source.

A package `MessageBodyRenderer` converts Markdown to HTML with unsafe embedded HTML stripped and unsafe links disabled. It also produces a readable plain-text alternative. Compose submission and queue delivery call the same renderer so rendered output cannot drift from what was queued.

## Delivery flow

1. The panel user completes the form and confirms **Send email**.
2. The server validates configuration, authorization, recipients, Markdown, and attachments.
3. The package creates or updates a local email record from the submitted form and assigns an immutable send token.
4. The record transitions to `queued`, and a `SendComposedEmail` job is dispatched after the database transaction commits.
5. The job obtains a database lock and proceeds only when the record is still `queued` or `sending` with the same send token.
6. The job transitions the record to `sending` and constructs the package Mailable from the stored snapshot.
7. The job calls `Mail::mailer(configured mailer)` and sends synchronously inside the queue worker. It applies To, CC, and BCC through Laravel Mail.
8. When Laravel's Cloudflare transport returns successfully, the record transitions to `sent` with `sent_at`.
9. Retryable transport failures are rethrown so the queue applies configured backoff. The final `failed()` hook records a sanitized exception class and message and transitions the record to `failed`.

The queue job, rather than a queued Mailable nested inside another queue operation, owns lifecycle tracking. This gives the package one retry boundary and one idempotency check.

## Status semantics

- `draft`: internal pre-queue snapshot (not a user-managed draft UI).
- `queued`: accepted for asynchronous processing.
- `sending`: claimed by a worker for the current attempt.
- `sent`: Laravel's Cloudflare transport received HTTP 200 from Cloudflare.
- `failed`: all configured job attempts ended without a successful transport return.
- `cancelled`: a record was cancelled before a worker claimed it.

`sent` must be labeled in UI help text as “Accepted by Cloudflare”. It must never be displayed as “Delivered”. Laravel's current Cloudflare transport reads the successful API response but does not attach its `message_id`, `delivered`, `queued`, or `permanent_bounces` values to the returned Symfony message.

## Audit storage

The package migration creates a `cloudflare_mail_messages` table with:

- ULID primary key.
- Nullable string `actor_type` and `actor_id` snapshot fields.
- Mailer name.
- From and Reply-To address/name snapshots.
- JSON To, CC, and BCC arrays.
- Subject.
- Markdown, rendered HTML, and plain-text body snapshots when body storage is enabled.
- JSON attachment metadata without credential data.
- Status and attempt count.
- Unique immutable send token.
- Queue, sent, failed, and cancelled timestamps.
- Sanitized failure class and failure message.
- Standard creation and update timestamps.

Actor IDs are strings rather than foreign keys so integer, UUID, and ULID authenticatable models work without schema assumptions. Deleting a user does not delete audit records.

Temporary attachment objects are deleted after a successful send or terminal failure according to package cleanup rules. Audit history stores metadata, not duplicate attachment bodies.

## Authorization and security

- Registering the Filament plugin does not bypass panel authentication.
- The package exposes one authorization callback for composing/sending and another for viewing audit history.
- The default callbacks allow authenticated users who can access the configured Filament panel. Host applications can replace them with gates or role checks.
- Server-side authorization runs for page access and every send, view, duplicate, and prune action.
- Email addresses, headers, attachment names, and exception messages are escaped in panel output.
- Markdown rendering strips raw HTML and unsafe links.
- Attachment filenames are normalized, MIME types are validated, and total size is checked before persistence and again before delivery.
- API tokens and account credentials are never written to the database, UI, logs, exceptions, fixtures, or snapshots.
- The send action is rate-limited per authenticated actor and protected by the immutable send token.

Because message bodies and recipient lists may contain personal or sensitive information, documentation will require host applications to review authorization and retention before production use.

## Failure behavior

- Missing Cloudflare configuration blocks sending before a record enters `queued` and displays an actionable configuration error without exposing secret values.
- Validation failures stay on the compose page and do not dispatch a job.
- A queue dispatch failure leaves the record in `draft` with an actionable UI error.
- Transport exceptions are retried automatically according to the queue policy; the same record and send token remain authoritative across attempts.
- A terminal queue failure records only a sanitized exception summary.
- Retrying a terminally failed message is an explicit “Duplicate” operation that prefills compose and creates a new record and send token on send; the package never silently reuses a terminal token.
- A worker crash while a record is `sending` is recoverable: a subsequent attempt may reclaim the same send token, while overlapping workers cannot both send it.
- The package cannot guarantee exactly-once delivery across the boundary where Cloudflare accepts a request but the worker crashes before persisting `sent`. Documentation will state this distributed-systems limitation rather than claim impossible guarantees.

## Attachments

Attachments use Filament's upload facilities and Laravel filesystem disks. The package supports ordinary attachments and does not expose inline-image authoring in the first release. Allowed MIME types are configurable, but executable and script-like formats are denied by default.

The package enforces a 5 MiB total message budget across rendered content and base64-expanded attachments. The exact calculation and rejection behavior will be covered by unit and feature tests. Cloudflare remains the final authority and may reject payloads that exceed its current limits.

## Extensibility

The first release exposes narrow extension points:

- `RecipientSuggestionProvider` for host-specific address lookup.
- `MessageBodyRenderer` for rendering policy while retaining Markdown as input.
- Authorization callbacks for compose/send and audit viewing.
- Filament plugin configuration for navigation and panel-specific behavior.
- Laravel configuration for mailer, queue, user model columns, attachments, and retention.

It will not expose a transport abstraction because Laravel already provides that boundary.

## Testing strategy

Tests use Pest 5 and Orchestra Testbench 11 with an in-memory SQLite database where practical.

### Unit tests

- Address normalization, validation, cross-field deduplication, and display labels.
- Cloudflare's combined 50-recipient ceiling across To, CC, and BCC.
- Markdown-to-HTML and Markdown-to-text rendering safety.
- Attachment metadata and total-size calculations.
- Status-transition rules and send-token idempotency.
- Failure-message sanitization.
- Package configuration defaults.

### Feature tests

- Service provider, migrations, configuration publishing, and plugin registration.
- Compose-page authorization and form validation.
- Suggestions from integer, UUID, and ULID-style user models.
- Arbitrary recipient entry.
- Sender default and lock behavior.
- Send confirmation, automatic queue retry, and duplicate-prefill flows.
- Audit resource authorization, filters, and sensitive output escaping.
- Attachment validation and cleanup.

### Mail and queue tests

- `Mail::fake()` assertions for From, Reply-To, To, CC, BCC, subject, HTML, text, and attachments.
- `Queue::fake()` assertions that dispatch occurs after commit with the configured connection and queue.
- Job tests using a fake mail transport for success, retryable exception, and terminal failure paths.
- Regression tests ensuring two workers or two confirmation requests cannot intentionally dispatch the same send token twice.

No automated test will send a real email by default. A separately documented, opt-in manual smoke test may use a real Cloudflare account and an address controlled by the maintainer.

## Quality gates

The project will provide separate mutating developer commands and one non-mutating authority gate:

- `composer format`: Pint with Laravel's standard preset.
- `composer refactor`: Rector and Rector Laravel.
- `composer analyse`: PHPStan/Larastan with `level: max`.
- `composer test`: full Pest/Testbench suite with at least 95% line coverage over package source.
- `composer verify`: strict Composer validation, strict PSR-4 optimized autoloading, security audit, Pint check, Rector dry-run, max-level PHPStan, and the full coverage-enforced test suite.

PHPStan configuration will analyze `src`, `database`, and `tests`. It will not begin with a generated baseline. Any ignore must constrain the diagnostic message or identifier and the narrowest relevant path, include a justification comment, and be proven by a full analyzer run.

Rector will cover source, migrations, configuration, and tests. Pint and Rector run in dry/check mode under `composer verify` so the authoritative verifier never silently edits the working tree.

## MCP and agent setup

The repository commits `.codex/config.toml` with:

- Context7 over STDIO for version-current library documentation.
- Cloudflare API MCP over authenticated remote HTTP for explicitly authorized account work.
- Cloudflare documentation MCP over remote HTTP for current product documentation.

Contributors authenticate Cloudflare locally with:

```bash
codex mcp login filament_mail_cloudflare_api
```

The project-prefixed MCP IDs prevent accidental merging with similarly named user-level servers. The configuration contains no credentials. Laravel Boost is omitted because this is a package repository, not a complete Laravel application.

## Documentation deliverables

Implementation will include:

- Installation and compatibility requirements.
- Laravel Cloudflare mailer and `services.cloudflare` configuration.
- Filament panel plugin registration.
- User-model recipient suggestion configuration.
- Authorization examples.
- Queue worker requirements and retry behavior.
- Status semantics and the “accepted, not delivered” boundary.
- Attachment and Cloudflare domain-onboarding requirements.
- Privacy, retention, and transactional-only guidance.
- Local testing and the opt-in real-provider smoke test.

## Acceptance criteria

The design is implemented only when all of these are true:

- A host Laravel 13 / Filament 5 application can register the plugin without package-source edits.
- An authorized panel user can compose Markdown email, use known or arbitrary recipients, and queue a send with one confirmed Send action.
- Laravel's native Cloudflare API transport is the only production delivery transport implemented by the package.
- The exact From, recipients, subject, HTML, text, and attachment metadata are auditable locally.
- Draft, queued, sending, sent, failed, and cancelled states follow the documented meanings.
- Duplicate UI confirmation and overlapping job execution are covered by regression tests.
- Secrets are absent from persisted data and rendered diagnostics.
- The README documents Cloudflare domain onboarding and the accepted-versus-delivered limitation.
- A fresh `composer verify` exits successfully with PHPStan at max and at least 95% source line coverage.
