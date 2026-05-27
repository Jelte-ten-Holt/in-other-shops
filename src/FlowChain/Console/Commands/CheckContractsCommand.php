<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Console\Commands;

use Illuminate\Console\Command;
use InOtherShops\FlowChain\ChainContractValidator;
use InOtherShops\FlowChain\Exceptions\BrokenPublishedChain;
use InOtherShops\FlowChain\Exceptions\FlowChainContractViolation;
use InOtherShops\FlowChain\FlowChainRegistry;
use InOtherShops\FlowChain\PublishableFlowChain;

/**
 * Walks every registered chain through ChainContractValidator. Hook into
 * pre-commit (project-side) or CI (package-side) so contract drift is
 * caught before deploy. Same checks the runtime validator would do, but
 * surfaces all violations in one pass instead of failing on first request.
 */
final class CheckContractsCommand extends Command
{
    protected $signature = 'flowchain:check-contracts';

    protected $description = 'Validate every registered FlowChain against its step-shape contracts. Non-zero exit on any violation.';

    public function handle(FlowChainRegistry $registry, ChainContractValidator $validator): int
    {
        $chains = $registry->all();

        if ($chains === []) {
            $this->info('No chains registered — nothing to check.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($chains as $chainClass) {
            $failed += $this->checkChain($registry, $validator, $chainClass) ? 0 : 1;
        }

        if ($failed > 0) {
            $this->error("{$failed} chain(s) failed contract validation.");

            return self::FAILURE;
        }

        $this->info(count($chains).' chain(s) validated successfully.');

        return self::SUCCESS;
    }

    /**
     * @param  class-string<PublishableFlowChain>  $chainClass
     */
    private function checkChain(FlowChainRegistry $registry, ChainContractValidator $validator, string $chainClass): bool
    {
        $label = $chainClass::domain().'/'.$chainClass::chainName();

        try {
            $resolved = $registry->resolve($chainClass);
            $validator->validate(
                chainName: $resolved::chainName(),
                stepClasses: $resolved::steps(),
                initialPayloadShape: $resolved::initialPayloadShape(),
            );
            $suffix = $resolved === $chainClass ? '' : ' (published)';
            $this->line("  <info>✓</info> {$label}{$suffix}");

            return true;
        } catch (FlowChainContractViolation|BrokenPublishedChain $e) {
            $this->line("  <error>✗</error> {$label}");
            $this->line('    '.$e->getMessage());

            return false;
        }
    }
}
