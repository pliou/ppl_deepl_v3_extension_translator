<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue;

final class TranslationIssueType
{
    public const KEY_MISMATCH_CANDIDATE = 'key_mismatch_candidate';
    public const KEYLESS_UNIT = 'keyless_unit';
    public const MISSING_SOURCE_FROM_LOCALE_CANDIDATE = 'missing_source_from_locale_candidate';
    public const MISSING_SOURCE_UNIT = 'missing_source_unit';
    public const MISSING_TARGET = 'missing_target';
    public const TODO_VALUE = 'todo_value';
    public const EQUAL_VALUE = 'equal_value';
    public const UNUSED_CANDIDATE = 'unused_candidate';
    public const LOCALE_GAP = 'locale_gap';
    public const CANNOT_CHANGE = 'cannot_change';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::KEYLESS_UNIT,
            self::KEY_MISMATCH_CANDIDATE,
            self::MISSING_SOURCE_FROM_LOCALE_CANDIDATE,
            self::MISSING_SOURCE_UNIT,
            self::LOCALE_GAP,
            self::MISSING_TARGET,
            self::TODO_VALUE,
            self::EQUAL_VALUE,
            self::UNUSED_CANDIDATE,
            self::CANNOT_CHANGE,
        ];
    }

    public static function label(string $issueType): string
    {
        return match ($issueType) {
            self::KEY_MISMATCH_CANDIDATE => 'Key mismatch candidate',
            self::KEYLESS_UNIT => 'Keyless unit',
            self::MISSING_SOURCE_FROM_LOCALE_CANDIDATE => 'Missing source, locale text found',
            self::MISSING_SOURCE_UNIT => 'Missing source unit',
            self::MISSING_TARGET => 'Missing target',
            self::TODO_VALUE => 'TODO value',
            self::EQUAL_VALUE => 'Equal value',
            self::UNUSED_CANDIDATE => 'Unused candidate',
            self::LOCALE_GAP => 'Locale gap',
            self::CANNOT_CHANGE => 'Cannot change',
            default => $issueType,
        };
    }
}
