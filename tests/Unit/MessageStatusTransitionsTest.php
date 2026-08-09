<?php

declare(strict_types=1);

use DrAliRagab\FilamentCloudflareMailSender\Enums\MessageStatus;
use DrAliRagab\FilamentCloudflareMailSender\Support\MessageStatusTransitions;

test('it permits only the approved message lifecycle transitions', function (): void {
    expect(MessageStatusTransitions::canTransition(MessageStatus::Draft, MessageStatus::Queued))->toBeTrue()
        ->and(MessageStatusTransitions::canTransition(MessageStatus::Draft, MessageStatus::Cancelled))->toBeTrue()
        ->and(MessageStatusTransitions::canTransition(MessageStatus::Queued, MessageStatus::Sending))->toBeTrue()
        ->and(MessageStatusTransitions::canTransition(MessageStatus::Queued, MessageStatus::Cancelled))->toBeTrue()
        ->and(MessageStatusTransitions::canTransition(MessageStatus::Sending, MessageStatus::Sent))->toBeTrue()
        ->and(MessageStatusTransitions::canTransition(MessageStatus::Sending, MessageStatus::Failed))->toBeTrue()
        ->and(MessageStatusTransitions::canTransition(MessageStatus::Draft, MessageStatus::Sending))->toBeFalse()
        ->and(MessageStatusTransitions::canTransition(MessageStatus::Queued, MessageStatus::Sent))->toBeFalse()
        ->and(MessageStatusTransitions::canTransition(MessageStatus::Sending, MessageStatus::Sending))->toBeFalse();
});

test('it keeps terminal message statuses terminal', function (): void {
    foreach ([MessageStatus::Sent, MessageStatus::Failed, MessageStatus::Cancelled] as $status) {
        foreach (MessageStatus::cases() as $candidate) {
            expect(MessageStatusTransitions::canTransition($status, $candidate))->toBeFalse();
        }
    }
});

test('sent is labeled as accepted by Cloudflare', function (): void {
    expect(MessageStatus::Sent->label())->toBe('Accepted by Cloudflare');
});
