<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Support;

/**
 * Resolves the transactional sender at runtime.
 *
 * Published package config can evaluate before `mail.php`, leaving
 * `filament-cloudflare-mail-sender.from.*` empty. Fall back to Laravel's
 * `mail.from` when the package values are missing.
 *
 * Sender fields are editable by default. Set `from.editable` to false to lock
 * the UI and delivery path to the configured/fallback From values.
 */
final class ConfiguredSender
{
    public static function address(): ?string
    {
        return self::stringOrFallback(
            config('filament-cloudflare-mail-sender.from.address'),
            config('mail.from.address'),
        );
    }

    public static function name(): ?string
    {
        return self::stringOrFallback(
            config('filament-cloudflare-mail-sender.from.name'),
            config('mail.from.name'),
        );
    }

    public static function editable(): bool
    {
        $editable = config('filament-cloudflare-mail-sender.from.editable');

        if ($editable === null) {
            return true;
        }

        return $editable === true;
    }

    private static function stringOrFallback(mixed $configured, mixed $fallback): ?string
    {
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if ($configured !== null && $configured !== '') {
            return null;
        }

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }
}
