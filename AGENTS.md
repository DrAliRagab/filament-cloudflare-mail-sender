# filament-cloudflare-mail-sender

## Project state

The approved design and implementation plan have been built. Maintain the package against its tests, architecture constraints, and task reports; do not treat planning documents as permission to redesign established public behavior.

## Purpose

Maintain a reusable Laravel 13 and Filament 5 package that lets authorized panel users compose, preview, queue, send, and audit transactional email through Laravel's first-party Cloudflare Email Sending API transport.

The package must use Laravel's native `cloudflare` mail transport. Do not implement, wrap, replace, or duplicate `Illuminate\Mail\Transport\CloudflareTransport` unless a future user request explicitly changes this decision.

## Supported stack

- PHP 8.4 or newer within the PHP 8.x series.
- Laravel 13.23 or newer within Laravel 13.
- Filament 5.1 or newer within Filament 5.
- Symfony HTTP Client 8 for Laravel's Cloudflare API transport.
- Pest 5.0.1 with Pest Laravel Plugin 5.0.1 and Orchestra Testbench 11.1 for package tests.
- PHPStan 2 with Larastan at `level: max`.
- Laravel Pint with the standard Laravel preset.
- Rector 2 with Rector Laravel.

Do not broaden framework compatibility without current dependency evidence and a test matrix proving it.

## Required architecture

- Register the package through a Laravel package service provider and a Filament panel plugin.
- Use Laravel Mail, a package Mailable, and a package queue job for delivery.
- Resolve Cloudflare credentials through Laravel's supported `services.cloudflare` configuration.
- Default the From address and name from Laravel's `mail.from` configuration. Do not read `env()` outside configuration files.
- Use Filament's `MarkdownEditor` as the message body editor. It is the rich editing experience; do not introduce Filament `RichEditor` as a second body format.
- Render preview and delivery through the same Markdown rendering service.
- Use To, CC, and BCC inputs that suggest addresses from the configured user model while still accepting valid arbitrary email addresses.
- Persist a package-owned email record before delivery and track the local lifecycle accurately.
- Treat `sent` as accepted by Laravel's Cloudflare transport. Do not claim provider delivery because Laravel currently discards Cloudflare's successful response payload.
- Queue sends by default and make the send operation idempotent so retries and double-clicks do not create duplicate messages.

## Product constraints

- Cloudflare Email Sending is for transactional email. Do not add newsletter, campaign, audience segmentation, or bulk-marketing features.
- The default From address and name are editable in the UI. A published configuration option may lock them to the package `from.*` values (falling back to Laravel `mail.from`), and every address must remain valid for the application's onboarded Cloudflare sending domain.
- Preview must show From, Reply-To, To, CC, BCC, subject, rendered HTML, plain text, and attachment metadata before confirmation.
- Logs may contain sensitive content. Access must follow the package authorization callback, secrets must never be stored, and failure details must be sanitized.
- Do not represent `sent` as `delivered`, and do not invent Cloudflare message IDs or per-recipient delivery states.

## Documentation and MCP usage

- Use Context7 for current Laravel, Filament, Pest, Testbench, PHPStan, Pint, Rector, and other library documentation.
- Use the Cloudflare documentation MCP for current Email Service contracts and limits.
- Use the Cloudflare API MCP only for explicitly authorized account inspection or changes. Prefer read-only inspection during diagnosis.
- Laravel Boost is intentionally not part of this package repository; do not add it unless the repository becomes a full Laravel application.
- Never put Cloudflare API tokens, account credentials, user email data, or other secrets in MCP configuration or committed files.

## Development workflow

- Follow test-driven development for every feature and bug fix.
- Start from the exact failing test or provider contract before changing implementation.
- Keep files focused and package boundaries explicit.
- Preserve user changes and avoid unrelated refactors.
- Use package configuration and contracts for host-application assumptions such as the user model, email column, display-name column, queue, authorization, and retention.
- Prefer Laravel and Filament extension points over custom JavaScript or framework duplication.

## Verification contract

The completed package must provide these Composer scripts:

- `composer format`: apply Pint formatting.
- `composer refactor`: apply Rector changes.
- `composer analyse`: run PHPStan/Larastan at max level.
- `composer test`: run Pest with package coverage enforcement.
- `composer verify`: the authoritative, non-mutating aggregate gate.

`composer verify` must run, in order:

1. `composer validate --strict`.
2. optimized autoload generation with `--strict-psr`.
3. `composer audit`.
4. Pint in check mode.
5. Rector in dry-run mode.
6. PHPStan/Larastan at `level: max`.
7. the full Pest/Testbench suite with at least 95% line coverage.

Do not claim completion until a fresh `composer verify` exits successfully. After running mutating tools, inspect the complete diff and run the verifier again.

## Source-of-truth documents

- Product and architecture: `docs/superpowers/specs/2026-08-09-filament-cloudflare-mail-sender-design.md`.
- Approved implementation plan: `docs/superpowers/plans/2026-08-09-filament-cloudflare-mail-sender.md`.
- Implementation evidence and review history: `.superpowers/sdd/2026-08-09-filament-cloudflare-mail-sender/`.
