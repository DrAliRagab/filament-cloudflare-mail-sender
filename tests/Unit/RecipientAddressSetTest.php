<?php

declare(strict_types=1);

use DrAliRagab\FilamentCloudflareMailSender\Exceptions\MissingToRecipient;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\RecipientLimitExceeded;
use DrAliRagab\FilamentCloudflareMailSender\Support\RecipientAddressSet;

test('it trims addresses and deduplicates them with To CC BCC precedence', function (): void {
    $recipients = RecipientAddressSet::from(
        to: [' Primary@Example.test ', 'primary@example.test'],
        cc: ['primary@example.test', 'Copy@Example.test'],
        bcc: ['copy@example.test', 'Hidden@Example.test'],
    );

    expect($recipients->to())->toBe(['Primary@Example.test'])
        ->and($recipients->cc())->toBe(['Copy@Example.test'])
        ->and($recipients->bcc())->toBe(['Hidden@Example.test'])
        ->and($recipients->count())->toBe(3)
        ->and($recipients->duplicates())->toHaveCount(3)
        ->and($recipients->duplicates()[0]->keptIn)->toBe('to')
        ->and($recipients->duplicates()[1]->keptIn)->toBe('to')
        ->and($recipients->duplicates()[2]->keptIn)->toBe('cc');
});

test('it accepts arbitrary valid email addresses', function (): void {
    $recipients = RecipientAddressSet::from(
        to: ['outside-the-user-model@example.test'],
        cc: [],
        bcc: [],
    );

    expect($recipients->to())->toBe(['outside-the-user-model@example.test']);
});

test('it requires a To recipient after normalization', function (): void {
    $exception = null;

    try {
        RecipientAddressSet::from(
            to: [],
            cc: ['copy@example.test'],
            bcc: ['hidden@example.test'],
        );
    } catch (MissingToRecipient $caught) {
        $exception = $caught;
    }

    if (! $exception instanceof MissingToRecipient) {
        throw new LogicException('A missing To recipient was not rejected.');
    }

    expect($exception->field())->toBe('to');
});

test('it rejects more than Cloudflare combined recipient limit', function (): void {
    $addresses = array_map(
        static fn (int $number): string => "recipient-{$number}@example.test",
        range(1, 51),
    );

    expect(fn (): RecipientAddressSet => RecipientAddressSet::from(
        to: array_slice($addresses, 0, 20),
        cc: array_slice($addresses, 20, 15),
        bcc: array_slice($addresses, 35),
    ))->toThrow(RecipientLimitExceeded::class);
});
