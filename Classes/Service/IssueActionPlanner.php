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
            'message' => $issueKnown && $first instanceof TranslationFinding ? $this->messageForIssueType($activeIssueType, $first) : 'Choose an issue type to review findings.',
            'visibleRows' => count($visibleFindings),
            'selectedRows' => count($selectedFindings),
            'canChangeCount' => count(array_filter($selectedFindings, static fn(TranslationFinding $finding): bool => $finding->canChange)),
            'needsSourceCount' => count(array_filter($selectedFindings, static fn(TranslationFinding $finding): bool => trim($finding->sourceValue) === '')),
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
                'deleteConfirmation' => $strategy->destructive,
            ],
            'targetKeySuggestions' => $strategy->requiresTargetKey ? $this->targetKeySuggestions($selectedFindings !== [] ? $selectedFindings : $visibleFindings) : [],
            'showDeeplSettings' => $strategy->requiresDeepl && !$disabled,
            'showSelectVisible' => !$hasRows && $visibleFindings !== [] && $strategy->requiresSelection,
            'actionDisabled' => $disabled,
            'actionCommand' => $strategy->command,
            'actionLabel' => $this->actionLabel($strategy),
            'validationErrors' => $validationErrors,
            'destructive' => $strategy->destructive,
            'confirmationLabel' => $strategy->confirmationLabel,
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
            return 'Create DeepL suggestion';
        }
        if ($strategy->command === 'create_alias_source_unit') {
            return 'Create matched XLF suggestion';
        }
        if ($strategy->command === 'ignore_finding_for_run') {
            return 'Ignore selected in this run';
        }
        if (
            str_starts_with($strategy->command, 'show_')
            || str_starts_with($strategy->command, 'mark_')
            || str_starts_with($strategy->command, 'add_')
            || $strategy->command === 'always_ignore_key'
            || $strategy->command === 'export_findings'
        ) {
            return $strategy->label;
        }

        return 'Create suggestion';
    }

    private function strategyMessage(SolutionStrategy $strategy): string
    {
        if ($strategy->requiresDeepl) {
            return 'Creates suggestions only. Writing to XLF is a separate confirmation step.';
        }
        if ($strategy->destructive) {
            return 'This action is destructive and requires backup confirmation before a write summary is created.';
        }

        return 'Select rows and create a suggestion for this strategy.';
    }

    private function messageForIssueType(string $issueType, TranslationFinding $finding): string
    {
        return match ($issueType) {
            TranslationIssueType::KEY_MISMATCH_CANDIDATE => 'A matching source text exists under another key. You can create the selected code key from that match, or change code to use the existing key.',
            TranslationIssueType::KEYLESS_UNIT => 'This XLF unit has source or target text but no usable trans-unit id.',
            TranslationIssueType::MISSING_SOURCE_FROM_LOCALE_CANDIDATE => 'The key is used, but the source file has no source unit. A locale file contains text for the same key, so it can be used as a candidate source after review.',
            TranslationIssueType::MISSING_SOURCE_UNIT => 'The key is used in code or templates, but no reliable source unit exists yet.',
            TranslationIssueType::LOCALE_GAP => 'A target locale file is missing for this source XLF file.',
            TranslationIssueType::MISSING_TARGET => 'The source exists, but this target locale has no target value.',
            TranslationIssueType::TODO_VALUE => 'The target currently contains a TODO placeholder.',
            TranslationIssueType::EQUAL_VALUE => 'Source and target are equal. Review whether that is intentional.',
            TranslationIssueType::UNUSED_CANDIDATE => 'No scanned usage was found. The key may still be used dynamically.',
            TranslationIssueType::CANNOT_CHANGE => $finding->cannotChangeReason !== '' ? $finding->cannotChangeReason : 'This finding cannot be changed in the current scope.',
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
                $dedupeKey = $key . '|' . $source . '|' . $file;
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
