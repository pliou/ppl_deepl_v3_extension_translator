<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\ActionPanelViewModel;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\SolutionStrategy;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationFinding;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue\SourceStatus;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue\TranslationIssueType;

final class IssueActionPlanner
{
    public function __construct(
        private readonly SolutionStrategyRegistry $strategyRegistry,
        private readonly SelectionStateService $selectionStateService
    ) {}

    /**
     * @param TranslationFinding[] $visibleFindings
     * @param TranslationFinding[] $selectedFindings
     * @param array<string, string> $suggestions
     */
    public function plan(string $activeIssueType, string $activeSolution, array $visibleFindings, array $selectedFindings, array $suggestions = []): array
    {
        $issueKnown = in_array($activeIssueType, TranslationIssueType::all(), true);
        $strategies = $issueKnown ? $this->strategyRegistry->forIssueType($activeIssueType) : [];
        $strategy = $issueKnown ? $this->strategyRegistry->find($activeIssueType, $activeSolution) : null;
        if (!$strategy instanceof SolutionStrategy) {
            $activeSolution = '';
        }

        $selectionSummary = $this->selectionStateService->summarize($selectedFindings);
        $issueInfo = $this->issueInfo($activeIssueType, $visibleFindings, $selectedFindings);
        $tool = $this->tool($activeIssueType, $strategy, $visibleFindings, $selectedFindings);
        $suggestionSummary = [
            'count' => count($suggestions),
            'hasSuggestions' => $suggestions !== [],
        ];

        return (new ActionPanelViewModel(
            $issueInfo,
            array_map(
                fn(SolutionStrategy $candidate): array => $candidate->toArray(
                    $candidate->strategyId === $activeSolution,
                    $this->strategyDisabled($candidate, $selectedFindings),
                    $this->strategyDisabledReason($candidate, $selectedFindings)
                ),
                $strategies
            ),
            $activeSolution,
            $selectionSummary,
            $tool,
            $suggestionSummary
        ))->toArray();
    }

    /**
     * @param TranslationFinding[] $visibleFindings
     * @param TranslationFinding[] $selectedFindings
     * @return array<string, mixed>
     */
    private function issueInfo(string $activeIssueType, array $visibleFindings, array $selectedFindings): array
    {
        $issueKnown = in_array($activeIssueType, TranslationIssueType::all(), true);
        $first = $selectedFindings[0] ?? $visibleFindings[0] ?? null;

        return [
            'active' => $issueKnown,
            'issueType' => $activeIssueType,
            'issueLabel' => $issueKnown ? TranslationIssueType::label($activeIssueType) : 'Review findings',
            'issueLabelKey' => $issueKnown ? $this->issueLabelKey($activeIssueType) : 'issue.reviewFindings',
            'message' => $issueKnown && $first instanceof TranslationFinding ? $this->messageForIssueType($activeIssueType, $first) : 'Choose an issue type to review findings.',
            'visibleRows' => count($visibleFindings),
            'selectedRows' => count($selectedFindings),
            'canChangeCount' => count(array_filter($selectedFindings, static fn(TranslationFinding $finding): bool => $finding->canChange)),
            'needsSourceCount' => count(array_filter($selectedFindings, static fn(TranslationFinding $finding): bool => $finding->sourceStatus === SourceStatus::MANUAL_SOURCE_REQUIRED || trim($finding->sourceValue) === '')),
            'cannotChangeCount' => count(array_filter($selectedFindings, static fn(TranslationFinding $finding): bool => !$finding->canChange)),
            'currentUsages' => $this->currentUsages($selectedFindings),
            'relatedCandidates' => $this->relatedCandidates($selectedFindings),
            'keyMismatchConflict' => $this->keyMismatchConflict($selectedFindings),
        ];
    }

