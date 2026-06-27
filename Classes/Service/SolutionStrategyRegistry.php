<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\SolutionStrategy;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue\TranslationIssueType;

final class SolutionStrategyRegistry
{
    /**
     * @return SolutionStrategy[]
     */
    public function forIssueType(string $issueType): array
    {
        return match ($issueType) {
            TranslationIssueType::KEY_MISMATCH_CANDIDATE => [
                $this->strategy('change_key_to_matching_key', $issueType, 'Change key to matching key', 'change_key_to_matching_key', requiresTargetKey: true, description: 'Changes the selected key to the matching XLF key with the same source text. If the old key is used in code, those static usages are included automatically.'),
                $this->strategy('create_alias_unit', $issueType, 'Create alias unit', 'create_alias_source_unit', requiresKnownSource: true, description: 'Adds the selected key as a second XLF unit and copies the source text from the matching key. Use this only if both keys should intentionally remain.'),
                ...$this->ignoreStrategies($issueType),
            ],
            TranslationIssueType::KEYLESS_UNIT => [
                $this->strategy('assign_key', $issueType, 'Assign key manually', 'enter_key_manually', requiresTargetKey: true),
                $this->strategy('link_keyless', $issueType, 'Link to missing code key', 'link_keyless_unit_to_key', requiresTargetKey: true),
                $this->strategy('delete_invalid', $issueType, 'Delete invalid unit', 'delete_invalid_unit', destructive: true),
                ...$this->ignoreStrategies($issueType),
            ],
            TranslationIssueType::MISSING_SOURCE_FROM_LOCALE_CANDIDATE => [
                $this->strategy('copy_locale_candidate', $issueType, 'Copy locale candidate into source', 'use_other_locale_as_source', description: 'The source is missing. The text shown under related candidates comes from another locale and can be copied into the source after review.'),
                $this->strategy('manual_source', $issueType, 'Manual source', 'enter_source_manually', requiresManualSource: true),
                ...$this->ignoreStrategies($issueType),
            ],
            TranslationIssueType::MISSING_SOURCE_UNIT => [
                $this->strategy('manual_source', $issueType, 'Manual source', 'enter_source_text', requiresManualSource: true),
                ...$this->ignoreStrategies($issueType),
            ],
            TranslationIssueType::MISSING_TRANSLATION_UNIT => [
                $this->strategy('manual_source_target', $issueType, 'Manual source and target', 'create_manual_translation_unit', requiresManualSource: true, requiresManualTarget: true, description: 'The key is used in code but no XLF unit exists. Enter the source and target text manually.'),
                ...$this->ignoreStrategies($issueType),
            ],
            TranslationIssueType::LOCALE_GAP => [
                $this->strategy('copy_unit_without_target', $issueType, 'Copy unit without translation', 'copy_source_unit_without_target', requiresKnownSource: true, description: 'Copies the complete trans-unit id and source into the existing locale file and leaves the target empty so it can be handled as Missing translation.'),
                ...$this->ignoreStrategies($issueType),
            ],
            TranslationIssueType::MISSING_TARGET => [
                $this->strategy('deepl_translate', $issueType, 'DeepL translate', 'create_deepl_target_suggestion', requiresKnownSource: true, requiresDeepl: true),
                $this->strategy('manual_target', $issueType, 'Manual target', 'enter_target_text', requiresManualTarget: true),
                $this->strategy('copy_source', $issueType, 'Copy source', 'copy_source_value', requiresKnownSource: true),
                $this->strategy('todo_target', $issueType, 'TODO target', 'write_todo_target'),
                $this->strategy('empty_target', $issueType, 'Create empty target', 'create_empty_target_unit'),
                ...$this->ignoreStrategies($issueType),
            ],
            TranslationIssueType::TODO_SOURCE => [
                $this->strategy('manual_source', $issueType, 'Manual source', 'enter_source_text', requiresManualSource: true, description: 'Replaces the TODO placeholder in the source text.'),
                ...$this->ignoreStrategies($issueType),
            ],
            TranslationIssueType::TODO_VALUE => [
                $this->strategy('deepl_translate', $issueType, 'DeepL translate', 'create_deepl_target_suggestion', requiresKnownSource: true, requiresDeepl: true),
                $this->strategy('manual_target', $issueType, 'Manual target', 'enter_target_text', requiresManualTarget: true),
                $this->strategy('copy_source', $issueType, 'Copy source', 'copy_source_value', requiresKnownSource: true),
                $this->strategy('keep_todo', $issueType, 'Keep TODO for now', 'keep_todo_in_run'),
                ...$this->ignoreStrategies($issueType),
            ],
            TranslationIssueType::EQUAL_VALUE => [
                ...$this->ignoreStrategies($issueType),
                $this->strategy('manual_target', $issueType, 'Manual target', 'enter_target_text', requiresManualTarget: true),
                $this->strategy('deepl_translate', $issueType, 'DeepL translate', 'create_deepl_target_suggestion', requiresKnownSource: true, requiresDeepl: true),
                $this->strategy('todo_prefix', $issueType, 'TODO prefix', 'prefix_with_todo'),
            ],
            TranslationIssueType::UNUSED_CANDIDATE => [
                $this->strategy('delete_translation_unit', $issueType, 'Delete translation unit', 'delete_translation_unit', destructive: true, description: 'Deletes the complete XLF trans-unit for this key, including id, source and target values. Matching source and locale units for the same key are removed together.'),
                ...$this->ignoreStrategies($issueType),
            ],
            default => [],
        };
    }

