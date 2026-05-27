<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Console\Commands;

use Illuminate\Console\Command;
use InOtherShops\FlowChain\FlowChainRegistry;
use InOtherShops\FlowChain\PublishableFlowChain;

/**
 * Scans for consumer-published chains and confirms each one has a
 * corresponding test class at Tests\Feature\FlowChains\{ChainName}Test.
 *
 * Existence-only check — "quality of test" is on the developer. Hook into
 * pre-commit for those who want the gate; the package does not enforce
 * at runtime (per the design: we offer the tool, consumers wire it in).
 */
final class VerifyTestsCommand extends Command
{
    protected $signature = 'flowchain:verify-tests';

    protected $description = 'For each published chain, verify a Tests\Feature\FlowChains\{ChainName}Test class exists. Non-zero exit on any missing.';

    public function handle(FlowChainRegistry $registry): int
    {
        $missing = [];

        foreach ($registry->all() as $chainClass) {
            if (! $registry->isPublished($chainClass)) {
                continue;
            }

            $expectedTestClass = $this->expectedTestClass($chainClass);

            if (! class_exists($expectedTestClass)) {
                $missing[] = [$chainClass::domain().'/'.$chainClass::chainName(), $expectedTestClass];
            }
        }

        if ($missing === []) {
            $this->info('All published chains have test classes.');

            return self::SUCCESS;
        }

        $this->error('Published chains missing test classes:');
        foreach ($missing as [$chainLabel, $expectedTest]) {
            $this->line("  {$chainLabel} → expected {$expectedTest}");
        }

        return self::FAILURE;
    }

    /**
     * @param  class-string<PublishableFlowChain>  $chainClass
     */
    private function expectedTestClass(string $chainClass): string
    {
        return 'Tests\\Feature\\FlowChains\\'.$chainClass::chainName().'Test';
    }
}
