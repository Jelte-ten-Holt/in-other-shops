<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Feature\Support;

use Illuminate\Support\Facades\Lang;
use InOtherShops\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every `shops-{domain}::` / `shops-common::` translation key referenced in the
 * package's PHP (outside the lang files themselves) must resolve — otherwise the
 * admin renders a raw `shops-…` key. Static literals only; dynamically-built
 * keys (HasLabel's `shops-{domain}::enums.{Enum}.{value}`) are covered by the
 * parity test's enums.php files instead. Runs under `en`, the source-of-truth
 * locale that must always have every key.
 */
final class NoOrphanTranslationKeysTest extends TestCase
{
    #[Test]
    public function every_referenced_translation_key_resolves(): void
    {
        $root = dirname(__DIR__, 3);
        $keys = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/lang/')) {
                continue;
            }

            // Only real string-literal tokens — NOT comments. Docblocks contain
            // `__('shops-…::…')` examples that are not live references; matching
            // raw text would flag them as orphans.
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                $literal = trim($token[1], "'\"");

                // A literal ending in '.' is a PREFIX being concatenated at
                // runtime (NavigationGroup's `'shops-common::nav.'.$case`,
                // HasLabel's enum key), not a complete key — it can never
                // resolve on its own. Those composed keys are covered by the
                // parity test (their lang files) and AdminNavigationLabelsTest.
                if (str_ends_with($literal, '.')) {
                    continue;
                }

                if (preg_match('/^shops-[a-z]+::[A-Za-z0-9_.]+$/', $literal)) {
                    $keys[$literal] = true;
                }
            }
        }

        Lang::setLocale('en');

        $orphans = array_values(array_filter(
            array_keys($keys),
            static fn (string $key): bool => ! Lang::has($key),
        ));

        sort($orphans);

        $this->assertSame([], $orphans, "Referenced keys that do not resolve:\n".implode("\n", $orphans));
    }
}
