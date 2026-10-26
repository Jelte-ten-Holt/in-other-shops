<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use InOtherShops\Agent\Http\Support\ReadFormat;

/**
 * One page of the read API: `until` (required) bounds every page, so rows
 * written while a client pages do not slip past its cursor; the cursor
 * `after_updated_at` + `after_id` comes from the previous page's `next`.
 * Timestamps are UTC with a `Z`, exactly as the API writes them.
 */
final class ReadPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $timestamp = 'date_format:'.ReadFormat::TIMESTAMP;

        return [
            'until' => ['required', $timestamp],
            'after_updated_at' => ['nullable', 'required_with:after_id', $timestamp],
            'after_id' => ['nullable', 'required_with:after_updated_at', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('agent.read.max_limit', 500)],
        ];
    }

    /** A machine client gets JSON, never a redirect, whatever it sent as Accept. */
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json(['errors' => $validator->errors()], 422));
    }

    public function until(): CarbonImmutable
    {
        return $this->timestamp('until');
    }

    /** @return array{0: CarbonImmutable, 1: int}|null */
    public function cursor(): ?array
    {
        return $this->filled('after_updated_at')
            ? [$this->timestamp('after_updated_at'), (int) $this->input('after_id')]
            : null;
    }

    public function limit(): int
    {
        return (int) $this->input('limit', 100);
    }

    /** In the app's zone, which is how the columns compared against hold it. */
    private function timestamp(string $key): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!'.ReadFormat::TIMESTAMP, (string) $this->input($key), 'UTC')
            ->setTimezone((string) config('app.timezone'));
    }
}
