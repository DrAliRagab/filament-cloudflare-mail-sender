<?php

declare(strict_types=1);

use DrAliRagab\FilamentCloudflareMailSender\Contracts\RecipientSuggestionProvider;
use DrAliRagab\FilamentCloudflareMailSender\Recipients\EloquentRecipientSuggestionProvider;
use DrAliRagab\FilamentCloudflareMailSender\Tests\Fixtures\AbstractRecipientUser;
use DrAliRagab\FilamentCloudflareMailSender\Tests\Fixtures\ConstructorRecipientUser;
use DrAliRagab\FilamentCloudflareMailSender\Tests\Fixtures\IntegerRecipientUser;
use DrAliRagab\FilamentCloudflareMailSender\Tests\Fixtures\UlidRecipientUser;
use DrAliRagab\FilamentCloudflareMailSender\Tests\Fixtures\UuidRecipientUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('integer_recipient_users', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('email');
        $table->string('full_name');
    });

    Schema::create('uuid_recipient_users', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('email');
        $table->string('full_name');
    });

    Schema::create('ulid_recipient_users', function (Blueprint $table): void {
        $table->ulid('id')->primary();
        $table->string('email');
        $table->string('full_name');
    });
});

afterEach(function (): void {
    Schema::dropIfExists('integer_recipient_users');
    Schema::dropIfExists('uuid_recipient_users');
    Schema::dropIfExists('ulid_recipient_users');
});

test('it suggests configured recipient model email values without reading model keys', function (): void {
    IntegerRecipientUser::query()->create([
        'email' => 'ada@example.test',
        'full_name' => 'Ada Lovelace',
    ]);
    UuidRecipientUser::query()->create([
        'id' => 'b2b3e6ce-5b2a-41df-b7b1-9f19c2e3b6f2',
        'email' => 'grace@example.test',
        'full_name' => 'Grace Hopper',
    ]);
    UlidRecipientUser::query()->create([
        'id' => '01J4VJZ1RHCEBKJ6D7PKSRTM4A',
        'email' => 'linus@example.test',
        'full_name' => 'Linus Torvalds',
    ]);

    foreach ([IntegerRecipientUser::class, UuidRecipientUser::class, UlidRecipientUser::class] as $model) {
        $suggestions = new EloquentRecipientSuggestionProvider(
            model: $model,
            emailColumn: 'email',
            nameColumn: 'full_name',
            limit: 10,
        );

        $suggestion = $suggestions->suggest()[0];

        expect($suggestion->email)->toContain('@example.test')
            ->and($suggestion->label)->toContain('<')
            ->and($suggestion->label)->toContain($suggestion->email);
    }
});

test('it searches configured columns and applies the configured limit', function (): void {
    IntegerRecipientUser::query()->create([
        'email' => 'ada@example.test',
        'full_name' => 'Ada Lovelace',
    ]);
    IntegerRecipientUser::query()->create([
        'email' => 'alan@example.test',
        'full_name' => 'Alan Turing',
    ]);

    $suggestions = new EloquentRecipientSuggestionProvider(
        model: IntegerRecipientUser::class,
        emailColumn: 'email',
        nameColumn: 'full_name',
        limit: 1,
    );

    expect($suggestions->suggest('Ada'))->toHaveCount(1)
        ->and($suggestions->suggest('Ada')[0]->email)->toBe('ada@example.test')
        ->and($suggestions->suggest())->toHaveCount(1);
});

test('it returns no suggestions when the configured model is invalid', function (): void {
    $suggestions = new EloquentRecipientSuggestionProvider(
        model: 'App\\Models\\NotAnEloquentModel',
        emailColumn: 'email',
        nameColumn: 'name',
        limit: 10,
    );

    expect($suggestions->suggest())->toBe([]);
});

test('it returns no suggestions when the configured Eloquent model cannot be instantiated', function (): void {
    foreach ([AbstractRecipientUser::class, ConstructorRecipientUser::class] as $model) {
        $suggestions = new EloquentRecipientSuggestionProvider(
            model: $model,
            emailColumn: 'email',
            nameColumn: 'name',
            limit: 10,
        );

        expect($suggestions->suggest())->toBe([]);
    }
});

test('the package resolves its configured recipient suggestion provider', function (): void {
    expect(resolve(RecipientSuggestionProvider::class))->toBeInstanceOf(EloquentRecipientSuggestionProvider::class);
});
