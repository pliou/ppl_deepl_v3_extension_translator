<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\SolutionStrategy;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationFinding;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue\SourceStatus;

final class SelectionStateService
{
    /**
     * @param TranslationFinding[] $findings
     * @return string[]
     */
    public function validateSelection(array $findings, string $activeIssueType, ?SolutionStrategy $strategy): array
    {
        if ($findings === []) {
            return ['Select at least one row.'];
        }

        $errors = [];
        foreach ($findings as $finding) {
            if ($finding->issueType !== $activeIssueType) {
                $errors[] = 'Actions are issue-specific. Clear selection or switch to the selected issue type.';
                break;
            }
        }

        if (!$strategy instanceof SolutionStrategy) {
            $errors[] = 'Choose a solution strategy first.';
            return array_values(array_unique($errors));
        }

        if (!$this->allowsReadOnlySelection($strategy)) {
            foreach ($findings as $finding) {
                if (!$finding->canChange) {
                    $errors[] = $finding->cannotChangeReason !== ''
                        ? $finding->cannotChangeReason
                        : 'Selected rows are read-only. Use an ignore action if this should stay out of the working list.';
                    break;
                }
            }
        }

        if ($strategy->requiresKnownSource) {
            foreach ($findings as $finding) {
                if ($strategy->requiresDeepl && (!SourceStatus::canUseForDeepl($finding->sourceStatus) || trim($finding->sourceValue) === '')) {
                    $errors[] = 'DeepL needs a known XLF source text.';
                    break;
                }
                if (!$strategy->requiresDeepl && trim($finding->sourceValue) === '') {
                    $errors[] = 'This solution needs a known source text.';
                    break;
                }
            }
        }

        return array_values(array_unique($errors));
    }

    private function allowsReadOnlySelection(SolutionStrategy $strategy): bool
    {
        return in_array($strategy->command, [
            'ignore_finding_for_run',
            'ignore_finding_permanently',
            'keep_todo_in_run',
            'show_scanned_usage',
            'show_key_mismatch_use_existing',
            'show_key_mismatch_use_selected',
            'export_findings',
        ], true);
    }

    /**
     * @param TranslationFinding[] $findings
     * @return array<string, int>
     */
    public function summarize(array $findings): array
    {
        $summary = [
            'selectedRows' => count($findings),
            'canChange' => 0,
            'needsSource' => 0,
            'cannotChange' => 0,
            'canTranslate' => 0,
        ];

        foreach ($findings as $finding) {
            if ($finding->canChange) {
                $summary['canChange']++;
            } else {
                $summary['cannotChange']++;
            }
            if ($finding->sourceStatus === SourceStatus::MANUAL_SOURCE_REQUIRED || trim($finding->sourceValue) === '') {
                $summary['needsSource']++;
            }
            if (SourceStatus::canUseForDeepl($finding->sourceStatus) && trim($finding->sourceValue) !== '') {
                $summary['canTranslate']++;
            }
        }

        return $summary;
    }
}
