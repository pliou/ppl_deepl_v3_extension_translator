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
                $this->strategy('create_from_match', $issueType, 'Create XLF unit from matched key', 'create_alias_source_unit', requiresKnownSource: true, description: 'Adds the selected code key to XLF and copies the source text from the matching existing key.'),
                $this->strategy('use_existing_key_in_code', $issueType, 'Change code to existing XLF key', 'show_key_mismatch_use_existing', false, description: 'Shows which selected code-key usages should be changed to the existing XLF key.'),
                $this->strategy('mark_intentional', $issueType, 'Mark as intentional', 'mark_intentionally_reused', description: 'Keeps this mismatch out of future review noise.'),
                $this->strategy('ignore_run', $issueType, 'Ignore in this run', 'ignore_finding_for_run'),
            ],
            TranslationIssueType::KEYLESS_UNIT => [
                $this->strategy('assign_key', $issueType, 'Assign key manually', 'enter_key_manually', requiresTargetKey: true),
                $this->strategy('link_keyless', $issueType, 'Link to missing code key', 'link_keyless_unit_to_key', requiresTargetKey: true),
                $this->strategy('delete_invalid', $issueType, 'Delete invalid unit', 'delete_invalid_unit_with_backup', destructive: true, confirmationLabel: 'I confirm backup-protected delete.'),
                $this->strategy('ignore_run', $issueType, 'Ignore in this run', 'ignore_finding_for_run'),
            ],
            TranslationIssueType::MISSING_SOURCE_FROM_LOCALE_CANDIDATE => [
                $this->strategy('use_locale_source', $issueType, 'Create source from locale text', 'use_other_locale_as_source', requiresKnownSource: true, description: 'Creates the missing source unit using the text found in the locale file. Review language before writing.'),
                $this->strategy('manual_source', $issueType, 'Manual source', 'enter_source_manually', requiresManualSource: true),
                $this->strategy('mark_reviewed', $issueType, 'Mark as OK permanently', 'mark_locale_source_candidate_reviewed', description: 'Stores this candidate as reviewed so it is not shown again. No XLF file is changed.'),
                $this->strategy('ignore_run', $issueType, 'Ignore in this run', 'ignore_finding_for_run'),
            ],
            TranslationIssueType::MISSING_SOURCE_UNIT => [
                $this->strategy('manual_source', $issueType, 'Manual source', 'enter_source_text', requiresManualSource: true),
                $this->strategy('key_as_source', $issueType, 'Use key as source', 'use_key_as_temporary_source'),
                $this->strategy('todo_source', $issueType, 'Create TODO source', 'write_todo_source'),
                $this->strategy('ignore_run', $issueType, 'Ignore in this run', 'ignore_finding_for_run'),
            ],
            TranslationIssueType::LOCALE_GAP => [
                $this->strategy('create_locale_file', $issueType, 'Create locale file', 'create_target_xlf_file'),
                $this->strategy('todo_missing_units', $issueType, 'Create TODO units', 'create_missing_units_as_todo'),
                $this->strategy('ignore_run', $issueType, 'Ignore in this run', 'ignore_finding_for_run'),
            ],
            TranslationIssueType::MISSING_TARGET => [
                $this->strategy('deepl_translate', $issueType, 'DeepL translate', 'create_deepl_target_suggestion', requiresKnownSource: true, requiresDeepl: true),
                $this->strategy('manual_target', $issueType, 'Manual target', 'enter_target_text', requiresManualTarget: true),
                $this->strategy('copy_source', $issueType, 'Copy source', 'copy_source_value', requiresKnownSource: true),
                $this->strategy('todo_target', $issueType, 'TODO target', 'write_todo_target'),
                $this->strategy('empty_target', $issueType, 'Create empty target', 'create_empty_target_unit'),
                $this->strategy('ignore_run', $issueType, 'Ignore in this run', 'ignore_finding_for_run'),
            ],
            TranslationIssueType::TODO_VALUE => [
                $this->strategy('deepl_translate', $issueType, 'DeepL translate', 'create_deepl_target_suggestion', requiresKnownSource: true, requiresDeepl: true),
                $this->strategy('manual_target', $issueType, 'Manual target', 'enter_target_text', requiresManualTarget: true),
                $this->strategy('copy_source', $issueType, 'Copy source', 'copy_source_value', requiresKnownSource: true),
                $this->strategy('keep_todo', $issueType, 'Keep TODO for now', 'keep_todo_in_run'),
                $this->strategy('mark_reviewed', $issueType, 'Mark TODO reviewed', 'mark_todo_reviewed'),
                $this->strategy('ignore_run', $issueType, 'Ignore in this run', 'ignore_finding_for_run'),
            ],
            TranslationIssueType::EQUAL_VALUE => [
                $this->strategy('ignore_run', $issueType, 'Ignore in this run', 'ignore_finding_for_run'),
                $this->strategy('always_ignore', $issueType, 'Always ignore key', 'always_ignore_key'),
                $this->strategy('edit_list', $issueType, 'Add to edit list', 'add_to_edit_list'),
                $this->strategy('manual_target', $issueType, 'Manual target', 'enter_target_text', requiresManualTarget: true),
                $this->strategy('deepl_translate', $issueType, 'DeepL translate', 'create_deepl_target_suggestion', requiresKnownSource: true, requiresDeepl: true),
                $this->strategy('todo_prefix', $issueType, 'TODO prefix', 'prefix_with_todo'),
            ],
            TranslationIssueType::UNUSED_CANDIDATE => [
                $this->strategy('show_usage', $issueType, 'Show usage search', 'show_scanned_usage', false),
                $this->strategy('keep_dynamic', $issueType, 'Keep / ignore permanently', 'mark_dynamic_keep'),
                $this->strategy('cleanup_list', $issueType, 'Add to cleanup list', 'add_to_cleanup_list'),
                $this->strategy('delete_source_targets', $issueType, 'Delete with backup', 'delete_source_and_targets', destructive: true, confirmationLabel: 'I confirm backup-protected delete.'),
                $this->strategy('delete_target_locale', $issueType, 'Delete target locale only', 'delete_target_locale_only', destructive: true, confirmationLabel: 'I confirm backup-protected target-locale delete.'),
                $this->strategy('ignore_run', $issueType, 'Ignore in this run', 'ignore_finding_for_run'),
            ],
            TranslationIssueType::CANNOT_CHANGE => [
                $this->strategy('show_reason', $issueType, 'Show reason', 'show_cannot_change_reason', false),
                $this->strategy('copy_instructions', $issueType, 'Copy manual instructions', 'export_findings', false),
                $this->strategy('ignore_run', $issueType, 'Ignore in this run', 'ignore_finding_for_run'),
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

        return null;
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
        string $confirmationLabel = '',
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
            $confirmationLabel,
            $description
        );
    }
}
