# Filament Cloudflare Mail Sender

Compose, queue, and audit **transactional** email from a Filament panel — using Laravel’s built-in Cloudflare Email Sending mail transport.

No custom SMTP client. No marketing/campaign tools. One **Send email** action that validates, stores an audit record, and queues delivery.

| Requirement | Version |
| --- | --- |
| PHP | 8.4+ (8.x) |
| Laravel | 13.23+ (13.x) |
| Filament | 5.1+ (5.x) |

## Quick start

### 1. Install

```bash
composer require draliragab/filament-cloudflare-mail-sender
```

### 2. Publish config + migration

```bash
php artisan vendor:publish --tag=filament-cloudflare-mail-sender-config
php artisan vendor:publish --tag=filament-cloudflare-mail-sender-migrations
php artisan migrate
```

The migration is publish-only so your app owns the schema history.

### 3. Configure Cloudflare in Laravel

1. In Cloudflare, onboard the sending domain for **Email Sending**.
2. Create an API token with **Email Sending: Edit** for that account.
3. Put credentials in `.env` (never commit them):

```env
CLOUDFLARE_ACCOUNT_ID=your-account-id
CLOUDFLARE_API_TOKEN=your-api-token

MAIL_MAILER=cloudflare
MAIL_FROM_ADDRESS=noreply@your-onboarded-domain.com
MAIL_FROM_NAME="${APP_NAME}"
```

4. Register Laravel’s native Cloudflare mailer in `config/mail.php`:

```php
'mailers' => [
    // ...
    'cloudflare' => [
        'transport' => 'cloudflare',
    ],
],
```

5. Wire credentials in `config/services.php` (`token` is preferred in Laravel 13):

```php
'cloudflare' => [
    'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
    'token' => env('CLOUDFLARE_API_TOKEN'),
],
```

This package does **not** ship its own Cloudflare HTTP client. It uses Laravel’s first-party `cloudflare` transport (Symfony HTTP Client 8).

### 4. Register the Filament plugin

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

Guests are always denied. If you omit a callback, any authenticated panel user is allowed — so set both callbacks on privileged panels.

### 5. Run a queue worker

Sends are queued by default. Without a worker, messages stay `queued` and never leave for Cloudflare.

```bash
php artisan queue:work
```

To use a dedicated queue name, set `queue.name` in the published package config (and pass `--queue=` to the worker). Leave `queue.connection` as `null` so it uses your app’s `QUEUE_CONNECTION` / `queue.default` at runtime.

### 6. Send from the panel

Open **Email → Compose Email**, fill recipients and Markdown body, then confirm **Send email**.

- The form validates and queues the message.
- **Sent Emails** is the audit log (status, envelope, bodies, failures).
- Status **Accepted by Cloudflare** means Laravel got a successful provider response — **not** inbox delivery.

---

## Package configuration (common knobs)

Published file: `config/filament-cloudflare-mail-sender.php`.

### Sender (From)

| Option | Default | Meaning |
| --- | --- | --- |
| `from.address` / `from.name` | `null` | Fall back to `mail.from` at runtime |
| `from.editable` | `true` | Allow editing From in the compose UI |
| `from.allowed_domains` | `[]` | When editable, limit domains (empty = default sender’s domain) |

Keep published `from.address` / `from.name` as `null` unless you intentionally override Laravel’s mail defaults. Calling `config('mail.*')` inside the published package config file is unsafe (load order).

### Recipients

Suggestions come from your user model — they are **not** an allowlist. Any valid address can be entered.

```php
'recipients' => [
    'model' => App\Models\User::class,
    'email_column' => 'email',
    'name_column' => 'name',
    'suggestion_limit' => 250,
],
```

To / CC / BCC are normalized and deduplicated. Cloudflare allows at most **50** unique recipients per message; the package checks that before queueing.

### Attachments

| Option | Default | Meaning |
| --- | --- | --- |
| `attachments.enabled` | `true` | Set `false` to hide uploads and skip disk checks |
| `attachments.disk` | `null` | Falls back to `filesystems.default` at runtime |
| `attachments.max_file_kib` | `4096` | Per-file limit |
| `message.max_total_kib` | `5120` | Whole-message budget (Cloudflare-oriented) |

### Navigation

Defaults to an **Email** nav group with Compose Email and Sent Emails. Override labels/group/icon/sort under `navigation`.

### Audit retention

Retention is opt-in (`audit.retention_days = null` means never prune). Example for 90 days:

```php
'audit' => [
    'store_body' => true,
    'retention_days' => 90,
],
```

```bash
php artisan cloudflare-mail:prune
```

Schedule in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('cloudflare-mail:prune')->daily();
```

With `audit.store_body = false`, Markdown/HTML/plain text are scrubbed after a terminal status.

---

## How sending works

1. User confirms **Send email**.
2. Package validates the form, renders Markdown → HTML + plain text (same renderer used for delivery).
3. An audit row is stored, a send token is assigned, status becomes `queued`, and `SendComposedEmail` is dispatched.
4. The worker sends via `Mail::mailer(...)` and Laravel’s Cloudflare transport.
5. On success, status becomes **Accepted by Cloudflare** (`sent`).

There is no separate save-draft / preview step in the UI. Double-clicks against the same compose scope are designed not to create duplicate provider sends.

**Important limits of the Laravel transport**

- `sent` ≠ delivered to the inbox.
- No durable Cloudflare message ID is exposed to this package today.
- If Cloudflare accepts a message and the worker dies before local success is recorded, a rare duplicate send is possible — design content to tolerate that.

Transactional use only: no newsletters, campaigns, audience tools, or bulk marketing features.

---

## Privacy

Audit logs can hold recipient addresses, subjects, bodies, and attachment metadata. Restrict who can compose and who can view Sent Emails. Credentials are never stored in package tables. Failure details are sanitized before persistence.

---

## Development (package contributors)

Tests use fake / non-network mailers and never call Cloudflare.

```bash
composer format
composer refactor
composer analyse
composer test
composer verify   # authoritative gate
```

### Optional real-provider smoke test

Opt-in only — never in CI. After configuring a non-production Cloudflare account:

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
        ->to('you@example.com')
        ->subject('Manual Cloudflare mail smoke test'),
);
```

Success means Cloudflare accepted the request, not that mail landed in an inbox.

---

## License

MIT — see [LICENSE.md](LICENSE.md).
