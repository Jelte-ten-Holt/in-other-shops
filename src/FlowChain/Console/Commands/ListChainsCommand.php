<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Console\Commands;

use Illuminate\Console\Command;
use InOtherShops\FlowChain\Exceptions\BrokenPublishedChain;
use InOtherShops\FlowChain\FlowChainRegistry;
use InOtherShops\FlowChain\FlowStepFingerprint;

final class ListChainsCommand extends Command
{
    protected $signature = 'flowchain:list';

    protected $description = 'List all registered FlowChains and whether each is using the package default or a consumer-published copy.';

    public function handle(FlowChainRegistry $registry): int
    {
        $chains = $registry->all();

        if ($chains === []) {
            $this->info('No chains registered.');

            return self::SUCCESS;
        }

        sort($chains);

        $rows = [];
        foreach ($chains as $chainClass) {
            $rows[] = $this->describeChain($registry, $chainClass);
        }

        $this->table(['Chain', 'Status', 'Steps', 'Fingerprints'], $rows);

        return self::SUCCESS;
    }

    /**
     * @param  class-string<\InOtherShops\FlowChain\PublishableFlowChain>  $chainClass
     * @return array<int, string>
     */
    private function describeChain(FlowChainRegistry $registry, string $chainClass): array
    {
        $label = $chainClass::domain().'/'.$chainClass::chainName();

        try {
            $resolved = $registry->resolve($chainClass);
            $status = $resolved === $chainClass ? 'package default' : 'published ('.$resolved.')';
            $steps = $resolved::steps();
            $fingerprints = array_map(
                fn (string $s): string => $this->shortName($s).' '.FlowStepFingerprint::ofStep($s),
                $steps,
            );

            return [
                $label,
                $status,
                (string) count($steps),
                implode("\n", $fingerprints),
            ];
        } catch (BrokenPublishedChain $e) {
            return [$label, '<error>BROKEN</error>', '-', $e->getMessage()];
        }
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }
}
