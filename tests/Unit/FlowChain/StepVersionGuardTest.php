<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Unit\FlowChain;

use InOtherShops\FlowChain\Contracts\FlowStep;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Forcing function for the deferred upstream-version-pin mechanism.
 *
 * When a step's behavior changes in a way downstream needs to know about
 * but the SHAPE stays the same (the hash can't see it), the developer
 * bumps the step's version(). At that moment, downstream consumers need
 * a way to assert which upstream version they were written against — i.e.
 * an `expectedUpstreamVersions()` method on FlowStep.
 *
 * That mechanism is intentionally deferred until the first such bump
 * happens, to avoid building speculative API. This test fails the moment
 * any step bumps past version 1, forcing the conversation:
 *
 *   1. Was the bump warranted? If the change was actually a SHAPE change,
 *      the hash already catches it — the version bump is redundant.
 *   2. If the change is genuinely semantic-only, time to build the
 *      upstream-version-pin mechanism. See FlowChain README §Outstanding.
 *   3. After building the pin (and migrating any current bumps to it),
 *      delete this test.
 *
 * Scans src/ only — test-only fixtures that exercise versioning (e.g.
 * StepWithShapeAtV2 in FlowStepFingerprintTest) are intentionally
 * out of scope.
 */
final class StepVersionGuardTest extends TestCase
{
    #[Test]
    public function no_flow_step_in_src_has_a_version_above_1(): void
    {
        $offenders = [];

        foreach ($this->scanForFlowSteps(srcDir: __DIR__.'/../../../src') as $stepClass) {
            $version = $stepClass::version();

            if ($version > 1) {
                $offenders[$stepClass] = $version;
            }
        }

        if ($offenders === []) {
            $this->assertTrue(true);

            return;
        }

        $details = array_map(
            fn (string $cls, int $v): string => "  {$cls} at version {$v}",
            array_keys($offenders),
            array_values($offenders),
        );

        $this->fail(
            "FlowStep versions have moved past 1, but the upstream-version-pin mechanism\n"
            ."(`expectedUpstreamVersions()` on FlowStep) is not implemented yet.\n\n"
            ."Offending steps:\n".implode("\n", $details)."\n\n"
            ."See FlowChain/README.md §Outstanding for the implementation plan. After the\n"
            ."pin mechanism lands and current bumps are migrated to it, delete this test."
        );
    }

    /**
     * @return iterable<class-string<FlowStep>>
     */
    private function scanForFlowSteps(string $srcDir): iterable
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

            if ($reflection->implementsInterface(FlowStep::class)) {
                /** @var class-string<FlowStep> $fqcn */
                yield $fqcn;
            }
        }
    }
}
