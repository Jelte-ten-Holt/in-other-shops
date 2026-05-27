<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain;

use InOtherShops\FlowChain\Exceptions\BrokenPublishedChain;

/**
 * Resolves a PublishableFlowChain class — returns the consumer's published
 * subclass if present, otherwise the package default.
 *
 * Discovery is file-existence at resolution time (not boot). Caches misses
 * AND hits per-class for the lifetime of the registry instance so repeated
 * resolutions don't repeatedly stat the filesystem.
 *
 * Published path convention:
 *   {publishBasePath}/{Domain}/{ChainName}.php
 *
 * Published class convention (must match exactly):
 *   {publishBaseNamespace}\{Domain}\{ChainName}
 *
 * Defaults: publishBasePath = app_path('Project/FlowChains'),
 * publishBaseNamespace = 'App\Project\FlowChains'. Override via the
 * constructor for tests or for projects with different conventions.
 */
final class FlowChainRegistry
{
    /** @var array<class-string<PublishableFlowChain>, class-string<PublishableFlowChain>> */
    private array $resolved = [];

    /** @var array<class-string<PublishableFlowChain>, true> */
    private array $registered = [];

    public function __construct(
        private readonly string $publishBasePath,
        private readonly string $publishBaseNamespace = 'App\\Project\\FlowChains',
    ) {}

    /**
     * Register a package-shipped chain so console commands (`flowchain:list`,
     * `flowchain:check-contracts`, `flowchain:verify-tests`) can enumerate
     * it. Each domain's service provider registers its own chains in boot().
     *
     * Registration is independent of resolution — resolve() works for any
     * PublishableFlowChain subclass, registered or not. Registration only
     * feeds enumeration.
     *
     * @param  class-string<PublishableFlowChain>  $chainClass
     */
    public function register(string $chainClass): void
    {
        $this->registered[$chainClass] = true;
    }

    /**
     * All registered package chain classes. Order is registration order;
     * console commands sort for display.
     *
     * @return list<class-string<PublishableFlowChain>>
     */
    public function all(): array
    {
        return array_keys($this->registered);
    }

    /**
     * @template T of PublishableFlowChain
     *
     * @param  class-string<T>  $packageChainClass
     * @return class-string<T>
     */
    public function resolve(string $packageChainClass): string
    {
        if (array_key_exists($packageChainClass, $this->resolved)) {
            /** @var class-string<T> */
            return $this->resolved[$packageChainClass];
        }

        $domain = $packageChainClass::domain();
        $chainName = $packageChainClass::chainName();

        $publishedPath = $this->publishBasePath
            .DIRECTORY_SEPARATOR.$domain
            .DIRECTORY_SEPARATOR.$chainName.'.php';

        if (! file_exists($publishedPath)) {
            return $this->resolved[$packageChainClass] = $packageChainClass;
        }

        $publishedClass = $this->publishBaseNamespace.'\\'.$domain.'\\'.$chainName;

        if (! class_exists($publishedClass)) {
            throw new BrokenPublishedChain(
                "Published chain file at {$publishedPath} exists but class {$publishedClass} "
                ."could not be autoloaded. Check the namespace declaration and class name in "
                ."the file — they must match the published path exactly. If the file is "
                ."intentionally placed elsewhere, remove it from this path so the registry "
                ."falls back to the package default."
            );
        }

        if (! is_subclass_of($publishedClass, $packageChainClass)) {
            throw new BrokenPublishedChain(
                "Published chain {$publishedClass} must extend {$packageChainClass} so that "
                ."chainName(), domain(), and initialPayloadShape() remain consistent. "
                ."Found class exists but is not a subclass — likely a manual edit that lost "
                ."the `extends` clause."
            );
        }

        /** @var class-string<T> $publishedClass */
        return $this->resolved[$packageChainClass] = $publishedClass;
    }

    /**
     * Whether the consumer has published a copy of the given chain. Useful
     * for diagnostic commands like `flowchain:list`.
     *
     * @param  class-string<PublishableFlowChain>  $packageChainClass
     */
    public function isPublished(string $packageChainClass): bool
    {
        return $this->resolve($packageChainClass) !== $packageChainClass;
    }
}
