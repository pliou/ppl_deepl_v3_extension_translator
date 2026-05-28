<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationFinding;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue\SourceStatus;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue\TranslationIssueType;

final class IssueResolutionPlanner
{
    /**
     * @param TranslationFinding[] $selectedFindings
     * @return array<string, mixed>
     */
    public function plan(array $selectedFindings): array
    {
        if ($selectedFindings === []) {
            return [
                'state' => 'none',
                'selectedCount' => 0,
                'message' => 'Select one issue type to see valid actions.',
                'actions' => [],
                'fields' => $this->emptyFields(),
                'deeplAllowed' => false,
            ];
        }

        $types = array_values(array_unique(array_map(static fn(TranslationFinding $finding): string => $finding->issueType, $selectedFindings)));
        if (count($types) > 1) {
            return [
                'state' => 'mixed',
                'selectedCount' => count($selectedFindings),
                'message' => 'Selected rows contain multiple issue types. Filter or select one issue type before choosing an action.',
                'actions' => [],
                'fields' => $this->emptyFields(),
                'deeplAllowed' => false,
                'issueTypes' => $types,
            ];
        }

        $type = $types[0];
        $first = $selectedFindings[0];
        $keyMismatchContext = $type === TranslationIssueType::KEY_MISMATCH_CANDIDATE ? $this->keyMismatchContext($selectedFindings) : [];
        $deeplAllowed = $type !== TranslationIssueType::KEY_MISMATCH_CANDIDATE && $this->allSourcesKnownForDeepl($selectedFindings);
        $actions = $this->actionsForIssueType($type, $deeplAllowed, $keyMismatchContext);
        $fields = $this->fieldsForActions($actions);

        return [
            'state' => 'ready',
            'selectedCount' => count($selectedFindings),
            'issueType' => $type,
            'baseIssueType' => $first->baseIssueType !== '' ? $first->baseIssueType : $first->issueType,
            'issueLabel' => TranslationIssueType::label($type),
            'message' => $this->messageForIssueType($type, $first),
            'actions' => $actions,
            'fields' => $fields,
            'deeplAllowed' => $deeplAllowed,
            'cannotChangeReason' => $first->cannotChangeReason,
            'currentUsages' => $this->currentUsages($selectedFindings),
            'relatedCandidates' => $this->relatedCandidates($selectedFindings),
            'keyMismatchConflict' => (bool)($keyMismatchContext['candidateUsedInCode'] ?? false),
        ];
    }

