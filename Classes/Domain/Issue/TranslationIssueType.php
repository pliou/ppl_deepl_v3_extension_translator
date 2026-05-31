<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue;

final class TranslationIssueType
{
    public const KEY_MISMATCH_CANDIDATE = 'key_mismatch_candidate';
    public const KEYLESS_UNIT = 'keyless_unit';
    public const MISSING_SOURCE_FROM_LOCALE_CANDIDATE = 'missing_source_from_locale_candidate';
    public const MISSING_SOURCE_UNIT = 'missing_source_unit';
    public const MISSING_TRANSLATION_UNIT = 'missing_translation_unit';
    public const LOCALE_GAP = 'locale_gap';
    public const MISSING_TARGET = 'missing_target';
    public const TODO_SOURCE = 'todo_source';
    public const TODO_VALUE = 'todo_value';
    public const EQUAL_VALUE = 'equal_value';
    public const UNUSED_CANDIDATE = 'unused_candidate';

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
            self::MISSING_TRANSLATION_UNIT,
            self::LOCALE_GAP,
            self::MISSING_TARGET,
            self::TODO_SOURCE,
            self::TODO_VALUE,
            self::EQUAL_VALUE,
            self::UNUSED_CANDIDATE,
        ];
    }

    public static function label(string $issueType): string
    {
        return match ($issueType) {
            self::KEY_MISMATCH_CANDIDATE => 'Key mismatch candidate',
            self::KEYLESS_UNIT => 'Keyless unit',
            self::MISSING_SOURCE_FROM_LOCALE_CANDIDATE => 'Missing source, locale candidate',
            self::MISSING_SOURCE_UNIT => 'Missing source unit',
            self::MISSING_TRANSLATION_UNIT => 'Missing translation unit',
            self::LOCALE_GAP => 'Locale gap',
            self::MISSING_TARGET => 'Missing translation',
            self::TODO_SOURCE => 'TODO in source',
            self::TODO_VALUE => 'TODO in translation',
            self::EQUAL_VALUE => 'Equal value',
            self::UNUSED_CANDIDATE => 'Unused candidate',
            default => $issueType,
        };
    }
}
