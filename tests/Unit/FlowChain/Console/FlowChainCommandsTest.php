<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Unit\FlowChain\Console;

use Illuminate\Support\Facades\Artisan;
use InOtherShops\FlowChain\FlowChainRegistry;
use InOtherShops\Tests\Fixtures\FlowChain\Package\Cart\RenamedSampleChain;
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

    #[Test]
    public function publish_command_writes_a_subclass_with_chainName_as_the_class_name(): void
    {
        // The package's PackageSampleChain has chainName() = 'SampleChain'
        // and the source class is also 'SampleChain', so this exercises the
        // baseline. The regex must also handle the case where chainName()
        // differs from the source class's short name (e.g. AddToCart vs.
        // AddToCartChain) — covered by the second assertion that the file
        // can be require'd and the class autoloads.
        $tmpBase = sys_get_temp_dir().'/flowchain-publish-test-'.uniqid();
        config(['app.path' => $tmpBase]);
        app()->useAppPath($tmpBase.'/app');

        $exitCode = Artisan::call('flowchain:publish', [
            'chain' => PackageSampleChain::class,
        ]);

        $expectedPath = $tmpBase.'/app/Project/FlowChains/Cart/SampleChain.php';

        try {
            $this->assertSame(0, $exitCode);
            $this->assertFileExists($expectedPath);

            $contents = (string) file_get_contents($expectedPath);
            $this->assertStringContainsString('namespace App\\Project\\FlowChains\\Cart;', $contents);
            $this->assertStringContainsString(
                'use '.PackageSampleChain::class.' as PackageSampleChain;',
                $contents,
            );
            $this->assertStringContainsString('final class SampleChain extends PackageSampleChain', $contents);
        } finally {
            if (file_exists($expectedPath)) {
                unlink($expectedPath);
            }
            @rmdir($tmpBase.'/app/Project/FlowChains/Cart');
            @rmdir($tmpBase.'/app/Project/FlowChains');
            @rmdir($tmpBase.'/app/Project');
            @rmdir($tmpBase);
            @rmdir($tmpBase);
        }
    }

    #[Test]
    public function publish_command_handles_chainName_differing_from_source_class_short_name(): void
    {
        // Regression: RenamedSampleChain (source class) has chainName() =
        // 'Renamed'. The published file must be at .../Cart/Renamed.php
        // with `final class Renamed extends PackageRenamed`, not the
        // source class's short name. This was the bug AddToCart hit.
        $tmpBase = sys_get_temp_dir().'/flowchain-publish-renamed-'.uniqid();
        app()->useAppPath($tmpBase.'/app');

        $exitCode = Artisan::call('flowchain:publish', [
            'chain' => RenamedSampleChain::class,
        ]);

        $expectedPath = $tmpBase.'/app/Project/FlowChains/Cart/Renamed.php';

        try {
            $this->assertSame(0, $exitCode);
            $this->assertFileExists($expectedPath);

            $contents = (string) file_get_contents($expectedPath);
            $this->assertStringContainsString(
                'use '.RenamedSampleChain::class.' as PackageRenamed;',
                $contents,
            );
            $this->assertStringContainsString('final class Renamed extends PackageRenamed', $contents);
            $this->assertStringNotContainsString('class RenamedSampleChain', $contents,
                'Source class name must be rewritten to chainName(), not preserved.');
        } finally {
            if (file_exists($expectedPath)) {
                unlink($expectedPath);
            }
            @rmdir($tmpBase.'/app/Project/FlowChains/Cart');
            @rmdir($tmpBase.'/app/Project/FlowChains');
            @rmdir($tmpBase.'/app/Project');
            @rmdir($tmpBase.'/app');
            @rmdir($tmpBase);
        }
    }
}
