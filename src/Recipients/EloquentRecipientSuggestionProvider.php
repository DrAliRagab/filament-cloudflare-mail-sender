<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Recipients;

use DrAliRagab\FilamentCloudflareMailSender\Contracts\RecipientSuggestionProvider;
use DrAliRagab\FilamentCloudflareMailSender\Data\RecipientSuggestion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

final readonly class EloquentRecipientSuggestionProvider implements RecipientSuggestionProvider
{
    public function __construct(
        private ?string $model,
        private string $emailColumn,
        private string $nameColumn,
        private int $limit,
    ) {}

    /**
     * @return list<RecipientSuggestion>
     */
    public function suggest(string $search = ''): array
    {
        $recipientModel = $this->recipientModel();

        if ($this->limit < 1 || $recipientModel === null) {
            return [];
        }

        $query = $recipientModel->newQuery();

        $search = trim($search);

        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $query
                    ->where($this->emailColumn, 'like', "%{$search}%")
                    ->orWhere($this->nameColumn, 'like', "%{$search}%");
            });
        }

        /** @var list<RecipientSuggestion> $suggestions */
        $suggestions = [];
        /** @var array<string, true> $seen */
        $seen = [];

        $query->orderBy($this->emailColumn);
        $query->limit($this->limit);

        foreach ($query->get([
            $this->emailColumn,
            $this->nameColumn,
        ]) as $recipient) {
            $email = $recipient->getAttribute($this->emailColumn);

            if (! is_string($email)) {
                continue;
            }

            $email = trim($email);

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $comparisonAddress = strtolower($email);

            if (isset($seen[$comparisonAddress])) {
                continue;
            }

            $seen[$comparisonAddress] = true;
            $name = $recipient->getAttribute($this->nameColumn);
            $name = is_string($name) ? trim($name) : '';

            $suggestions[] = new RecipientSuggestion(
                email: $email,
                label: $name === '' ? $email : "{$name} <{$email}>",
            );
        }

        return $suggestions;
    }

    private function recipientModel(): ?Model
    {
        if (! is_string($this->model) || ! class_exists($this->model)) {
            return null;
        }

        $reflection = new ReflectionClass($this->model);

        $constructor = $reflection->getConstructor();

        if (
            ! $reflection->isSubclassOf(Model::class)
            || $reflection->isAbstract()
            || ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0)
        ) {
            return null;
        }

        return $reflection->newInstance();
    }
}
