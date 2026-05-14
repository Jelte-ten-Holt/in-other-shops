<?php

declare(strict_types=1);

namespace InOtherShops\Agent\Tools;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InOtherShops\Agent\AgentTool;
use InOtherShops\Logging\Enums\LogLevel;

/**
 * Admin-only. Returns recent `domain_logs` entries — application errors
 * (channel `app`) and shop activity (commerce / inventory / lifecycle / …) —
 * grouped by a fingerprint of the message, so recent activity surfaces as
 * counts rather than raw rows. The data source behind the logging dashboard;
 * see the consuming app's docs/logging-dashboard-design.md.
 *
 * Sanitation: this tool never returns the stored `context` column, and the
 * message shown is a fingerprint — emails are scrubbed and every digit run is
 * collapsed, so ids, order numbers, and phone/card-shaped numbers cannot reach
 * the transcript. Free-text names inside an exception message are a residual
 * risk, mitigated by the admin gate and by log messages being overwhelmingly
 * templated. Specifics live in the shop admin / laravel.log, never here.
 */
final class GetRecentProblems extends AgentTool
{
    private const int DEFAULT_SINCE_HOURS = 24;

    private const int MAX_SINCE_HOURS = 168;

    private const int FETCH_CAP = 5000;

    private const string DEFAULT_LEVEL = 'warning';

    public static function identifier(): string
    {
        return 'get_recent_problems';
    }

    public static function displayName(): string
    {
        return 'Get recent problems';
    }

    public function description(): string
    {
        return 'Admin only. Recent errors and shop activity from the domain log, grouped by message fingerprint with counts — the data behind the logging dashboard.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'since_hours' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_SINCE_HOURS,
                    'description' => 'How far back to look, in hours. Default 24, max 168 (7 days).',
                ],
                'level' => [
                    'type' => 'string',
                    'enum' => array_map(static fn (LogLevel $l): string => $l->value, LogLevel::cases()),
                    'description' => 'Minimum severity to include. Default "warning"; pass "info" to see the full activity stream.',
                ],
                'channel' => [
                    'type' => 'string',
                    'description' => 'Optional. Restrict to one channel, e.g. "app" for application errors or "commerce" for shop activity.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function __invoke(array $arguments): array
    {
        if (! $this->isAdmin()) {
            return [
                'ok' => false,
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'get_recent_problems is admin-only.',
                ],
            ];
        }

        $sinceHours = $this->sinceHours($arguments);
        $level = $this->level($arguments);
        $channel = $this->channel($arguments);

        $entries = $this->fetch($sinceHours, $level, $channel);
        $groups = $this->group($entries);

        return [
            'ok' => true,
            'data' => $groups->all(),
            'meta' => [
                'since_hours' => $sinceHours,
                'level' => $level,
                'channel' => $channel,
                'total_entries' => $entries->count(),
                'total_groups' => $groups->count(),
                'truncated' => $entries->count() >= self::FETCH_CAP,
            ],
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function sinceHours(array $arguments): int
    {
        $value = (int) ($arguments['since_hours'] ?? self::DEFAULT_SINCE_HOURS);

        return max(1, min($value, self::MAX_SINCE_HOURS));
    }

    /** @param array<string, mixed> $arguments */
    private function level(array $arguments): string
    {
        $value = is_string($arguments['level'] ?? null) ? $arguments['level'] : self::DEFAULT_LEVEL;

        return (LogLevel::tryFrom($value) ?? LogLevel::Warning)->value;
    }

    /** @param array<string, mixed> $arguments */
    private function channel(array $arguments): ?string
    {
        $value = $arguments['channel'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return Collection<int, object>
     */
    private function fetch(int $sinceHours, string $level, ?string $channel): Collection
    {
        $query = DB::table('domain_logs')
            ->where('created_at', '>=', now()->subHours($sinceHours))
            ->whereIn('level', $this->levelsAtOrAbove($level))
            ->orderByDesc('created_at')
            ->limit(self::FETCH_CAP);

        if ($channel !== null) {
            $query->where('channel', $channel);
        }

        return $query->get(['level', 'channel', 'message', 'created_at']);
    }

    /**
     * The given level and everything more severe — `domain_logs.level` is a
     * plain string, so severity ordering comes from the LogLevel enum's
     * declaration order (Debug … Critical).
     *
     * @return list<string>
     */
    private function levelsAtOrAbove(string $level): array
    {
        $order = array_map(static fn (LogLevel $l): string => $l->value, LogLevel::cases());
        $index = array_search($level, $order, true);

        return $index === false ? $order : array_slice($order, $index);
    }

    /**
     * @param  Collection<int, object>  $entries
     * @return Collection<int, array<string, mixed>>
     */
    private function group(Collection $entries): Collection
    {
        return $entries
            ->groupBy(fn (object $e): string => $e->channel.'|'.$e->level.'|'.$this->fingerprint($e->message))
            ->map(function (Collection $rows): array {
                /** @var object $first */
                $first = $rows->first();
                $timestamps = $rows->pluck('created_at');

                return [
                    'channel' => $first->channel,
                    'level' => $first->level,
                    'message' => $this->fingerprint($first->message),
                    'count' => $rows->count(),
                    'first_seen' => $timestamps->min(),
                    'last_seen' => $timestamps->max(),
                ];
            })
            ->sortByDesc('count')
            ->values();
    }

    /**
     * Scrub emails, then collapse every digit run — so ids, order numbers, and
     * phone/card-shaped numbers never reach the output, and entries differing
     * only by a number ("…for id 5" / "…for id 6") group into one row.
     */
    private function fingerprint(string $message): string
    {
        $scrubbed = preg_replace('/[\w.+-]+@[\w.-]+\.\w+/', '[email]', $message) ?? $message;

        return preg_replace('/\d+/', 'N', $scrubbed) ?? $scrubbed;
    }
}
