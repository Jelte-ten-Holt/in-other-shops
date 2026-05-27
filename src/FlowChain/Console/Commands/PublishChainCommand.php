<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Console\Commands;

use Illuminate\Console\Command;
use InOtherShops\FlowChain\FlowChainRegistry;
use InOtherShops\FlowChain\PublishableFlowChain;

/**
 * Copies a chain definition into the consumer's app/Project/FlowChains/
 * tree so it can be modified locally. Only the chain class itself is
 * copied — referenced step classes stay in the package and the consumer's
 * published chain references them by FQN.
 */
final class PublishChainCommand extends Command
{
    protected $signature = 'flowchain:publish
        {chain : Fully-qualified package chain class to publish (e.g. InOtherShops\\Commerce\\Cart\\FlowChains\\AddToCartChain)}
        {--force : Overwrite an existing published file at the destination path}';

    protected $description = 'Copy a package FlowChain into app/Project/FlowChains/{Domain}/ so it can be modified locally.';

    public function handle(FlowChainRegistry $registry): int
    {
        $chainClass = $this->validateChainClass();

        if ($chainClass === null) {
            return self::FAILURE;
        }

        $sourcePath = $this->resolveSourcePath($chainClass);

        if ($sourcePath === null) {
            $this->error("Could not locate source file for {$chainClass}. The class must be loadable via Composer's PSR-4 autoloader.");

            return self::FAILURE;
        }

        $destinationPath = $this->resolveDestinationPath($chainClass);

        if (file_exists($destinationPath) && ! $this->option('force')) {
            $this->error("Destination already exists: {$destinationPath}");
            $this->line('Pass --force to overwrite, or delete the file manually if you want to start over.');

            return self::FAILURE;
        }

        $this->ensureDirectoryExists(dirname($destinationPath));

        $contents = $this->transformSource(
            sourcePath: $sourcePath,
            chainClass: $chainClass,
        );

        file_put_contents($destinationPath, $contents);

        $this->info("Published: {$destinationPath}");
        $this->line('');
        $this->line('Next steps:');
        $this->line('  1. Edit the published file to add/remove/reorder steps.');
        $this->line('  2. The chain class extends the package class — chainName(), domain(),');
        $this->line('     and initialPayloadShape() inherit. Override steps() to customize.');
        $this->line('  3. Referenced step classes stay in the package. To modify a step\'s');
        $this->line('     internals, copy its file from vendor/ into your project namespace');
        $this->line('     and update your published chain to reference your copy.');
        $this->line('  4. Run `php artisan flowchain:check-contracts` to verify your changes.');
        $this->line('  5. Add a test class at Tests\\Feature\\FlowChains\\'.$chainClass::chainName().'Test');
        $this->line('     so `flowchain:verify-tests` stays green.');

        return self::SUCCESS;
    }

    /**
     * @return class-string<PublishableFlowChain>|null
     */
    private function validateChainClass(): ?string
    {
        $chainClass = (string) $this->argument('chain');

        if (! class_exists($chainClass)) {
            $this->error("Class does not exist: {$chainClass}");

            return null;
        }

        if (! is_subclass_of($chainClass, PublishableFlowChain::class)) {
            $this->error("Class {$chainClass} must extend ".PublishableFlowChain::class.' to be publishable.');

            return null;
        }

        /** @var class-string<PublishableFlowChain> */
        return $chainClass;
    }

    /**
     * @param  class-string<PublishableFlowChain>  $chainClass
     */
    private function resolveSourcePath(string $chainClass): ?string
    {
        $reflection = new \ReflectionClass($chainClass);
        $path = $reflection->getFileName();

        return $path !== false ? $path : null;
    }

    /**
     * @param  class-string<PublishableFlowChain>  $chainClass
     */
    private function resolveDestinationPath(string $chainClass): string
    {
        return app_path('Project/FlowChains/'.$chainClass::domain().'/'.$chainClass::chainName().'.php');
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }
    }

    /**
     * @param  class-string<PublishableFlowChain>  $chainClass
     */
    private function transformSource(string $sourcePath, string $chainClass): string
    {
        $originalSource = (string) file_get_contents($sourcePath);

        $shortName = $chainClass::chainName();
        $newNamespace = 'App\\Project\\FlowChains\\'.$chainClass::domain();

        // Rewrite namespace and alias the package class as Package{chainName}
        // so the published `extends Package{chainName}` resolves regardless
        // of what the source class was actually named (chainName() doesn't
        // have to match the source class's short name).
        $rewritten = preg_replace(
            '/^namespace\s+[^;]+;/m',
            "namespace {$newNamespace};\n\nuse {$chainClass} as Package{$shortName};",
            $originalSource,
            limit: 1,
        );

        // Rewrite the class declaration to use chainName() as the class
        // name, regardless of what the source called it. Strips abstract/final
        // modifiers, normalizes to `final` (published copies are leaves), and
        // sets the parent to the imported alias. Any `implements` clause on
        // the source is preserved.
        $rewritten = (string) preg_replace(
            '/(?:final\s+|abstract\s+)?class\s+\w+\s+extends\s+\w+/',
            "final class {$shortName} extends Package{$shortName}",
            (string) $rewritten,
            limit: 1,
        );

        return $rewritten;
    }
}
