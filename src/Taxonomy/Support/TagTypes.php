<?php

declare(strict_types=1);

namespace InOtherShops\Taxonomy\Support;

/**
 * The tag-type vocabulary a consuming project declares for itself.
 *
 * `tags.type` is a free string the package stores and never interprets — every
 * meaning it carries is the consumer's. One site partitions tags by genre and
 * disclosure; a shop has no use for either and must not inherit them. So the
 * package ships the mechanism and the project declares the words, via
 * `config('taxonomy.tag_types')`.
 *
 * **Declaring nothing keeps the field free text**, which is what every existing
 * consumer gets on upgrade: no config, no behaviour change, no editor suddenly
 * unable to type the value they have always typed.
 *
 * Two accepted shapes, so a project that only wants a list is not forced to
 * write nested arrays:
 *
 *     'tag_types' => [
 *         'genre' => 'Genre',
 *         'disclosure' => ['label' => 'Disclosure', 'description' => 'How it was made.'],
 *     ],
 */
final class TagTypes
{
    /**
     * Whether this project has declared a vocabulary. False means free text —
     * the field stays a plain input rather than becoming a select with nothing
     * in it.
     */
    public static function isConfigured(): bool
    {
        return static::declared() !== [];
    }

    /**
     * value => label, for a select.
     *
     * `$current` is merged in when it is not part of the declared vocabulary.
     * Without that, a tag typed before the vocabulary existed (or since removed
     * from it) renders as an empty select and is **silently wiped on the next
     * save of an unrelated field** — the edit destroys data the editor never
     * touched and never saw.
     *
     * @return array<string, string>
     */
    public static function options(?string $current = null): array
    {
        $options = [];

        foreach (static::declared() as $value => $definition) {
            $options[(string) $value] = static::labelOf((string) $value, $definition);
        }

        if ($current !== null && $current !== '' && ! array_key_exists($current, $options)) {
            $options[$current] = $current;
        }

        return $options;
    }

    /**
     * value => description, for the declared types that carry one. Absent when
     * a project declared plain labels.
     *
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        $descriptions = [];

        foreach (static::declared() as $value => $definition) {
            if (is_array($definition) && filled($definition['description'] ?? null)) {
                $descriptions[(string) $value] = (string) $definition['description'];
            }
        }

        return $descriptions;
    }

    /**
     * @return array<string, mixed>
     */
    private static function declared(): array
    {
        $configured = config('taxonomy.tag_types', []);

        return is_array($configured) ? $configured : [];
    }

    private static function labelOf(string $value, mixed $definition): string
    {
        if (is_array($definition)) {
            return (string) ($definition['label'] ?? $value);
        }

        return is_string($definition) && $definition !== '' ? $definition : $value;
    }
}
