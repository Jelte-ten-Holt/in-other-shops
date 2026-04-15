<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Http\Requests;

use InOtherShops\Commerce\Cart\Contracts\Cartable;
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

            if (! $cartable instanceof Cartable) {
                $validator->errors()->add('type', 'The selected item is not addable to a cart.');
            }
        });
    }

    /**
     * Returns the resolved cartable model after validation passes.
     */
    public function cartable(): Cartable&Model
    {
        $cartable = $this->resolveCartable();

        if (! $cartable instanceof Cartable) {
            throw new \RuntimeException('AddToCartRequest::cartable() called before validation succeeded.');
        }

        return $cartable;
    }

    public function quantity(): int
    {
        return (int) ($this->input('quantity') ?? 1);
    }

    private function resolveCartable(): ?Model
    {
        $class = Relation::getMorphedModel((string) $this->input('type')) ?? $this->input('type');

        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        $instance = new $class;

        if (! $instance instanceof Model) {
            return null;
        }

        return $class::query()->find($this->input('id'));
    }
}
