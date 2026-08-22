<?php

declare(strict_types=1);

namespace InOtherShops\Commerce\Checkout\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A voucher code the shopper typed into the checkout form.
 *
 * Normalized to upper case and trimmed before the rules run: codes are
 * printed on cards and pasted out of emails, so leading whitespace and a
 * lower-case spelling are the shopper's typing, not a different code.
 * `vouchers.code` is a plain unique column with no case-folding of its own,
 * so the normalization has to happen here or "SPRING" and "spring" are two
 * different lookups. Existence is proven by the pricing call, never by an
 * `exists:` rule — a validation-layer lookup would answer "is this a real
 * code?" outside the rate limiter.
 */
final class ApplyVoucherRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'voucher_code' => mb_strtoupper(trim((string) $this->input('voucher_code'))),
        ]);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'voucher_code' => ['required', 'string', 'max:255'],
        ];
    }

    public function voucherCode(): string
    {
        return (string) $this->validated()['voucher_code'];
    }
}
