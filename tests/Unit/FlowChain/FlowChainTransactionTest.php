<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Unit\FlowChain;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use InOtherShops\FlowChain\Contracts\FlowPayload;
use InOtherShops\FlowChain\Contracts\FlowStep;
use InOtherShops\FlowChain\Enums\FlowChainStatus;
use InOtherShops\FlowChain\FlowChain;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class FlowChainTransactionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('flowchain_rollback_probes');
        Schema::create('flowchain_rollback_probes', function (Blueprint $table): void {
            $table->id();
            $table->string('marker');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('flowchain_rollback_probes');

        parent::tearDown();
    }

    #[Test]
    public function failed_step_rolls_back_writes_from_earlier_steps_when_wrapped_in_transaction(): void
    {
        $result = FlowChain::make()
            ->name('test')
            ->wrapInTransaction()
            ->step(WriteProbeStep::class)
            ->step(ThrowingStep::class)
            ->run(new ProbePayload);

        $this->assertSame(FlowChainStatus::Failed, $result->status);
        $this->assertSame(0, DB::table('flowchain_rollback_probes')->count(),
            'Writes from earlier steps must be rolled back when a later step fails.');
    }

    #[Test]
    public function successful_chain_persists_writes_when_wrapped_in_transaction(): void
    {
        $result = FlowChain::make()
            ->name('test')
            ->wrapInTransaction()
            ->step(WriteProbeStep::class)
            ->step(WriteProbeStep::class)
            ->run(new ProbePayload);

        $this->assertSame(FlowChainStatus::Completed, $result->status);
        $this->assertSame(2, DB::table('flowchain_rollback_probes')->count());
    }

    #[Test]
    public function failed_step_does_not_roll_back_when_transaction_is_disabled(): void
    {
        $result = FlowChain::make()
            ->name('test')
            ->step(WriteProbeStep::class)
            ->step(ThrowingStep::class)
            ->run(new ProbePayload);

        $this->assertSame(FlowChainStatus::Failed, $result->status);
        $this->assertSame(1, DB::table('flowchain_rollback_probes')->count(),
            'Without wrapInTransaction(), earlier step writes persist — that is the contract for non-transactional chains.');
    }
}

final class ProbePayload implements FlowPayload {}

final class WriteProbeStep implements FlowStep
{
    public function handle(FlowPayload $payload): void
    {
        DB::table('flowchain_rollback_probes')->insert([
            'marker' => 'written',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

final class ThrowingStep implements FlowStep
{
    public function handle(FlowPayload $payload): void
    {
        throw new RuntimeException('intentional failure for test');
    }
}