    /**
     * @param TranslationFinding[] $findings
     */
    private function allSourcesKnownForDeepl(array $findings): bool
    {
        foreach ($findings as $finding) {
            if (!SourceStatus::canUseForDeepl($finding->sourceStatus) || trim($finding->sourceValue) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function actionsForIssueType(string $issueType, bool $deeplAllowed, array $context = []): array
    {
        $deeplAction = [
            'command' => 'create_deepl_target_suggestion',
            'label' => 'Create DeepL suggestion',
            'requiresDeepl' => true,
            'disabled' => false,
            'help' => '',
        ];
        $deeplActions = $deeplAllowed ? [$deeplAction] : [];

        return match ($issueType) {
            TranslationIssueType::KEY_MISMATCH_CANDIDATE => !empty($context['candidateUsedInCode']) ? [
                ['command' => 'show_scanned_usage', 'label' => 'Show both key usages'],
                ['command' => 'mark_intentionally_reused', 'label' => 'Mark both keys as intentional'],
                ['command' => 'ignore_finding_for_run', 'label' => 'Ignore in this run'],
            ] : [
                ['command' => 'show_scanned_usage', 'label' => 'Use existing key in code manually'],
                ['command' => 'ignore_finding_for_run', 'label' => 'Ignore in this run'],
                ['command' => 'mark_intentionally_reused', 'label' => 'Mark reuse as intentional'],
            ],
            TranslationIssueType::KEYLESS_UNIT => [
                ['command' => 'enter_key_manually', 'label' => 'Enter key manually', 'requiresTargetKey' => true],
                ['command' => 'link_keyless_unit_to_key', 'label' => 'Link to missing code key', 'requiresTargetKey' => true],
                ['command' => 'delete_invalid_unit_with_backup', 'label' => 'Delete invalid unit with backup', 'requiresDeleteConfirmation' => true],
                ['command' => 'ignore_finding_for_run', 'label' => 'Ignore in this run'],
            ],
            TranslationIssueType::MISSING_SOURCE_FROM_LOCALE_CANDIDATE => [
                ['command' => 'use_other_locale_as_source', 'label' => 'Create source from locale text'],
                ['command' => 'enter_source_manually', 'label' => 'Create manual source suggestion', 'requiresManualSource' => true],
                ['command' => 'mark_locale_source_candidate_reviewed', 'label' => 'Mark as OK permanently'],
                ['command' => 'ignore_finding_for_run', 'label' => 'Ignore in this run'],
            ],
            TranslationIssueType::MISSING_SOURCE_UNIT => [
                ['command' => 'enter_source_text', 'label' => 'Create manual source suggestion', 'requiresManualSource' => true],
                ['command' => 'use_key_as_temporary_source', 'label' => 'Use key as temporary source'],
                ['command' => 'write_todo_source', 'label' => 'Create TODO source'],
                ['command' => 'link_to_candidate', 'label' => 'Link to candidate', 'requiresTargetKey' => true],
                ['command' => 'ignore_finding_for_run', 'label' => 'Ignore in this run'],
            ],
            TranslationIssueType::MISSING_TARGET => [
                ...$deeplActions,
                ['command' => 'copy_source_value', 'label' => 'Create copy-source suggestion'],
                ['command' => 'write_todo_target', 'label' => 'Create TODO target suggestion'],
                ['command' => 'enter_target_text', 'label' => 'Create manual target suggestion', 'requiresManualTarget' => true],
                ['command' => 'create_empty_target_unit', 'label' => 'Create empty target unit'],
                ['command' => 'ignore_finding_for_run', 'label' => 'Ignore in this run'],
            ],
            TranslationIssueType::TODO_VALUE => [
                ...$deeplActions,
                ['command' => 'enter_target_text', 'label' => 'Create manual target suggestion', 'requiresManualTarget' => true],
                ['command' => 'copy_source_value', 'label' => 'Create copy-source suggestion'],
                ['command' => 'keep_todo_in_run', 'label' => 'Keep TODO in this run'],
                ['command' => 'mark_todo_reviewed', 'label' => 'Mark TODO as reviewed'],
            ],
            TranslationIssueType::EQUAL_VALUE => [
                ['command' => 'ignore_finding_for_run', 'label' => 'Ignore in this run'],
                ['command' => 'always_ignore_key', 'label' => 'Always ignore this key'],
                ['command' => 'enter_target_text', 'label' => 'Create manual target suggestion', 'requiresManualTarget' => true],
                ...$deeplActions,
                ['command' => 'prefix_with_todo', 'label' => 'Create TODO target suggestion'],
            ],
            TranslationIssueType::UNUSED_CANDIDATE => [
                ['command' => 'show_scanned_usage', 'label' => 'Show scanned usage'],
                ['command' => 'mark_dynamic_keep', 'label' => 'Mark as dynamic / keep'],
                ['command' => 'ignore_finding_for_run', 'label' => 'Ignore in this run'],
                ['command' => 'delete_target_locale_only', 'label' => 'Delete target locale only', 'requiresDeleteConfirmation' => true],
                ['command' => 'delete_source_and_targets', 'label' => 'Delete source and targets', 'requiresDeleteConfirmation' => true],
            ],
            TranslationIssueType::LOCALE_GAP => [
                ['command' => 'create_target_xlf_file', 'label' => 'Create target XLF file'],
                ['command' => 'create_missing_units_as_todo', 'label' => 'Create missing units as TODO'],
                ['command' => 'ignore_finding_for_run', 'label' => 'Ignore in this run'],
            ],
            TranslationIssueType::CANNOT_CHANGE => [
                ['command' => 'show_cannot_change_reason', 'label' => 'Show reason'],
                ['command' => 'export_findings', 'label' => 'Export findings'],
            ],
            default => [],
        };
    }

    /**
     * @param array<int, array<string, mixed>> $actions
     * @return array<string, bool>
     */
    private function fieldsForActions(array $actions): array
    {
        $fields = $this->emptyFields();
        foreach ($actions as $action) {
            foreach ([
                'requiresManualSource' => 'manualSource',
                'requiresManualTarget' => 'manualTarget',
                'requiresTargetKey' => 'targetKey',
                'requiresDeleteConfirmation' => 'deleteConfirmation',
            ] as $flag => $field) {
                if (!empty($action[$flag])) {
                    $fields[$field] = true;
                }
            }
        }

        return $fields;
    }

    /**
     * @return array<string, bool>
     */
    private function emptyFields(): array
    {
        return [
            'manualSource' => false,
            'manualTarget' => false,
            'targetKey' => false,
            'deleteConfirmation' => false,
        ];
    }

    private function messageForIssueType(string $issueType, TranslationFinding $finding): string
    {
        return match ($issueType) {
            TranslationIssueType::KEY_MISMATCH_CANDIDATE => 'A matching source text exists under another key. Review both keys and prefer changing the code to the existing key; if both keys are used, resolve it manually.',
            TranslationIssueType::KEYLESS_UNIT => 'This XLF unit has source or target text but no usable trans-unit id.',
            TranslationIssueType::MISSING_SOURCE_FROM_LOCALE_CANDIDATE => 'The default source is missing, but another locale contains text for this key.',
            TranslationIssueType::MISSING_SOURCE_UNIT => 'The key is used in code or templates, but no reliable source unit exists yet.',
            TranslationIssueType::MISSING_TARGET => 'The source exists, but this target locale has no target value.',
            TranslationIssueType::TODO_VALUE => 'The target currently contains a TODO placeholder.',
            TranslationIssueType::EQUAL_VALUE => 'Source and target are equal. Review whether that is intentional.',
            TranslationIssueType::UNUSED_CANDIDATE => 'No scanned usage was found. The key may still be used dynamically.',
            TranslationIssueType::LOCALE_GAP => 'A target locale file is missing for this source XLF file.',
            TranslationIssueType::CANNOT_CHANGE => $finding->cannotChangeReason !== '' ? $finding->cannotChangeReason : 'This finding cannot be changed in the current scope.',
            default => '',
        };
    }

    /**
     * @param TranslationFinding[] $findings
     * @return array<string, mixed>
     */
    private function keyMismatchContext(array $findings): array
    {
        foreach ($findings as $finding) {
            foreach ($finding->relatedCandidates as $candidate) {
                if (!empty($candidate['usedInCode'])) {
                    return ['candidateUsedInCode' => true];
                }
            }
        }

        return ['candidateUsedInCode' => false];
    }

    /**
     * @param TranslationFinding[] $findings
     * @return string[]
     */
    private function currentUsages(array $findings): array
    {
        $usages = [];
        foreach ($findings as $finding) {
            foreach ($finding->sourceFiles as $sourceFile) {
                $usages[$finding->transUnitId . ' @ ' . $sourceFile] = true;
            }
        }

        return array_keys($usages);
    }

    /**
     * @param TranslationFinding[] $findings
     * @return array<int, array<string, mixed>>
     */
    private function relatedCandidates(array $findings): array
    {
        $candidates = [];
        foreach ($findings as $finding) {
            foreach ($finding->relatedCandidates as $candidate) {
                $key = (string)($candidate['key'] ?? '');
                $file = (string)($candidate['file'] ?? '');
                $dedupeKey = $key . '|' . $file;
                if ($key === '' || isset($candidates[$dedupeKey])) {
                    continue;
                }
                $candidates[$dedupeKey] = $candidate;
            }
        }

        return array_values($candidates);
    }
}
