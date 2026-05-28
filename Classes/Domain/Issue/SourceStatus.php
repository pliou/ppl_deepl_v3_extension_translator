<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue;

final class SourceStatus
{
    public const SOURCE_KNOWN = 'source_known';
    public const SOURCE_KNOWN_FROM_OTHER_KEY = 'source_known_from_other_key';
    public const SOURCE_KNOWN_FROM_OTHER_LOCALE = 'source_known_from_other_locale';
    public const SOURCE_KNOWN_FROM_KEYLESS_UNIT = 'source_known_from_keyless_unit';
    public const MANUAL_SOURCE_REQUIRED = 'manual_source_required';
    public const NOT_TRANSLATABLE = 'not_translatable';

    public static function canUseForDeepl(string $sourceStatus): bool
    {
        return in_array($sourceStatus, [
            self::SOURCE_KNOWN,
            self::SOURCE_KNOWN_FROM_OTHER_KEY,
            self::SOURCE_KNOWN_FROM_OTHER_LOCALE,
        ], true);
    }
}
