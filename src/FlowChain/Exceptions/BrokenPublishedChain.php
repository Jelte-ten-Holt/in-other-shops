<?php

declare(strict_types=1);

namespace InOtherShops\FlowChain\Exceptions;

use RuntimeException;

/**
 * Thrown when a published chain file exists at the expected path but the
 * expected class can't be autoloaded — typically a namespace typo or
 * mismatched class name in the consumer's published copy.
 *
 * The registry refuses to silently fall back to the package default in this
 * case because silent fallback turns a typo into a months-later "wait, my
 * customizations were never running" bug.
 */
final class BrokenPublishedChain extends RuntimeException {}
