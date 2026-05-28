<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue;

final class ActionState
{
    public const READY_TO_WRITE = 'ready_to_write';
    public const READY_TO_CREATE_SUGGESTION = 'ready_to_create_suggestion';
    public const NEEDS_SOURCE = 'needs_source';
    public const REVIEW_ONLY = 'review_only';
    public const CANNOT_CHANGE = 'cannot_change';
    public const IGNORED_FOR_RUN = 'ignored_for_run';
    public const IGNORED_PERMANENTLY = 'ignored_permanently';
    public const WRITTEN = 'written';
}
