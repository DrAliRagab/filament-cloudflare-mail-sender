<?php

declare(strict_types=1);

use DrAliRagab\FilamentCloudflareMailSender\Support\FailureSanitizer;
use RuntimeException;

test('failure sanitizer provides a bounded fallback without an exception', function (): void {
    expect((new FailureSanitizer)->sanitize(null))->toBe([
        'class' => null,
        'message' => 'The queue job failed without an exception summary.',
    ]);
});

test('failure sanitizer redacts configured bearer and JSON credentials', function (): void {
    config()->set('services.cloudflare.token', 'configured-secret');
    config()->set('mail.mailers', [
        'invalid' => 'not-an-array',
        'cloudflare' => ['password' => 'mailer-password'],
    ]);

    $failure = new RuntimeException(
        'Bearer bearer-secret token=plain-secret '
        .'"token":"json-secret" "api_key":"json-api-secret" '
        .'configured-secret mailer-password',
    );
    $sanitized = (new FailureSanitizer)->sanitize($failure);

    expect($sanitized['class'])->toBe(RuntimeException::class)
        ->and($sanitized['message'])->toContain('Bearer [REDACTED]')
        ->and($sanitized['message'])->not->toContain('bearer-secret')
        ->and($sanitized['message'])->not->toContain('plain-secret')
        ->and($sanitized['message'])->not->toContain('json-secret')
        ->and($sanitized['message'])->not->toContain('json-api-secret')
        ->and($sanitized['message'])->not->toContain('configured-secret')
        ->and($sanitized['message'])->not->toContain('mailer-password');
});

test('failure sanitizer truncates multibyte messages at its byte boundary', function (): void {
    $sanitized = (new FailureSanitizer)->sanitize(new RuntimeException(str_repeat('é', 1500)));

    expect(strlen($sanitized['message']))->toBeLessThanOrEqual(FailureSanitizer::MAX_MESSAGE_LENGTH)
        ->and(mb_check_encoding($sanitized['message'], 'UTF-8'))->toBeTrue();
});
