<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Unit\FlowChain;

use InOtherShops\FlowChain\PublishableFlowChain;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Guard against re-introducing `final` on PublishableFlowChain subclasses.
 *
 * The whole point of PublishableFlowChain is that consumers extend the
 * package class in their app/Project/FlowChains/ published copy. A
 * `final` modifier on a package chain class breaks `flowchain:publish`
 * (the generated published file says `extends Package{ChainName}` which
 * fails at autoload with "cannot extend final class").
 *
 * This bit at AddToCartChain's first real-world publish use. Test scans
 * src/ for all PublishableFlowChain subclasses and fails on any that are
 * final, so the publish workflow stays unbroken across future chains.
 */
final class PublishableChainNotFinalTest extends TestCase
{
    #[Test]
    public function no_publishable_chain_subclass_in_src_is_final(): void
    {
        $offenders = [];

        foreach ($this->scanForChains(srcDir: __DIR__.'/../../../src') as $chainClass) {
            $reflection = new ReflectionClass($chainClass);

            if ($reflection->isFinal()) {
                $offenders[] = $chainClass;
            }
        }

        if ($offenders === []) {
            $this->assertTrue(true);

            return;
        }

        $this->fail(
            "PublishableFlowChain subclasses must not be `final` — consumers extend\n"
            ."them via flowchain:publish. Remove `final` from:\n\n  "
            .implode("\n  ", $offenders)
        );
    }

    /**
     * @return iterable<class-string<PublishableFlowChain>>
     */
    private function scanForChains(string $srcDir): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (! preg_match('/namespace\s+([^;]+);/', $contents, $nsMatch)) {
                continue;
            }
            if (! preg_match('/(?:class|abstract class|final class)\s+(\w+)/', $contents, $classMatch)) {
                continue;
            }

            $fqcn = trim($nsMatch[1]).'\\'.$classMatch[1];

            if (! class_exists($fqcn)) {
                continue;
            }

            $reflection = new ReflectionClass($fqcn);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            if ($reflection->isSubclassOf(PublishableFlowChain::class)) {
                /** @var class-string<PublishableFlowChain> $fqcn */
                yield $fqcn;
            }
        }
    }
}
