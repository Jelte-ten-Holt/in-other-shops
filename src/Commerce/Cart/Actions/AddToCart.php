<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Actions;

use Illuminate\Database\Eloquent\Model;
use InOtherShops\Commerce\Cart\Contracts\HasCart;
use InOtherShops\Commerce\Cart\FlowChains\AddToCartChain;
use InOtherShops\Commerce\Cart\FlowChains\AddToCartPayload;
use InOtherShops\Commerce\Cart\Models\Cart;
use InOtherShops\Commerce\Cart\Models\CartItem;
use InOtherShops\FlowChain\Enums\FlowChainStatus;
use InOtherShops\FlowChain\Exceptions\StepFailedException;
use InOtherShops\FlowChain\FlowChainRegistry;

/**
 * Thin facade over AddToCartChain. Keeps the historical __invoke signature
 * so existing call sites don't change, while delegating the actual
 * orchestration to the publishable chain — which consumers can fork and
 * modify per FlowChain README §Publishing.
 *
 * Failure handling unwraps the chain's StepFailedException so the
 * underlying domain exceptions (e.g. InsufficientStockException) surface
 * directly to callers, matching the pre-chain behavior.
 */
final class AddToCart
{
    public function __construct(
        private readonly FlowChainRegistry $registry,
    ) {}

    public function __invoke(Cart $cart, HasCart&Model $cartable, int $quantity = 1): CartItem
    {
        $chainClass = $this->registry->resolve(AddToCartChain::class);
        $payload = new AddToCartPayload($cart, $cartable, $quantity);

        /** @var AddToCartChain $chain */
        $chain = new $chainClass;
        $result = $chain->run($payload);

        if ($result->status === FlowChainStatus::Failed) {
            $this->rethrowUnderlyingException($result->exception);
        }

        assert($payload->cartItem !== null, 'AddToCartChain finished but cartItem was not set — chain step list is incomplete.');

        return $payload->cartItem;
    }

    private function rethrowUnderlyingException(?StepFailedException $exception): never
    {
        if ($exception === null) {
            throw new \RuntimeException('AddToCartChain failed but no exception was attached to the result.');
        }

        $previous = $exception->getPrevious();

        if ($previous === null) {
            throw $exception;
        }

        throw $previous;
    }
}
