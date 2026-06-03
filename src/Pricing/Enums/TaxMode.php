<?php

declare(strict_types=1);

namespace InOtherShops\Pricing\Enums;

/**
 * How a price relates to tax for a given order.
 *
 * - Inclusive: the stored/displayed price is gross (tax-inclusive); net and tax
 *   are derived from it. This is the EU B2C model and the only one implemented.
 * - Exclusive: the customer is charged net, tax handled separately. Reserved for
 *   the B2B / reverse-charge seam — plumbed and snapshotted, not yet implemented.
 */
enum TaxMode: string
{
    case Inclusive = 'inclusive';
    case Exclusive = 'exclusive';
}
