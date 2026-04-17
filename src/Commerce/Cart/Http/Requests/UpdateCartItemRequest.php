<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Cart\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    public function quantity(): int
    {
        return (int) $this->input('quantity');
    }
}
