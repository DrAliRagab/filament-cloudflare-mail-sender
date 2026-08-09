<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Support;

use Throwable;

final class FailureSanitizer
{
    public const MAX_MESSAGE_LENGTH = 2000;

    /**
     * @return array{class: class-string<Throwable>|null, message: string}
     */
    public function sanitize(?Throwable $exception): array
    {
        if ($exception === null) {
            return [
                'class' => null,
                'message' => 'The queue job failed without an exception summary.',
            ];
        }

        $message = str_replace($this->configuredSecrets(), '[REDACTED]', $exception->getMessage());
        $message = preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $message) ?? $message;
        $message = preg_replace(
            '/\b(token|api[_-]?key|secret|password)[\'\"]?\s*[:=]\s*[\'\"]?[^\s,;\'\"}]+[\'\"]?/i',
            '$1=[REDACTED]',
            $message,
        ) ?? $message;

        return [
            'class' => $exception::class,
            'message' => mb_strcut($message, 0, self::MAX_MESSAGE_LENGTH),
        ];
    }

    /**
     * @return list<string>
     */
    private function configuredSecrets(): array
    {
        $values = [];
        $cloudflare = config('services.cloudflare', []);

        if (is_array($cloudflare)) {
            foreach (['token', 'key', 'secret', 'password'] as $key) {
                $value = $cloudflare[$key] ?? null;

                if (is_string($value) && strlen($value) >= 6) {
                    $values[] = $value;
                }
            }
        }

        $mailers = config('mail.mailers', []);

        if (is_array($mailers)) {
            foreach ($mailers as $mailer) {
                if (! is_array($mailer)) {
                    continue;
                }

                foreach (['token', 'key', 'secret', 'password'] as $key) {
                    $value = $mailer[$key] ?? null;

                    if (is_string($value) && strlen($value) >= 6) {
                        $values[] = $value;
                    }
                }
            }
        }

        return array_values(array_unique($values));
    }
}
