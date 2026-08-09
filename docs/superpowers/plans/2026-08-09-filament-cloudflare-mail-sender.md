# Filament Cloudflare Mail Sender Implementation Plan

**Goal:** Build the approved Laravel 13.23+ / Filament 5 package that composes, previews, queues, sends, and audits transactional mail through Laravel's native Cloudflare API transport.

**Architecture:** A package service provider owns configuration, migrations, bindings, views, and commands. A Filament plugin registers a Markdown-based compose page and a read-only audit resource. Immutable persisted message snapshots cross the queue boundary; a single queue job owns retries and lifecycle transitions while Laravel Mail owns transport delivery.

**Stack:** PHP 8.4, Laravel 13.23+, Filament 5.1+, Livewire 4, Symfony HTTP Client 8, Orchestra Testbench 11.1+, Pest 5.0.1+ with Pest Laravel plugin 5.0.1+, PHPStan 2/Larastan at max, Pint, Rector 2 with Rector Laravel.

**Source of truth:** `docs/superpowers/specs/2026-08-09-filament-cloudflare-mail-sender-design.md`.

## Task 1: Scaffold the package and authoritative quality gate

**Files:** `composer.json`, `phpunit.xml`, `tests/Pest.php`, `tests/TestCase.php`, `tests/Feature/PackageBootTest.php`, `src/FilamentCloudflareMailSenderServiceProvider.php`, `src/FilamentCloudflareMailSenderPlugin.php`, `config/filament-cloudflare-mail-sender.php`, `phpstan.neon`, `rector.php`, `pint.json`, `.github/workflows/tests.yml`.

1. Create a minimal failing package-boot test proving package discovery, config merge, and plugin identity.
2. Add Composer runtime/development dependencies for the approved compatibility contract and install them.
3. Implement the smallest service provider/plugin/config needed for the test to pass.
4. Add the exact mutating scripts and non-mutating `composer verify` chain from the design.
5. Add CI jobs for lowest and current compatible dependencies on PHP 8.4.
6. Run focused tests and all gates that are meaningful at this stage; commit.

## Task 2: Add the audit domain, migration, and configuration

**Files:** `src/Enums/MessageStatus.php`, `src/Models/CloudflareMailMessage.php`, `src/Support/MessageStatusTransitions.php`, `database/migrations/create_cloudflare_mail_messages_table.php.stub`, `tests/Unit/MessageStatusTransitionsTest.php`, `tests/Feature/MessagePersistenceTest.php`, config updates.

1. Write failing tests for ULID records, actor snapshots, JSON recipient/attachment casts, immutable send tokens, timestamps, and allowed lifecycle transitions.
2. Add the migration and model with explicit casts/fillable attributes and package-owned table name.
3. Implement transition rules for draft, queued, sending, sent, failed, and cancelled; label sent as accepted by Cloudflare.
4. Add audit body-storage/retention and queue retry defaults.
5. Run focused tests, analysis, and formatting checks; commit.

## Task 3: Implement recipient suggestions and safe message rendering

**Files:** `src/Contracts/RecipientSuggestionProvider.php`, `src/Contracts/MessageBodyRenderer.php`, `src/Recipients/EloquentRecipientSuggestionProvider.php`, `src/Support/RecipientAddressSet.php`, `src/Rendering/CommonMarkMessageBodyRenderer.php`, DTOs as needed, and focused tests.

1. Write failing tests for arbitrary emails, configured user-model suggestions, case-insensitive normalization/deduplication across To/CC/BCC, minimum To validation, Cloudflare's combined 50-recipient ceiling, and integer/UUID/ULID-safe lookup.
2. Implement the provider contract/default implementation and address-set validation.
3. Write failing safety/parity tests for Markdown HTML and plain text, including stripped raw HTML and unsafe links.
4. Implement one renderer used by both preview and delivery and bind both contracts in the provider.
5. Run focused tests and strict static analysis; commit.

## Task 4: Implement attachment policy and immutable message snapshots

**Files:** `src/Attachments/AttachmentPolicy.php`, `src/Data/ComposedMessageData.php`, `src/Support/MessageSnapshotFactory.php`, related tests and config updates.

1. Write failing tests for allowed/denied MIME types, normalized filenames, the 4 MiB per-file limit, and the 5 MiB rendered-content plus base64-expanded attachment budget.
2. Implement attachment metadata validation without storing bodies in audit JSON.
3. Write failing tests proving actor, sender, reply-to, recipients, subject, Markdown, rendered HTML/text, mailer, and attachments are snapshotted before queueing.
4. Implement the snapshot factory and body-scrubbing policy when body storage is disabled.
5. Run focused tests and strict analysis; commit.

## Task 5: Implement the Mailable, queue job, and idempotent delivery flow

**Files:** `src/Mail/ComposedMessage.php`, `src/Jobs/SendComposedEmail.php`, `src/Support/FailureSanitizer.php`, delivery tests.

1. Write failing mail tests for From, Reply-To, To, CC, BCC, subject, identical rendered HTML/plain text, and attachments.
2. Implement the package Mailable using Laravel Mail only; do not add a custom Cloudflare client/transport.
3. Write failing job tests for queued-to-sending-to-sent, configured mailer/queue, three tries, `[60, 300, 900]` backoff, terminal sanitized failure, cleanup, and same-token idempotency.
4. Implement the lock/token guarded queue job and after-commit dispatch service.
5. Document/test the unavoidable provider-accepted/worker-crash exactly-once limitation.
6. Run focused tests and strict analysis; commit.

## Task 6: Build the Filament compose and audit interfaces

**Files:** `src/Filament/Pages/ComposeEmail.php`, page Blade views, `src/Filament/Resources/CloudflareMailMessages/*`, translations, UI tests.

1. Write failing authorization and form tests for configured sender defaults, locked sender, recipient suggestions plus raw tags, MarkdownEditor state, validation, and configured navigation.
2. Implement the Compose page with sender/reply-to, To/CC/BCC, subject, Filament MarkdownEditor, uploads, draft, preview, confirm send, cancel, and preview invalidation after edits.
3. Ensure preview displays sender, reply-to, all recipients, subject, rendered HTML/plain text, and attachment metadata from the same renderer used for delivery.
4. Rate-limit confirmation to five per minute per actor and prevent duplicate confirmations.
5. Write and implement audit list/view authorization, status help text, filters, failure display, and duplicate-as-draft.
6. Run focused UI tests and strict analysis; commit.

## Task 7: Add retention, documentation, and complete verification

**Files:** `src/Console/Commands/PruneCloudflareMailMessages.php`, `README.md`, `LICENSE.md`, tests, any final configuration/translations.

1. Write failing tests for opt-in retention pruning, terminal-body scrubbing, and attachment cleanup.
2. Implement the prune command and document scheduler integration.
3. Document installation, native Laravel Cloudflare mailer/services config, Filament panel registration, authorization, recipient model mapping, queues/retries, attachments, privacy/retention, transactional-only scope, accepted-not-delivered semantics, and an opt-in manual provider smoke test.
4. Add the MIT license and complete public API examples.
5. Run `composer format` and `composer refactor`, inspect the full diff, then run a fresh `composer verify`.
6. Fix all failures without baselines or broad ignores, request final review, and commit the verified result.

## Completion conditions

- No package-owned mail transport or Cloudflare API client exists.
- Automated tests never send a real Cloudflare email.
- `composer verify` is non-mutating, PHPStan is at max, and source coverage is at least 95%.
- A host Laravel 13 / Filament 5 application can install and explicitly register the plugin.
- UI and documentation consistently describe `sent` as “Accepted by Cloudflare,” never delivered.
