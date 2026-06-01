<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Order\DTOs;

/**
 * One person who pre-ordered a purchasable, resolved for notification. Email is
 * already normalized (trimmed + lower-cased) and is the dedup key. `customerId`
 * is null for guest checkouts; `locale` is the order's locale snapshot, for
 * localized mail.
 */
final readonly class PreOrderRecipient
{
    public function __construct(
        public string $email,
        public ?string $name,
        public ?string $locale,
        public ?int $customerId,
    ) {}
}
