<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Pricing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InOtherShops\Pricing\Actions\ReleaseVoucher;
use InOtherShops\Pricing\Events\VoucherReleased;
use InOtherShops\Pricing\Models\Voucher;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ReleaseVoucherTest extends TestCase
{
    use RefreshDatabase;

    private ReleaseVoucher $release;

    protected function setUp(): void
    {
        parent::setUp();

        $this->release = new ReleaseVoucher;
    }

    #[Test]
    public function it_gives_a_use_back(): void
    {
        Voucher::factory()->withMaxUses(max: 10, used: 4)->create(['code' => 'GIVEBACK']);

        ($this->release)('GIVEBACK');

        $this->assertSame(3, Voucher::query()->where('code', 'GIVEBACK')->value('times_used'));
    }

    #[Test]
    public function it_matches_the_code_case_insensitively(): void
    {
        // Callers hand it whatever was snapshotted on the order, which came
        // from a shopper's typing before normalization existed.
        Voucher::factory()->withMaxUses(max: 10, used: 4)->create(['code' => 'GIVEBACK']);

        ($this->release)(' giveback ');

        $this->assertSame(3, Voucher::query()->where('code', 'GIVEBACK')->value('times_used'));
    }

    #[Test]
    public function it_never_drives_the_counter_negative(): void
    {
        // An unused voucher being released means something upstream released
        // twice. Floor rather than throw: the caller is a status-change
        // listener with no way to recover, and a negative count would read as
        // free uses for everyone.
        Voucher::factory()->create(['code' => 'FRESH', 'times_used' => 0]);

        ($this->release)('FRESH');

        $this->assertSame(0, Voucher::query()->where('code', 'FRESH')->value('times_used'));
    }

    #[Test]
    public function an_unknown_code_is_a_no_op_rather_than_an_error(): void
    {
        // The voucher may have been deleted since the order was placed. There
        // is nothing to give back, and throwing here would break a cancel.
        Event::fake([VoucherReleased::class]);

        $this->assertNull(($this->release)('GHOST'));

        Event::assertNotDispatched(VoucherReleased::class);
    }

    #[Test]
    public function it_dispatches_voucher_released_for_the_audit_trail(): void
    {
        Event::fake([VoucherReleased::class]);

        Voucher::factory()->withMaxUses(max: 10, used: 1)->create(['code' => 'AUDITED']);

        ($this->release)('AUDITED');

        Event::assertDispatched(VoucherReleased::class);
    }
}
