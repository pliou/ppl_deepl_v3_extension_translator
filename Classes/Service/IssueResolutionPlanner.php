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
            'label' => 'Translate and write',
            'requiresDeepl' => true,
            'disabled' => false,
            'help' => '',
        ];
        $deeplActions = $deeplAllowed ? [$deeplAction] : [];
        $ignoreActions = [
            ['command' => 'ignore_finding_for_run', 'label' => 'Ignore in this run'],
            ['command' => 'ignore_finding_permanently', 'label' => 'Ignore permanently'],
        ];

        return match ($issueType) {
            TranslationIssueType::KEY_MISMATCH_CANDIDATE => [
                ['command' => 'change_key_to_matching_key', 'label' => 'Change key to matching key', 'requiresTargetKey' => true],
                ['command' => 'create_alias_source_unit', 'label' => 'Create alias unit'],
                ...$ignoreActions,
            ],
            TranslationIssueType::KEYLESS_UNIT => [
                ['command' => 'enter_key_manually', 'label' => 'Enter key manually', 'requiresTargetKey' => true],
                ['command' => 'link_keyless_unit_to_key', 'label' => 'Link to missing code key', 'requiresTargetKey' => true],
                ['command' => 'delete_invalid_unit', 'label' => 'Delete invalid unit'],
                ...$ignoreActions,
            ],
            TranslationIssueType::MISSING_SOURCE_FROM_LOCALE_CANDIDATE => [
                ['command' => 'use_other_locale_as_source', 'label' => 'Copy locale candidate into source'],
                ['command' => 'enter_source_manually', 'label' => 'Manual source', 'requiresManualSource' => true],
                ...$ignoreActions,
            ],
            TranslationIssueType::MISSING_SOURCE_UNIT => [
                ['command' => 'enter_source_text', 'label' => 'Manual source', 'requiresManualSource' => true],
                ...$ignoreActions,
            ],
            TranslationIssueType::MISSING_TRANSLATION_UNIT => [
                ['command' => 'create_manual_translation_unit', 'label' => 'Manual source and target', 'requiresManualSource' => true, 'requiresManualTarget' => true],
                ...$ignoreActions,
            ],
            TranslationIssueType::LOCALE_GAP => [
                ['command' => 'copy_source_unit_without_target', 'label' => 'Copy unit without translation'],
                ...$ignoreActions,
            ],
            TranslationIssueType::MISSING_TARGET => [
                ...$deeplActions,
                ['command' => 'copy_source_value', 'label' => 'Copy source'],
                ['command' => 'write_todo_target', 'label' => 'TODO target'],
                ['command' => 'enter_target_text', 'label' => 'Manual target', 'requiresManualTarget' => true],
                ['command' => 'create_empty_target_unit', 'label' => 'Create empty target'],
                ...$ignoreActions,
            ],
            TranslationIssueType::TODO_SOURCE => [
                ['command' => 'enter_source_text', 'label' => 'Manual source', 'requiresManualSource' => true],
                ...$ignoreActions,
            ],
            TranslationIssueType::TODO_VALUE => [
                ...$deeplActions,
                ['command' => 'enter_target_text', 'label' => 'Manual target', 'requiresManualTarget' => true],
                ['command' => 'copy_source_value', 'label' => 'Copy source'],
                ['command' => 'keep_todo_in_run', 'label' => 'Keep TODO in this run'],
                ...$ignoreActions,
            ],
            TranslationIssueType::EQUAL_VALUE => [
                ...$ignoreActions,
                ['command' => 'enter_target_text', 'label' => 'Manual target', 'requiresManualTarget' => true],
                ...$deeplActions,
                ['command' => 'prefix_with_todo', 'label' => 'TODO prefix'],
            ],
            TranslationIssueType::UNUSED_CANDIDATE => [
                ['command' => 'delete_translation_unit', 'label' => 'Delete translation unit'],
                ...$ignoreActions,
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
        ];
    }

    private function messageForIssueType(string $issueType, TranslationFinding $finding): string
    {
        return match ($issueType) {
            TranslationIssueType::KEY_MISMATCH_CANDIDATE => 'A matching source text exists under another key. Prefer one shared key when both texts mean the same thing; create an alias only if both keys must remain.',
            TranslationIssueType::KEYLESS_UNIT => 'This XLF unit has source or target text but no usable trans-unit id.',
            TranslationIssueType::MISSING_SOURCE_FROM_LOCALE_CANDIDATE => 'The source is missing. Text from another locale is only a candidate until you copy or enter it.',
            TranslationIssueType::MISSING_SOURCE_UNIT => 'The key is used in code or templates, but the source is missing or not reliable.',
            TranslationIssueType::MISSING_TRANSLATION_UNIT => 'The key is used in code, but no XLF unit exists anywhere. Source and target need manual input.',
            TranslationIssueType::LOCALE_GAP => 'This existing locale file lacks a key that exists in the source file. Copy id and source; leave target empty.',
            TranslationIssueType::MISSING_TARGET => 'The source exists, but this target locale has no target value.',
            TranslationIssueType::TODO_SOURCE => 'The source currently contains a TODO placeholder.',
            TranslationIssueType::TODO_VALUE => 'The target currently contains a TODO placeholder.',
            TranslationIssueType::EQUAL_VALUE => 'Source and target are equal. Review whether that is intentional.',
            TranslationIssueType::UNUSED_CANDIDATE => 'No scanned static usage was found. Delete removes the complete XLF unit for this key across matching source and locale files.',
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
