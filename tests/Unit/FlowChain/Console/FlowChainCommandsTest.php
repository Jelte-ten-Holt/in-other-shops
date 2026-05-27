<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Unit\FlowChain\Console;

use Illuminate\Support\Facades\Artisan;
use InOtherShops\FlowChain\FlowChainRegistry;
use InOtherShops\Tests\Fixtures\FlowChain\Package\Cart\SampleChain as PackageSampleChain;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Smoke tests for the four FlowChain console commands. Each command has
 * one happy-path assertion confirming it wires up correctly through the
 * service container; detailed behavior is covered by the unit tests on
 * the underlying components (FlowChainRegistry, ChainContractValidator).
 */
final class FlowChainCommandsTest extends TestCase
{
    #[Test]
    public function list_command_includes_registered_chains(): void
    {
        app(FlowChainRegistry::class)->register(PackageSampleChain::class);

        $exitCode = Artisan::call('flowchain:list');

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Cart/SampleChain', $output);
        $this->assertStringContainsString('package default', $output);
    }

    #[Test]
    public function check_contracts_command_passes_for_a_well_formed_chain(): void
    {
        app(FlowChainRegistry::class)->register(PackageSampleChain::class);

        $exitCode = Artisan::call('flowchain:check-contracts');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('validated successfully', Artisan::output());
    }

    #[Test]
    public function verify_tests_command_passes_when_no_chains_are_published(): void
    {
        app(FlowChainRegistry::class)->register(PackageSampleChain::class);

        $exitCode = Artisan::call('flowchain:verify-tests');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('All published chains have test classes', Artisan::output());
    }

    #[Test]
    public function publish_command_rejects_non_publishable_classes(): void
    {
        $exitCode = Artisan::call('flowchain:publish', [
            'chain' => self::class,  // a real class, but not a PublishableFlowChain
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('must extend', Artisan::output());
    }

    #[Test]
    public function publish_command_rejects_nonexistent_classes(): void
    {
        $exitCode = Artisan::call('flowchain:publish', [
            'chain' => 'NonExistent\\Class\\Name',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('does not exist', Artisan::output());
    }
}
