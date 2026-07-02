<?php

declare(strict_types=1);

namespace InOtherShops\Payment\Enums;

use InOtherShops\Support\HasLabel;

enum PaymentStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Succeeded => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'warning',
            self::Expired => 'gray',
            self::Refunded => 'danger',
            self::PartiallyRefunded => 'warning',
        };
    }
}
