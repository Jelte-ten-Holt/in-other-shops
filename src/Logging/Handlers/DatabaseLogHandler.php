<?php

declare(strict_types=1);

namespace InOtherShops\Logging\Handlers;

use InOtherShops\Logging\Contracts\LogHandler;
use InOtherShops\Logging\DTOs\LogActor;
use InOtherShops\Logging\DTOs\LogEntry;
use Illuminate\Support\Facades\DB;
use JsonException;

final class DatabaseLogHandler implements LogHandler
{
    public function handle(LogEntry $entry): void
    {
        // The dispatcher resolves the actor before handing the entry over, but a
        // handler invoked directly (tests, ad-hoc) may carry none — fall back to
        // the same loud default rather than writing a null actor.
        $actor = $entry->actor ?? LogActor::unknown();

        DB::table('domain_logs')->insert([
            'level' => $entry->level->value,
            'channel' => $entry->channel,
            'message' => $entry->message,
            'context' => $this->encodeContext($entry->context),
            'actor_type' => $actor->type->value,
            'actor_id' => $actor->id,
            'actor_label' => $actor->label,
            'created_at' => now(),
        ]);
    }

    /**
     * Encode the context to JSON without ever returning the literal `false`
     * (G10): plain `json_encode` returns `false` — not throwing — on non-UTF-8
     * or otherwise unencodable input (free-text fields like an error message,
     * shipment reason, or voucher code can trigger it), silently storing `false`
     * and losing the entire context. Substitute invalid UTF-8 so the common case
     * survives intact, and on any remaining failure store a recoverable sentinel
     * rather than dropping the audit context on the floor.
     *
     * @param  array<string, mixed>  $context
     */
    private function encodeContext(array $context): string
    {
        try {
            return json_encode($context, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException $e) {
            return json_encode(
                ['_context_encode_error' => $e->getMessage(), '_keys' => array_keys($context)],
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
            ) ?: '{"_context_encode_error":"unencodable"}';
        }
    }
}
