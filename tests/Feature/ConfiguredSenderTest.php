<?php

declare(strict_types=1);

use DrAliRagab\FilamentCloudflareMailSender\Support\ConfiguredSender;

beforeEach(function (): void {
    config()->set('mail.from', [
        'address' => 'mailer@example.test',
        'name' => 'Mailer Default',
    ]);
    config()->set('filament-cloudflare-mail-sender.from', [
        'address' => null,
        'name' => null,
        'editable' => true,
        'allowed_domains' => [],
    ]);
});

test('package from defaults to editable so hosts must opt into a locked sender', function (): void {
    $defaults = require dirname(__DIR__, 2).'/config/filament-cloudflare-mail-sender.php';

    expect($defaults['from']['editable'])->toBeTrue()
        ->and(ConfiguredSender::editable())->toBeTrue();
});

test('configured sender address resolution', function (mixed $package, mixed $mail, ?string $expected): void {
    config()->set('filament-cloudflare-mail-sender.from.address', $package);
    config()->set('mail.from.address', $mail);

    expect(ConfiguredSender::address())->toBe($expected);
})->with([
    'package address wins' => ['package@example.test', 'mailer@example.test', 'package@example.test'],
    'null package falls back to mail' => [null, 'mailer@example.test', 'mailer@example.test'],
    'empty package falls back to mail' => ['', 'mailer@example.test', 'mailer@example.test'],
    'invalid package type does not fall back' => [123, 'mailer@example.test', null],
    'both missing' => [null, null, null],
    'empty mail fallback' => [null, '', null],
]);

test('configured sender name resolution', function (mixed $package, mixed $mail, ?string $expected): void {
    config()->set('filament-cloudflare-mail-sender.from.name', $package);
    config()->set('mail.from.name', $mail);

    expect(ConfiguredSender::name())->toBe($expected);
})->with([
    'package name wins' => ['Package Sender', 'Mailer Default', 'Package Sender'],
    'null package falls back to mail' => [null, 'Mailer Default', 'Mailer Default'],
    'empty package falls back to mail' => ['', 'Mailer Default', 'Mailer Default'],
    'invalid package type does not fall back' => [['Sender'], 'Mailer Default', null],
    'both missing' => [null, null, null],
]);

test('configured sender editable flag', function (mixed $editable, bool $expected): void {
    config()->set('filament-cloudflare-mail-sender.from.editable', $editable);

    expect(ConfiguredSender::editable())->toBe($expected);
})->with([
    'boolean true' => [true, true],
    'boolean false locks sender' => [false, false],
    'missing key stays editable' => [null, true],
    'string true is not trusted' => ['true', false],
    'integer one is not trusted' => [1, false],
]);
