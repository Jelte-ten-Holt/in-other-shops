<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Unit\FlowChain;

use InOtherShops\FlowChain\Exceptions\BrokenPublishedChain;
use InOtherShops\FlowChain\FlowChainRegistry;
use InOtherShops\Tests\Fixtures\FlowChain\Package\Cart\SampleChain as PackageSampleChain;
use InOtherShops\Tests\Fixtures\FlowChain\Published\Cart\SampleChain as PublishedSampleChain;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class FlowChainRegistryTest extends TestCase
{
    #[Test]
    public function returns_package_class_when_no_published_file_exists(): void
    {
        $registry = $this->registryPointedAt(__DIR__.'/non-existent-publish-base');

        $resolved = $registry->resolve(PackageSampleChain::class);

        $this->assertSame(PackageSampleChain::class, $resolved);
    }

    #[Test]
    public function returns_published_class_when_file_exists_and_is_valid_subclass(): void
    {
        $registry = $this->registryPointedAt(
            basePath: $this->fixtureBasePath('Published'),
            baseNamespace: 'InOtherShops\\Tests\\Fixtures\\FlowChain\\Published',
        );

        $resolved = $registry->resolve(PackageSampleChain::class);

        $this->assertSame(PublishedSampleChain::class, $resolved);
    }

    #[Test]
    public function is_published_reports_published_status_correctly(): void
    {
        $unpublished = $this->registryPointedAt(__DIR__.'/non-existent');
        $published = $this->registryPointedAt(
            basePath: $this->fixtureBasePath('Published'),
            baseNamespace: 'InOtherShops\\Tests\\Fixtures\\FlowChain\\Published',
        );

        $this->assertFalse($unpublished->isPublished(PackageSampleChain::class));
        $this->assertTrue($published->isPublished(PackageSampleChain::class));
    }

    #[Test]
    public function resolution_is_cached_so_repeated_calls_dont_restat(): void
    {
        $tmpBase = sys_get_temp_dir().'/flowchain-registry-test-'.uniqid();
        mkdir($tmpBase.'/Cart', recursive: true);

        $registry = $this->registryPointedAt($tmpBase);

        $first = $registry->resolve(PackageSampleChain::class);
        $this->assertSame(PackageSampleChain::class, $first);

        // Create the file AFTER first resolution. The second resolve should
        // still return the package class because the resolution is cached.
        // This is the contract: the registry caches per-instance to avoid
        // repeated filesystem stats. Restart the app (or instantiate a fresh
        // registry) to pick up new publishes.
        file_put_contents($tmpBase.'/Cart/SampleChain.php', '<?php // placeholder');

        $second = $registry->resolve(PackageSampleChain::class);
        $this->assertSame(PackageSampleChain::class, $second);

        // Cleanup
        unlink($tmpBase.'/Cart/SampleChain.php');
        rmdir($tmpBase.'/Cart');
        rmdir($tmpBase);
    }

    #[Test]
    public function throws_when_published_file_exists_but_class_does_not_autoload(): void
    {
        $tmpBase = sys_get_temp_dir().'/flowchain-registry-test-'.uniqid();
        mkdir($tmpBase.'/Cart', recursive: true);

        // File at the expected publish path, but it declares no class that
        // matches the expected FQN. The registry can't autoload it.
        file_put_contents(
            $tmpBase.'/Cart/SampleChain.php',
            "<?php\n// intentionally empty — no class declared\n",
        );

        $registry = $this->registryPointedAt(
            basePath: $tmpBase,
            baseNamespace: 'NonExistent\\Namespace',
        );

        try {
            $registry->resolve(PackageSampleChain::class);
            $this->fail('Expected BrokenPublishedChain.');
        } catch (BrokenPublishedChain $e) {
            $this->assertStringContainsString($tmpBase.'/Cart/SampleChain.php', $e->getMessage());
            $this->assertStringContainsString('NonExistent\\Namespace\\Cart\\SampleChain', $e->getMessage());
        } finally {
            unlink($tmpBase.'/Cart/SampleChain.php');
            rmdir($tmpBase.'/Cart');
            rmdir($tmpBase);
        }
    }

    #[Test]
    public function throws_when_published_class_is_not_a_subclass_of_the_package_class(): void
    {
        // The fixture base contains a SampleChain at the right path, but
        // we lie and claim a different package class — so the registry
        // finds the published class, but it isn't a subclass.
        $registry = $this->registryPointedAt(
            basePath: $this->fixtureBasePath('Published'),
            baseNamespace: 'InOtherShops\\Tests\\Fixtures\\FlowChain\\Published',
        );

        try {
            $registry->resolve(UnrelatedPackageChain::class);
            $this->fail('Expected BrokenPublishedChain.');
        } catch (BrokenPublishedChain $e) {
            $this->assertStringContainsString('must extend', $e->getMessage());
            $this->assertStringContainsString(UnrelatedPackageChain::class, $e->getMessage());
        }
    }

    private function registryPointedAt(string $basePath, string $baseNamespace = 'App\\Project\\FlowChains'): FlowChainRegistry
    {
        return new FlowChainRegistry($basePath, $baseNamespace);
    }

    private function fixtureBasePath(string $folder): string
    {
        return __DIR__.'/../../Fixtures/FlowChain/'.$folder;
    }
}

/**
 * A SampleChain-shaped chain in an unrelated inheritance line, used to
 * verify the "not a subclass" branch of FlowChainRegistry::resolve().
 *
 * Declares the same chainName + domain as the fixture PackageSampleChain
 * so it discovers the same published file path.
 */
final class UnrelatedPackageChain extends \InOtherShops\FlowChain\PublishableFlowChain
{
    public static function chainName(): string
    {
        return 'SampleChain';
    }

    public static function domain(): string
    {
        return 'Cart';
    }

    public static function initialPayloadShape(): array
    {
        return [];
    }

    public static function steps(): array
    {
        return [];
    }
}
