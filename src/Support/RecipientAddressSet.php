<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Support;

use DrAliRagab\FilamentCloudflareMailSender\Data\DuplicateRecipientAddress;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\InvalidRecipientAddress;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\MissingToRecipient;
use DrAliRagab\FilamentCloudflareMailSender\Exceptions\RecipientLimitExceeded;

final readonly class RecipientAddressSet
{
    private const MAX_RECIPIENTS = 50;

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @param  list<DuplicateRecipientAddress>  $duplicates
     */
    private function __construct(
        private array $to,
        private array $cc,
        private array $bcc,
        private array $duplicates,
    ) {}

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     */
    public static function from(array $to, array $cc, array $bcc): self
    {
        /** @var array<string, string> $keptIn */
        $keptIn = [];
        /** @var list<DuplicateRecipientAddress> $duplicates */
        $duplicates = [];

        $normalizedTo = self::normalize($to, 'to', $keptIn, $duplicates);
        $normalizedCc = self::normalize($cc, 'cc', $keptIn, $duplicates);
        $normalizedBcc = self::normalize($bcc, 'bcc', $keptIn, $duplicates);

        if ($normalizedTo === []) {
            throw new MissingToRecipient;
        }

        $count = count($normalizedTo) + count($normalizedCc) + count($normalizedBcc);

        if ($count > self::MAX_RECIPIENTS) {
            throw new RecipientLimitExceeded($count, self::MAX_RECIPIENTS);
        }

        return new self($normalizedTo, $normalizedCc, $normalizedBcc, $duplicates);
    }

    /**
     * @return list<string>
     */
    public function to(): array
    {
        return $this->to;
    }

    /**
     * @return list<string>
     */
    public function cc(): array
    {
        return $this->cc;
    }

    /**
     * @return list<string>
     */
    public function bcc(): array
    {
        return $this->bcc;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return [...$this->to, ...$this->cc, ...$this->bcc];
    }

    public function count(): int
    {
        return count($this->to) + count($this->cc) + count($this->bcc);
    }

    /**
     * @return list<DuplicateRecipientAddress>
     */
    public function duplicates(): array
    {
        return $this->duplicates;
    }

    /**
     * @param  list<string>  $addresses
     * @param  array<string, string>  $keptIn
     * @param  list<DuplicateRecipientAddress>  $duplicates
     * @return list<string>
     */
    private static function normalize(array $addresses, string $field, array &$keptIn, array &$duplicates): array
    {
        $normalized = [];

        foreach ($addresses as $address) {
            $address = trim($address);

            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidRecipientAddress($field, $address);
            }

            $comparisonAddress = strtolower($address);

            if (isset($keptIn[$comparisonAddress])) {
                $duplicates[] = new DuplicateRecipientAddress(
                    email: $address,
                    keptIn: $keptIn[$comparisonAddress],
                    discardedFrom: $field,
                );

                continue;
            }

            $keptIn[$comparisonAddress] = $field;
            $normalized[] = $address;
        }

        return $normalized;
    }
}
