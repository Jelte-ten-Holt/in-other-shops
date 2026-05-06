<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Pricing;

use InOtherShops\Currency\Enums\Currency;
use InOtherShops\Pricing\Actions\ApplyVoucher;
use InOtherShops\Pricing\Events\VoucherApplied;
use InOtherShops\Pricing\Exceptions\PricingException;
use InOtherShops\Pricing\Exceptions\VoucherInvalidException;
use InOtherShops\Pricing\Exceptions\VoucherNotFoundException;
use InOtherShops\Pricing\Models\Voucher;
use InOtherShops\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

final class ApplyVoucherTest extends TestCase
{
    use RefreshDatabase;

    private ApplyVoucher $apply;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apply = new ApplyVoucher;
    }

    #[Test]
    public function it_increments_usage_on_successful_apply(): void
    {
        $voucher = Voucher::factory()->create(['code' => 'GO', 'times_used' => 0]);

        $applied = ($this->apply)(5000, 'GO', Currency::EUR);

        $this->assertSame(1, $applied->times_used);
        $this->assertSame(1, $voucher->fresh()->times_used);
    }

    #[Test]
    public function it_throws_when_voucher_is_at_max_uses(): void
    {
        Voucher::factory()->withMaxUses(max: 2, used: 2)->create(['code' => 'FULL']);

        $this->expectException(VoucherInvalidException::class);

        ($this->apply)(5000, 'FULL', Currency::EUR);
    }

    #[Test]
    public function it_does_not_exceed_max_uses_under_sequential_applies(): void
    {
        Voucher::factory()->withMaxUses(max: 2, used: 0)->create(['code' => 'TWICE']);

        ($this->apply)(5000, 'TWICE', Currency::EUR);
        ($this->apply)(5000, 'TWICE', Currency::EUR);

        $this->assertSame(2, Voucher::query()->where('code', 'TWICE')->value('times_used'));

        $this->expectException(VoucherInvalidException::class);
        ($this->apply)(5000, 'TWICE', Currency::EUR);
    }

    #[Test]
    public function it_does_not_increment_usage_when_validation_fails(): void
    {
        $voucher = Voucher::factory()->expired()->create(['code' => 'OLD', 'times_used' => 3]);

        try {
            ($this->apply)(5000, 'OLD', Currency::EUR);
            $this->fail('Expected VoucherInvalidException.');
        } catch (PricingException) {
            // expected
        }

        $this->assertSame(3, $voucher->fresh()->times_used,
            'Failed validation must not increment usage.');
    }

    #[Test]
    public function the_increment_rolls_back_when_an_outer_transaction_fails(): void
    {
        $voucher = Voucher::factory()->create(['code' => 'ROLL', 'times_used' => 0]);

        try {
            DB::transaction(function (): void {
                ($this->apply)(5000, 'ROLL', Currency::EUR);
                throw new \RuntimeException('simulated order-creation failure');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, $voucher->fresh()->times_used,
            'When the outer transaction rolls back, the increment must roll back too.');
    }

    #[Test]
    public function it_dispatches_voucher_applied_on_success(): void
    {
        Event::fake([VoucherApplied::class]);
        Voucher::factory()->create(['code' => 'EVT']);

        ($this->apply)(5000, 'EVT', Currency::EUR);

        Event::assertDispatched(VoucherApplied::class, 1);
    }

    #[Test]
    public function it_does_not_dispatch_voucher_applied_when_validation_fails(): void
    {
        Event::fake([VoucherApplied::class]);
        Voucher::factory()->inactive()->create(['code' => 'NOPE']);

        try {
            ($this->apply)(5000, 'NOPE', Currency::EUR);
        } catch (PricingException) {
            // expected
        }

        Event::assertNotDispatched(VoucherApplied::class);
    }

    #[Test]
    public function it_throws_when_voucher_does_not_exist(): void
    {
        $this->expectException(VoucherNotFoundException::class);
        $this->expectExceptionMessage('Voucher [GHOST] not found.');

        ($this->apply)(5000, 'GHOST', Currency::EUR);
    }
}
