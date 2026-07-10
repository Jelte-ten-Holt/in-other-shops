<?php

declare(strict_types=1);

namespace InOtherShops\Tests\Unit\Support;

use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Admin i18n guardrail: for every domain that ships translations, the `es` key
 * set must exactly equal the `en` key set — no key present in one locale and
 * absent in the other — and no value may be empty. This is what stops the
 * English/Spanish admin mix from silently reappearing: a new `->label()` keyed
 * in `en` but not `es` (or vice versa) fails here, not in production.
 *
 * `en` is the source-of-truth locale; a missing `es` key would fall back to
 * English at runtime (no raw key on screen), but that fallback is a bug to
 * catch here, not a shipping state.
 */
final class TranslationParityTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function domainLangDirs(): iterable
    {
        $root = dirname(__DIR__, 3);

        $found = false;

        foreach (glob($root.'/src/*/lang') ?: [] as $langDir) {
            $found = true;
            $domain = basename(dirname($langDir));

            yield $domain => [$langDir];
        }

        // Until the first domain ships translations, yield a sentinel so the
        // class has an executable (skipped) test rather than erroring with
        // "no tests found". Removed in practice the moment any lang dir exists.
        if (! $found) {
            yield 'no-translations-yet' => [null];
        }
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('domainLangDirs')]
    public function es_key_set_equals_en_key_set(?string $langDir): void
    {
        if ($langDir === null) {
            $this->markTestSkipped('No domain translations shipped yet.');
        }

        $en = $this->flattenLocale($langDir.'/en');
        $es = $this->flattenLocale($langDir.'/es');

        $missingInEs = array_diff(array_keys($en), array_keys($es));
        $missingInEn = array_diff(array_keys($es), array_keys($en));

        $this->assertSame(
            [],
            array_values($missingInEs),
            "Keys present in en but missing in es: \n".implode("\n", $missingInEs),
        );
        $this->assertSame(
            [],
            array_values($missingInEn),
            "Keys present in es but missing in en: \n".implode("\n", $missingInEn),
        );
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('domainLangDirs')]
    public function no_translation_value_is_empty(?string $langDir): void
    {
        if ($langDir === null) {
            $this->markTestSkipped('No domain translations shipped yet.');
        }

        foreach (['en', 'es'] as $locale) {
            foreach ($this->flattenLocale($langDir.'/'.$locale) as $key => $value) {
                $this->assertNotSame('', trim((string) $value), "Empty translation: {$locale} / {$key}");
            }
        }
    }

    /**
     * Flatten every `{locale}/*.php` file into dotted keys, namespaced by the
     * file basename so two files can't collide (e.g. `orders.status`,
     * `enums.OrderStatus.confirmed`).
     *
     * @return array<string, mixed>
     */
    private function flattenLocale(string $localeDir): array
    {
        $flat = [];

        foreach (glob($localeDir.'/*.php') ?: [] as $file) {
            $group = basename($file, '.php');
            $data = require $file;

            if (! is_array($data)) {
                continue;
            }

            foreach (Arr::dot($data) as $key => $value) {
                $flat[$group.'.'.$key] = $value;
            }
        }

        return $flat;
    }
}
