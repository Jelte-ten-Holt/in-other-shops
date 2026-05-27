<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Http\Requests;

use InOtherShops\Commerce\Cart\Contracts\HasCart;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\FormRequest;

final class AddToCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string'],
            'id' => ['required'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            // Free-form passthrough to AddToCartPayload::$metadata so
            // consumer-published chain steps can read context (attribution
            // source, A/B variant, etc.). The package does not interpret
            // contents — interpretation is the consumer step's
            // responsibility. Capped at array (no nested validation here);
            // Laravel's request size limits cover DoS shape.
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $cartable = $this->resolveCartable();

            if ($cartable === null) {
                $validator->errors()->add('type', 'The selected item could not be found.');

                return;
            }

            if (! $cartable instanceof HasCart) {
                $validator->errors()->add('type', 'The selected item is not addable to a cart.');
            }
        });
    }

    /**
     * Returns the resolved cartable model after validation passes.
     */
    public function cartable(): HasCart&Model
    {
        $cartable = $this->resolveCartable();

        if (! $cartable instanceof HasCart) {
            throw new \RuntimeException('AddToCartRequest::cartable() called before validation succeeded.');
        }

        return $cartable;
    }

    public function quantity(): int
    {
        return (int) ($this->input('quantity') ?? 1);
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        $metadata = $this->input('metadata');

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * Resolves the request's `type` via the morph map only — raw FQCNs are
     * rejected. `new $class` on an attacker-controlled FQCN would run
     * arbitrary constructors before the `instanceof HasCart` check could
     * fire, so the morph map is the trust boundary: only types the
     * consumer has registered in `Relation::enforceMorphMap()` can resolve.
     */
    private function resolveCartable(): ?Model
    {
        $type = $this->input('type');

        if (! is_string($type) || $type === '') {
            return null;
        }

        $class = Relation::getMorphedModel($type);

        if ($class === null || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->find($this->input('id'));
    }
}