    /**
     * @param TranslationFinding[] $visibleFindings
     * @param TranslationFinding[] $selectedFindings
     * @return array<string, mixed>
     */
    private function tool(string $activeIssueType, ?SolutionStrategy $strategy, array $visibleFindings, array $selectedFindings): array
    {
        if (!in_array($activeIssueType, TranslationIssueType::all(), true)) {
            return [
                'state' => 'no_issue',
                'title' => 'Review findings',
                'message' => 'Open an issue tab to see valid solution strategies.',
            ];
        }

        if (!$strategy instanceof SolutionStrategy) {
            return [
                'state' => 'choose_solution',
                'title' => TranslationIssueType::label($activeIssueType),
                'message' => 'Choose a solution strategy below the counters before running an action.',
                'showSelectVisible' => false,
            ];
        }

        $validationErrors = $this->selectionStateService->validateSelection($selectedFindings, $activeIssueType, $strategy);
        $hasRows = $selectedFindings !== [];
        $disabled = $validationErrors !== [];
        $isReviewAction = !$strategy->requiresSelection;
        if ($isReviewAction && $visibleFindings !== []) {
            $disabled = false;
            $validationErrors = [];
        }

        return [
            'state' => $hasRows ? 'ready' : 'no_rows',
            'title' => $strategy->label,
            'message' => $strategy->description !== '' ? $strategy->description : $this->strategyMessage($strategy),
            'strategy' => $strategy->toArray(false, $disabled, implode(' ', $validationErrors)),
            'fields' => [
                'manualSource' => $strategy->requiresManualSource,
                'manualTarget' => $strategy->requiresManualTarget,
                'targetKey' => $strategy->requiresTargetKey,
            ],
            'targetKeySuggestions' => $strategy->requiresTargetKey ? $this->targetKeySuggestions($selectedFindings !== [] ? $selectedFindings : $visibleFindings) : [],
            'showDeeplSettings' => $strategy->requiresDeepl && !$disabled,
            'showSelectVisible' => !$hasRows && $visibleFindings !== [] && $strategy->requiresSelection,
            'actionDisabled' => $disabled,
            'actionCommand' => $strategy->command,
            'actionLabel' => $this->actionLabel($strategy),
            'validationErrors' => $validationErrors,
            'destructive' => $strategy->destructive,
        ];
    }

    /**
     * @param TranslationFinding[] $findings
     */
    private function strategyDisabled(SolutionStrategy $strategy, array $findings): bool
    {
        if ($findings === [] || !$strategy->requiresKnownSource) {
            return false;
        }

        return $this->strategyDisabledReason($strategy, $findings) !== '';
    }

    /**
     * @param TranslationFinding[] $findings
     */
    private function strategyDisabledReason(SolutionStrategy $strategy, array $findings): string
    {
        if (!$strategy->requiresKnownSource) {
            return '';
        }

        foreach ($findings as $finding) {
            if ($strategy->requiresDeepl && !SourceStatus::canUseForDeepl($finding->sourceStatus)) {
                return 'DeepL needs a known XLF source text.';
            }
            if (trim($finding->sourceValue) === '') {
                return 'Needs source text first.';
            }
        }

        return '';
    }

    private function actionLabel(SolutionStrategy $strategy): string
    {
        if ($strategy->requiresDeepl) {
            return 'Translate and write';
        }

        return match ($strategy->command) {
            'change_key_to_matching_key', 'replace_code_key_with_existing_key' => 'Change key to matching key',
            'create_alias_source_unit' => 'Create alias unit',
            'use_other_locale_as_source' => 'Write locale text as source',
            'copy_source_unit_without_target' => 'Copy unit without translation',
            'create_manual_translation_unit' => 'Create manual translation unit',
            'ignore_finding_for_run' => 'Ignore selected in this run',
            'ignore_finding_permanently' => 'Ignore permanently',
            'enter_source_text', 'enter_source_manually' => 'Write manual source',
            'enter_target_text' => 'Write manual target',
            'copy_source_value' => 'Copy source to target',
            'write_todo_target' => 'Write TODO target',
            'prefix_with_todo' => 'Write TODO prefix',
            'create_empty_target_unit' => 'Create empty target',
            'enter_key_manually' => 'Assign key',
            'link_keyless_unit_to_key' => 'Link to key',
            'delete_invalid_unit' => 'Delete invalid unit',
            'delete_target_locale_only' => 'Delete target locale unit',
            'delete_translation_unit', 'delete_source_and_targets' => 'Delete translation unit',
            default => (
                str_starts_with($strategy->command, 'show_')
                || str_starts_with($strategy->command, 'mark_')
                || str_starts_with($strategy->command, 'add_')
                || $strategy->command === 'export_findings'
            ) ? $strategy->label : $strategy->label,
        };
    }

