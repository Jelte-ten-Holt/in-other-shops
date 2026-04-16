<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Stubs;

use InOtherShops\Payment\Concerns\InteractsWithPayments;
use InOtherShops\Payment\Contracts\HasPayments;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class TestPayable extends Model implements HasPayments
{
    use HasFactory;
    use InteractsWithPayments;

    protected $guarded = [];

    protected $table = 'test_payables';

    protected static function newFactory(): Factory
    {
        return new TestPayableFactory;
    }

    protected function casts(): array
    {
        return [
            'total_due' => 'integer',
        ];
    }

    public function getPaymentTotalDue(): int
    {
        return (int) $this->total_due;
    }
}