    public function find(string $issueType, string $strategyId): ?SolutionStrategy
    {
        foreach ($this->forIssueType($issueType) as $strategy) {
            if ($strategy->strategyId === $strategyId) {
                return $strategy;
            }
        }

        return null;
    }

    public function findByCommand(string $issueType, string $command): ?SolutionStrategy
    {
        foreach ($this->forIssueType($issueType) as $strategy) {
            if ($strategy->command === $command) {
                return $strategy;
            }
        }

        if ($issueType === TranslationIssueType::UNUSED_CANDIDATE && $command === 'delete_source_and_targets') {
            return $this->strategy('delete_translation_unit', $issueType, 'Delete translation unit', 'delete_source_and_targets', destructive: true, description: 'Deletes the complete XLF trans-unit for this key, including id, source and target values. Matching source and locale units for the same key are removed together.');
        }

        if ($issueType === TranslationIssueType::UNUSED_CANDIDATE && $command === 'delete_target_locale_only') {
            return $this->strategy('delete_target_locale_legacy', $issueType, 'Delete target locale unit', 'delete_target_locale_only', destructive: true);
        }

        return null;
    }

    /**
     * @return SolutionStrategy[]
     */
    private function ignoreStrategies(string $issueType): array
    {
        return [
            $this->strategy('ignore_run', $issueType, 'Ignore in this run', 'ignore_finding_for_run'),
            $this->strategy('ignore_permanent', $issueType, 'Ignore permanently', 'ignore_finding_permanently'),
        ];
    }

    private function strategy(
        string $id,
        string $issueType,
        string $label,
        string $command,
        bool $requiresSelection = true,
        bool $requiresKnownSource = false,
        bool $requiresManualSource = false,
        bool $requiresManualTarget = false,
        bool $requiresTargetKey = false,
        bool $requiresDeepl = false,
        bool $destructive = false,
        string $description = ''
    ): SolutionStrategy {
        return new SolutionStrategy(
            $id,
            $issueType,
            $label,
            $command,
            $requiresSelection,
            $requiresKnownSource,
            $requiresManualSource,
            $requiresManualTarget,
            $requiresTargetKey,
            $requiresDeepl,
            $destructive,
            $description
        );
    }
}