    private function issueLabelKey(string $issueType): string
    {
        return match ($issueType) {
            TranslationIssueType::KEYLESS_UNIT => 'issue.keylessUnits',
            TranslationIssueType::KEY_MISMATCH_CANDIDATE => 'issue.possibleKeyMismatch',
            TranslationIssueType::MISSING_SOURCE_FROM_LOCALE_CANDIDATE => 'issue.missingSourceLocaleCandidate',
            TranslationIssueType::MISSING_SOURCE_UNIT => 'issue.missingSource',
            TranslationIssueType::MISSING_TRANSLATION_UNIT => 'issue.missingTranslationUnit',
            TranslationIssueType::LOCALE_GAP => 'issue.localeGaps',
            TranslationIssueType::MISSING_TARGET => 'issue.missingTarget',
            TranslationIssueType::TODO_SOURCE => 'issue.todoSource',
            TranslationIssueType::TODO_VALUE => 'issue.todoTarget',
            TranslationIssueType::EQUAL_VALUE => 'issue.equalValue',
            TranslationIssueType::UNUSED_CANDIDATE => 'issue.unusedCandidates',
            default => 'issue.unknown',
        };
    }

    private function strategyMessage(SolutionStrategy $strategy): string
    {
        if ($strategy->requiresDeepl) {
            return 'Translates the selected rows with DeepL and writes the result to XLF immediately.';
        }
        if ($strategy->destructive) {
            return 'This action changes files immediately when you run it.';
        }

        return 'Select rows and run this strategy directly.';
    }

    private function messageForIssueType(string $issueType, TranslationFinding $finding): string
    {
        if ($issueType === TranslationIssueType::MISSING_TRANSLATION_UNIT && !empty($finding->metadata['hardcodedConfigLabel'])) {
            return 'This TYPO3 configuration label is hardcoded. Create or confirm the XLF unit and replace the label with an LLL reference.';
        }

        return match ($issueType) {
            TranslationIssueType::KEY_MISMATCH_CANDIDATE => 'A matching source text exists under another key. Prefer changing the selected key to the matching key; code usages of the old key are carried along when they exist. Create an alias unit only if both keys must remain.',
            TranslationIssueType::KEYLESS_UNIT => 'This XLF unit has source or target text but no usable trans-unit id.',
            TranslationIssueType::MISSING_SOURCE_FROM_LOCALE_CANDIDATE => 'The source unit is missing. Text from another locale is shown only as a candidate and is not treated as a reliable source until you copy or enter it.',
            TranslationIssueType::MISSING_SOURCE_UNIT => 'The key is used in code or templates, but the source is missing or not reliable.',
            TranslationIssueType::MISSING_TRANSLATION_UNIT => 'The key is used in code, but no XLF unit exists anywhere. Enter source and target text manually to create the complete unit.',
            TranslationIssueType::LOCALE_GAP => 'This existing locale file is missing a key that exists in the source file. Copy the id and source only; the target stays empty for the Missing target workflow.',
            TranslationIssueType::MISSING_TARGET => 'The source exists, but this target locale has no target value.',
            TranslationIssueType::TODO_SOURCE => 'The source contains a TODO placeholder. Fix the source text first; target actions stay blocked until the source is real.',
            TranslationIssueType::TODO_VALUE => 'The target currently contains a TODO placeholder while the source is usable.',
            TranslationIssueType::EQUAL_VALUE => 'Source and target are equal. Review whether that is intentional.',
            TranslationIssueType::UNUSED_CANDIDATE => 'No scanned static usage was found. Delete removes the complete XLF unit for this key across matching source and locale files.',
            default => '',
        };
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

    /**
     * @param TranslationFinding[] $findings
     * @return array<int, array<string, string>>
     */
    private function targetKeySuggestions(array $findings): array
    {
        $suggestions = [];
        foreach ($findings as $finding) {
            foreach ($finding->relatedCandidates as $candidate) {
                $key = trim((string)($candidate['key'] ?? ''));
                if ($key === '') {
                    continue;
                }

                $source = trim((string)($candidate['source'] ?? $candidate['text'] ?? ''));
                $file = trim((string)($candidate['file'] ?? $candidate['languageFile'] ?? ''));
                $dedupeKey = $key;
                if (isset($suggestions[$dedupeKey])) {
                    continue;
                }

                $suggestions[$dedupeKey] = [
                    'key' => $key,
                    'source' => $source,
                    'file' => $file,
                    'label' => trim($key . ($source !== '' ? ' = ' . $source : '')),
                ];
            }
        }

        return array_values($suggestions);
    }

    /**
     * @param TranslationFinding[] $findings
     */
    private function keyMismatchConflict(array $findings): bool
    {
        foreach ($findings as $finding) {
            foreach ($finding->relatedCandidates as $candidate) {
                if (!empty($candidate['usedInCode'])) {
                    return true;
                }
            }
        }

        return false;
    }
}
