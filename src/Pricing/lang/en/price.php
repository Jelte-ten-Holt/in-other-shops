<?php

declare(strict_types=1);

/**
 * Price admin strings — `en` source of truth. Domain-specific strings only;
 * recurring field labels (Currency) come from `shops-common::fields.*`.
 */
return [
    'title' => 'Prices',

    'amount' => 'Amount',
    'minimum_quantity' => 'Minimum quantity',
    'price_list' => 'Price list',
    'compare_at_amount' => 'Strikethrough price',
    'compare_at_tooltip' => "Only use a price this item was genuinely sold at recently. Inventing a higher 'original' price to fake a discount is illegal under EU pricing rules (Omnibus Directive).",
    'compare_at_create_blocked' => "A strikethrough can't be set when first creating a price — there's no prior price the item was sold at. Save the price, then add a strikethrough.",
    'compare_at_too_high' => 'The strikethrough price cannot be higher than what this item is currently priced at. Use a price it was actually sold at before.',
    'compare_at_until' => 'Strikethrough ends',
    'compare_at_until_help' => 'When this passes, the strikethrough price becomes the actual price and the strikethrough is cleared. Times are in the shop’s configured timezone (:timezone).',
    'strikethrough' => 'Strikethrough',
];
