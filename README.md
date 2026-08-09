# Filament Cloudflare Mail Sender

A Filament panel package for composing, queueing, and auditing transactional email through Laravel's first-party Cloudflare Email Sending transport.

The package supports PHP 8.4 within PHP 8.x, Laravel 13.23 or newer within Laravel 13, and Filament 5.1 or newer within Filament 5. It intentionally does not claim compatibility with other major versions.

## Installation

Install the package:

```bash
composer require draliragab/filament-cloudflare-mail-sender
```

Publish its configuration and migration, then migrate:

```bash
php artisan vendor:publish --tag=filament-cloudflare-mail-sender-config
php artisan vendor:publish --tag=filament-cloudflare-mail-sender-migrations
php artisan migrate
```

The migration is publish-only so the host application owns its schema history.

## Cloudflare and Laravel configuration

First onboard the From address's domain for Email Sending in Cloudflare. Create an API token scoped to the account with the `Email Sending: Edit` permission. Do not place the token in source control.

Laravel 13 provides the native `cloudflare` REST API mail transport. This package does not contain a provider client or custom mail transport. Symfony HTTP Client 8, which Laravel uses for the request, is already a package dependency.

Add the mailer to `config/mail.php`:

```php
'mailers' => [
    // ...
    'cloudflare' => [
        'transport' => 'cloudflare',
    ],
],
```

Add the credentials to `config/services.php`. `token` is the preferred Laravel 13 key:

```php
'cloudflare' => [
    'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
    'token' => env('CLOUDFLARE_API_TOKEN'),
],
```

Set the package mailer and Laravel's default sender in the published configuration and `config/mail.php`. From address and name are editable by default. Set `from.editable` to `false` to lock them to the package `from.*` values (falling back to Laravel `mail.from`). When editing is enabled, keep `from.allowed_domains` limited to domains onboarded in the same Cloudflare account; an empty list derives the default sender's domain.

Attachment uploads are optional. Set `attachments.enabled` to `false` to hide the upload UI and skip attachment disk requirements. When attachments stay enabled, `attachments.disk` falls back to Laravel's default filesystem disk at runtime.

Sending uses a single **Send email** action on the Compose Email page. That validates the form, stores the audit snapshot, and dispatches the package queue job through Laravel's mailer. Run the configured queue worker so messages actually leave via Cloudflare.

## Filament panel registration

Register the plugin explicitly in each panel that should expose it:

```php
use DrAliRagab\FilamentCloudflareMailSender\FilamentCloudflareMailSenderPlugin;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(
            FilamentCloudflareMailSenderPlugin::make()
                ->authorizeComposeUsing(
                    fn (Authenticatable $user): bool => $user->can('send transactional email'),
                )
                ->authorizeAuditUsing(
                    fn (Authenticatable $user): bool => $user->can('view transactional email audit'),
                ),
        );
}
```

Guests are always denied. Authenticated users are allowed by default when a callback is omitted, so applications with privileged panels should configure both callbacks explicitly.

## Recipients and composing

The `recipients` configuration maps suggestions to a host Eloquent user model:

```php
'recipients' => [
    'model' => App\Models\User::class,
    'email_column' => 'email',
    'name_column' => 'name',
    'suggestion_limit' => 250,
],
```

Suggestions are conveniences, not an allowlist: authorized users may enter any valid email address. To, CC, and BCC are canonicalized and deduplicated across fields. Cloudflare currently permits at most 50 combined unique recipients, which the package validates before queueing.

Filament's `MarkdownEditor` is the only message-body format. **Send email** validates the form, renders HTML and plain text through the same Markdown service used for delivery, persists the audit snapshot, and queues the job. There is no separate save-draft or preview step.

## Queues and delivery semantics

Configure `queue.connection` and `queue.name`, then run a worker for that connection and queue. For example, when both are `database` and `mail`:

```bash
php artisan queue:work database --queue=mail
```

The job defaults to three attempts with backoff intervals of 60, 300, and 900 seconds. Send assigns one immutable send token under a database row lock. Duplicate sends against the same upload scope and ordinary same-token retries cannot send concurrently, and queue dispatch occurs only after the package transaction commits.

A local `sent` status means **Accepted by Cloudflare**, not delivered. The native Laravel transport does not expose provider delivery state or a durable provider message ID to this package. There is also an unavoidable duplicate window if Cloudflare accepts a request but the worker crashes before the local transaction records success. Design downstream messages to tolerate that rare failure mode.

This package is only for transactional email. It does not provide newsletters, campaigns, audience segmentation, or bulk marketing.

## Attachments, limits, and privacy

Attachments are stored on the configured Laravel disk below `attachments.directory`. Upload paths are scoped to the authenticated actor and a server-issued draft identifier. Before delivery the package revalidates MIME type, size, path ownership, and SHA-256 content digest. Temporary objects are removed after terminal processing.

The defaults allow files up to 4 MiB each and enforce Cloudflare's 5 MiB total message budget across rendered content and base64-expanded attachments. Cloudflare remains the final authority for its service limits.

Audit records include recipients, headers, attachment names and metadata, lifecycle timestamps, and sanitized failure details. They can contain personal or confidential information. Restrict audit authorization and filesystem access accordingly; package records never store Cloudflare credentials. With `audit.store_body=false`, Markdown, HTML, and plain text are scrubbed after a terminal state.

## Retention

Audit retention is opt-in. The default `audit.retention_days=null` makes pruning a no-op. Set a positive integer to remove only Sent, Failed, and Cancelled records strictly older than their matching terminal timestamp:

```php
'audit' => [
    'store_body' => true,
    'retention_days' => 90,
],
```

The command cleans any remaining package-owned attachment objects before deleting each record. Unsafe paths and filesystem failures retain that record so a later run can retry:

```bash
php artisan cloudflare-mail:prune
```

Schedule it with Laravel 13 in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('cloudflare-mail:prune')->daily();
```

## Testing and verification

Automated tests use fake and non-network mail transports and never contact Cloudflare.

```bash
composer format
composer refactor
composer analyse
composer test
composer verify
```

`composer verify` is the authoritative non-mutating gate: strict Composer validation, optimized strict-PSR autoloading, dependency audit, Pint check, Rector dry-run, PHPStan/Larastan at max level, and Pest with at least 95% line coverage.

## Manual real-provider smoke test

This is deliberately opt-in and must never run in automated tests or CI. After configuring a non-production Cloudflare account and an onboarded sender domain, replace the recipient below with an address you control, explicitly set the shell guard, and run these lines in `php artisan tinker`:

```bash
RUN_CLOUDFLARE_MAIL_SMOKE=1 php artisan tinker
```

```php
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

throw_unless(($_SERVER['RUN_CLOUDFLARE_MAIL_SMOKE'] ?? null) === '1');

Mail::mailer('cloudflare')->raw(
    'Manual Cloudflare transport smoke test.',
    static fn (Message $message) => $message
        ->to('controlled-recipient@example.com')
        ->subject('Manual Cloudflare mail smoke test'),
);
```

That call sends real provider mail. Success proves only that Cloudflare accepted the request; it does not prove inbox delivery. For an end-to-end package smoke test, compose and queue a controlled transactional message in the registered panel and run its configured worker.

## License

Filament Cloudflare Mail Sender is open-source software licensed under the [MIT license](LICENSE.md).
