<?php

declare(strict_types=1);

namespace InOtherShops\Logging\Enums;

/**
 * The kind of actor responsible for an audit-log row. Coarse on purpose — the
 * audit trail's first job is to distinguish a human admin from an automated
 * gateway callback, a scheduled process, or the agent/MCP connector. The actor's
 * identity (which admin, which command) lives in {@see LogActor::$id/$label};
 * this enum is the queryable bucket ("everything the agent touched").
 */
enum LogActorType: string
{
    case User = 'user';
    case Gateway = 'gateway';
    case System = 'system';
    case Agent = 'agent';
}
