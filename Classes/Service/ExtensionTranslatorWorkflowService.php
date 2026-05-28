<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationAuditReport;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationFinding;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\WritePreview;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue\TranslationIssueType;
use Ppl\PplDeeplV3Requests\Service\DeeplConfigurationService;

final class ExtensionTranslatorWorkflowService
{
    public function __construct(
        private readonly ExtensionPathResolver $pathResolver,
        private readonly TranslationAuditService $auditService,
        private readonly TranslationRequestBuilder $requestBuilder,
        private readonly TranslationProviderInterface $translationProvider,
        private readonly TranslationWriteService $writeService,
        private readonly EnvironmentGuard $environmentGuard,
        private readonly TranslationOptionProvider $translationOptionProvider,
        private readonly DeeplConfigurationService $configurationService,
        private readonly IgnoreRuleService $ignoreRuleService,
        private readonly SolutionStrategyRegistry $solutionStrategyRegistry,
        private readonly IssueActionPlanner $issueActionPlanner,
        private readonly SelectionStateService $selectionStateService,
        private readonly SuggestionWorkspaceService $suggestionWorkspaceService
    ) {}

    public function handle(array $body): array
    {
        $action = (string)($body['module_action'] ?? '');
        $scanPath = trim((string)($body['scan_path'] ?? '')) ?: $this->pathResolver->getDefaultPath();
        $activeTab = (string)($body['active_tab'] ?? 'overview');
        $activeSolution = (string)($body['active_solution_strategy'] ?? '');
        $selectedIds = $this->normalizeStringList($body['selected_findings'] ?? []);
        $ignoredIds = $this->normalizeStringList($body['ignored_findings'] ?? []);
        $selectedLanguageFiles = $this->normalizeStringList($body['selected_language_files'] ?? []);
        $translatedValues = $this->suggestionWorkspaceService->getSuggestionsForSelection($scanPath, $selectedLanguageFiles);
        $sourceLanguage = (string)($body['source_language'] ?? 'EN');
        $targetLanguage = (string)($body['target_language'] ?? '');
        $resolutionAction = (string)($body['resolution_action'] ?? '');
        $messages = [];
        $report = null;
        $preview = null;
        $writeResult = null;
        $redirectAfterWrite = false;
        $redirectNotice = '';
        $redirectWrittenRows = 0;
        $redirectAffectedFiles = 0;

        $notice = (string)($body['ppl_et_notice'] ?? '');
        if ($notice === 'write_complete') {
            $messages[] = $this->message(
                'success',
                sprintf(
                    'Write complete: %d operation(s), %d file(s).',
                    (int)($body['ppl_et_written_rows'] ?? 0),
                    (int)($body['ppl_et_affected_files'] ?? 0)
                )
            );
        } elseif ($notice === 'no_operations') {
            $messages[] = $this->message('warning', 'No writable operations were selected.');
        }

        if ($action !== '') {
            try {
                if ($action === 'scan') {
                    $this->suggestionWorkspaceService->clearForScopeChange();
                    $translatedValues = [];
                    $activeSolution = '';
                }

                $report = $this->auditService->audit($scanPath, $selectedLanguageFiles);
                $selectedIds = $this->selectedIdsForActiveTab($report, $selectedIds, $activeTab);
                $selectedFindings = $report->findByIds($selectedIds);
                $strategy = $this->solutionStrategyRegistry->find($activeTab, $activeSolution);
                if (!$strategy) {
                    $strategy = $this->solutionStrategyRegistry->findByCommand($activeTab, $action);
                    if ($strategy) {
                        $activeSolution = $strategy->strategyId;
                    }
                }

                if ($action === 'scan') {
                    $messages[] = $this->message('success', 'Scan complete.');
                } elseif ($action === 'refresh_selection') {
                    // State-only refresh after selecting rows, files, issue tabs or solution tabs.
                } elseif ($action === 'discard_suggestions') {
                    $this->suggestionWorkspaceService->discardSuggestions($scanPath, $selectedLanguageFiles);
                    $translatedValues = [];
                    $preview = null;
                    $messages[] = $this->message('success', 'Suggestion discarded.');
                } elseif ($action === 'ignore_finding_for_run' || $action === 'keep_todo_in_run') {
                    $ignoredIds = array_values(array_unique(array_merge($ignoredIds, $selectedIds)));
                    $selectedIds = [];
                    $messages[] = $this->message('success', 'Selected row(s) hidden for this run.');
                    $this->suggestionWorkspaceService->discardSuggestions($scanPath, $selectedLanguageFiles);
                    $translatedValues = [];
                } elseif (in_array($action, ['add_to_edit_list', 'add_to_cleanup_list'], true)) {
                    if ($selectedFindings === []) {
                        $messages[] = $this->message('warning', 'Select at least one row.');
                    } else {
                        $ignoredIds = array_values(array_unique(array_merge($ignoredIds, $selectedIds)));
                        $selectedIds = [];
                        $messages[] = $this->message('success', $action === 'add_to_edit_list' ? 'Added to edit list for this run.' : 'Added to cleanup list for this run.');
                    }
                } elseif (in_array($action, ['mark_dynamic_keep', 'always_ignore_key', 'mark_intentionally_reused', 'mark_todo_reviewed', 'mark_locale_source_candidate_reviewed'], true)) {
                    if ($selectedFindings === []) {
                        $messages[] = $this->message('warning', 'Select at least one row.');
                    } else {
                        foreach ($selectedFindings as $finding) {
                            $this->ignoreRuleService->addRule($finding, $action);
                        }
                        $selectedIds = [];
                        $messages[] = $this->message('success', 'Permanent review rule saved.');
                        $report = $this->auditService->audit($scanPath, $selectedLanguageFiles);
                    }
                } elseif (in_array($action, ['show_scanned_usage', 'show_key_mismatch_use_existing', 'show_key_mismatch_use_selected', 'show_cannot_change_reason', 'export_findings'], true)) {
                    $reviewFindings = $selectedFindings !== [] ? $selectedFindings : $this->filteredFindings($report, $activeTab, $ignoredIds);
                    $messages[] = $this->message('info', $this->reviewOnlyMessage($action, $reviewFindings));
                } elseif ($action === 'create_deepl_target_suggestion') {
                    $selectionErrors = $this->selectionErrors($selectedFindings, $activeTab, $strategy);
                    if ($selectionErrors !== []) {
                        foreach ($selectionErrors as $selectionError) {
                            $messages[] = $this->message('warning', $selectionError);
                        }
                    } else {
                        [$selectedFindings, $translatedValues, $translationErrors] = $this->translateFindings($selectedFindings, $body);
                        foreach ($translationErrors as $translationError) {
                            $messages[] = $this->message('error', $translationError);
                        }
                        $this->suggestionWorkspaceService->storeSuggestion($scanPath, $selectedLanguageFiles, $action, $translatedValues);
                        $preview = $this->writeService->buildPreview($selectedFindings, $action, ['translated_values' => $translatedValues]);
                    }
                } elseif ($action === 'write_selected') {
                    $resolutionAction = $resolutionAction !== '' ? $resolutionAction : (string)($body['last_resolution_action'] ?? '');
                    if ($resolutionAction === '') {
                        $messages[] = $this->message('warning', 'Create a suggestion before writing.');
                    } else {
                        $preview = $this->writeService->buildPreview($selectedFindings, $resolutionAction, $this->writeValuesFromBody($body, $translatedValues));
                        $confirmed = !empty($body['confirm_write']);
                        $productionConfirmed = !empty($body['confirm_production_write']);

                        if (!$confirmed) {
                            $messages[] = $this->message('warning', 'Review confirmation is required before writing.');
                        } elseif ($this->environmentGuard->isProduction() && !$productionConfirmed) {
                            $messages[] = $this->message('warning', 'Production write confirmation is required.');
                        } elseif (!$preview->hasOperations()) {
                            $messages[] = $this->message('warning', 'No writable operations were selected.');
                            $preview = null;
                            $selectedIds = [];
                            $translatedValues = [];
                            $resolutionAction = '';
                            $redirectAfterWrite = true;
                            $redirectNotice = 'no_operations';
                        } else {
                            $writeResult = $this->writeService->write($preview);
                            $messages[] = $this->message(
                                $writeResult['errors'] === [] ? 'success' : 'warning',
                                sprintf('Write complete: %d operation(s), %d file(s).', $writeResult['writtenRows'], $writeResult['affectedFiles'])
                            );
                            foreach ($writeResult['errors'] as $error) {
                                $messages[] = $this->message('error', $error);
                            }
                            $report = $this->auditService->audit($scanPath, $selectedLanguageFiles);
                            if ($writeResult['errors'] === []) {
                                $preview = null;
                                $selectedIds = [];
                                $translatedValues = [];
                                $resolutionAction = '';
                                $this->suggestionWorkspaceService->clearAfterWrite($scanPath, $selectedLanguageFiles);
                                $redirectAfterWrite = true;
                                $redirectNotice = 'write_complete';
                                $redirectWrittenRows = (int)$writeResult['writtenRows'];
                                $redirectAffectedFiles = (int)$writeResult['affectedFiles'];
                            }
                        }
                    }
                } elseif ($this->isWritePreviewAction($action)) {
                    $selectionErrors = $this->selectionErrors($selectedFindings, $activeTab, $strategy);
                    if ($selectionErrors !== []) {
                        foreach ($selectionErrors as $selectionError) {
                            $messages[] = $this->message('warning', $selectionError);
                        }
                    } elseif ($this->actionNeedsDeleteConfirmation($action) && empty($body['confirm_delete'])) {
                        $messages[] = $this->message('warning', 'Delete actions require explicit backup/delete confirmation.');
                    } else {
                        $preview = $this->writeService->buildPreview($selectedFindings, $action, $this->writeValuesFromBody($body, $translatedValues));
                    }
                }
            } catch (\Throwable $exception) {
                $messages[] = $this->message('error', $exception->getMessage());
            }
        }

        if ($preview instanceof WritePreview && !$preview->hasOperations() && $preview->errors === []) {
            $preview = null;
        }

        $reportArray = $report instanceof TranslationAuditReport ? $this->reportForView($report, $activeTab, $selectedIds, $ignoredIds, $translatedValues) : null;
        $visibleFindingsForPanel = $report instanceof TranslationAuditReport ? $this->filteredFindings($report, $activeTab, $ignoredIds) : [];
        $selectedFindingsForPanel = $report instanceof TranslationAuditReport ? $report->findByIds($selectedIds) : [];

        return [
            'activeTab' => $activeTab,
            'activeSolution' => $activeSolution,
            'authKeyConfigured' => $this->configurationService->getAuthKey() !== '',
            'capabilities' => $this->translationProvider->getCapabilities()->toArray(),
            'formData' => [
                'scanPath' => $scanPath,
                'sourceLanguage' => $sourceLanguage,
                'targetLanguage' => $targetLanguage,
                'glossaryId' => (string)($body['glossary_id'] ?? ''),
                'styleRuleId' => (string)($body['style_rule_id'] ?? ''),
                'tagHandling' => (string)($body['tag_handling'] ?? ''),
                'customInstructions' => (string)($body['custom_instructions'] ?? ''),
                'manualSourceText' => (string)($body['manual_source_text'] ?? ''),
                'manualTargetText' => (string)($body['manual_target_text'] ?? ''),
                'targetKey' => (string)($body['target_key'] ?? ''),
            ],
            'messages' => $messages,
            'preview' => $preview instanceof WritePreview ? $preview->toArray() : null,
            'report' => $reportArray,
            'safety' => $this->environmentGuard->getSafetyState(),
            'scopeOptions' => $this->pathResolver->getScopeOptions(),
            'selectedIds' => $selectedIds,
            'ignoredIds' => $ignoredIds,
            'selectedLanguageFiles' => $selectedLanguageFiles,
            'actionPanel' => $this->issueActionPlanner->plan($activeTab, $activeSolution, $visibleFindingsForPanel, $selectedFindingsForPanel, $translatedValues),
            'translationOptions' => $this->translationOptionProvider->buildOptions($sourceLanguage, $targetLanguage),
            'translatedValues' => $translatedValues,
            'writeResult' => $writeResult,
            'issueTabs' => $this->issueTabs(),
            'redirectAfterWrite' => $redirectAfterWrite,
            'redirectNotice' => $redirectNotice,
            'redirectWrittenRows' => $redirectWrittenRows,
            'redirectAffectedFiles' => $redirectAffectedFiles,
        ];
    }

    /**
     * @param TranslationFinding[] $findings
     * @return array{0: TranslationFinding[], 1: array<string, string>, 2: string[]}
     */
    private function translateFindings(array $findings, array $body): array
    {
        $build = $this->requestBuilder->buildRequests($findings, $body);
        $translatedValues = [];
        $errors = array_values($build['errors']);

        foreach ($build['requests'] as $request) {
            $result = $this->translationProvider->translateBatch($request);
            foreach ($result->translations as $id => $translation) {
                if ($translation !== '') {
                    $translatedValues[$id] = $translation;
                }
            }
            foreach ($result->errors as $error) {
                $errors[] = $error;
            }
        }

        $findings = array_map(
            static function (TranslationFinding $finding) use ($translatedValues, $build): TranslationFinding {
                if (isset($translatedValues[$finding->findingId])) {
                    return $finding->withSuggestedValue($translatedValues[$finding->findingId], true);
                }

                if (isset($build['errors'][$finding->findingId])) {
                    return $finding->withErrorState($build['errors'][$finding->findingId]);
                }

                return $finding;
            },
            $findings
        );

        return [$findings, $translatedValues, array_values(array_unique($errors))];
    }

    /**
     * @param string[] $selectedIds
     * @param string[] $ignoredIds
     * @param array<string, string> $translatedValues
     */
    private function reportForView(TranslationAuditReport $report, string $activeTab, array $selectedIds, array $ignoredIds, array $translatedValues): array
    {
        $selectedLookup = array_fill_keys($selectedIds, true);
        $ignoredLookup = array_fill_keys($ignoredIds, true);
        $selectedFileLookup = array_fill_keys($report->selectedLanguageFiles, true);
        $translatedLookup = $translatedValues;
        $reportArray = $report->toArray();
        $findings = array_values(array_filter(
            $report->findings,
            static fn(TranslationFinding $finding): bool => !isset($ignoredLookup[$finding->findingId])
        ));
        $reportArray['languageFiles'] = array_map(
            function (array $file) use ($selectedFileLookup, $findings): array {
                $file['selected'] = $selectedFileLookup === [] || isset($selectedFileLookup[$file['relativePath']]);
                $file['extensionKey'] = $this->extensionKeyFromRelativePath((string)$file['relativePath']);
                $file['shortRelativePath'] = $this->shortLanguageFilePath((string)$file['relativePath']);
                $file['localeLabel'] = (bool)($file['canonical'] ?? false)
                    ? 'source: ' . ((string)($file['sourceLanguage'] ?? '') !== '' ? (string)$file['sourceLanguage'] : 'en')
                    : ((string)($file['locale'] ?? '') !== '' ? (string)$file['locale'] : 'locale unknown');
                $file['statusSummary'] = $this->languageFileStatusSummary((string)$file['relativePath'], $findings);
                $file['searchText'] = mb_strtolower(implode(' ', [
                    $file['extensionKey'],
                    $file['relativePath'],
                    $file['localeLabel'],
                    $file['statusSummary'],
                ]));

                return $file;
            },
            $reportArray['languageFiles']
        );

        if (in_array($activeTab, TranslationIssueType::all(), true)) {
            $findings = array_values(array_filter(
                $findings,
                static fn(TranslationFinding $finding): bool => $finding->issueType === $activeTab
            ));
        }

        $reportArray['filteredFindings'] = array_map(
            static function (TranslationFinding $finding) use ($selectedLookup, $translatedLookup): array {
                $row = $finding->toArray();
                $row['selected'] = isset($selectedLookup[$finding->findingId]);
                $row['searchText'] = mb_strtolower(implode(' ', [
                    $row['issueLabel'],
                    $row['languageFile'],
                    $row['displayLocale'],
                    $row['displayKey'],
                    $row['sourceValue'],
                    $row['currentTargetValue'],
                    $row['relatedCandidatesLabel'],
                ]));
                if (isset($translatedLookup[$finding->findingId])) {
                    $row['suggestedValue'] = $translatedLookup[$finding->findingId];
                    $row['suggestionText'] = $translatedLookup[$finding->findingId];
                    $row['requiresDeepl'] = true;
                }

                return $row;
            },
            $findings
        );

        return $reportArray;
    }

    /**
     * @param TranslationFinding[] $findings
     */
    private function languageFileStatusSummary(string $relativePath, array $findings): string
    {
        $counts = [];
        $canChange = false;
        foreach ($findings as $finding) {
            if ($finding->languageFile !== $relativePath) {
                continue;
            }
            $counts[$finding->issueType] = (int)($counts[$finding->issueType] ?? 0) + 1;
            $canChange = $canChange || $finding->canChange;
        }

        $parts = [$canChange ? 'Can be changed' : 'Review only'];
        foreach ($counts as $issueType => $count) {
            $parts[] = $count . ' ' . TranslationIssueType::label((string)$issueType);
        }

        return implode(' · ', $parts);
    }

    private function extensionKeyFromRelativePath(string $relativePath): string
    {
        $parts = explode('/', trim($relativePath, '/'));

        return $parts[0] ?? '';
    }

    private function shortLanguageFilePath(string $relativePath): string
    {
        $parts = explode('/', trim($relativePath, '/'));
        array_shift($parts);

        return implode('/', $parts);
    }

    /**
     * @param string[] $selectedIds
     * @return string[]
     */
    private function selectedIdsForActiveTab(TranslationAuditReport $report, array $selectedIds, string $activeTab): array
    {
        if (!in_array($activeTab, TranslationIssueType::all(), true)) {
            return $selectedIds;
        }

        $allowedIds = [];
        foreach ($report->findings as $finding) {
            if ($finding->issueType === $activeTab) {
                $allowedIds[$finding->findingId] = true;
            }
        }

        return array_values(array_filter(
            $selectedIds,
            static fn(string $selectedId): bool => isset($allowedIds[$selectedId])
        ));
    }

    private function selectionCanUseAction(array $actionPanel, array &$messages): bool
    {
        if (($actionPanel['state'] ?? '') === 'mixed') {
            $messages[] = $this->message('warning', (string)$actionPanel['message']);
            return false;
        }

        if ((int)($actionPanel['selectedCount'] ?? 0) === 0) {
            $messages[] = $this->message('warning', 'Select at least one row.');
            return false;
        }

        return true;
    }

    /**
     * @param TranslationFinding[] $selectedFindings
     * @return string[]
     */
    private function selectionErrors(array $selectedFindings, string $activeTab, mixed $strategy): array
    {
        return $this->selectionStateService->validateSelection(
            $selectedFindings,
            $activeTab,
            $strategy instanceof \Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\SolutionStrategy ? $strategy : null
        );
    }

    /**
     * @param string[] $ignoredIds
     * @return TranslationFinding[]
     */
    private function filteredFindings(TranslationAuditReport $report, string $activeTab, array $ignoredIds): array
    {
        $ignoredLookup = array_fill_keys($ignoredIds, true);
        $findings = array_values(array_filter(
            $report->findings,
            static fn(TranslationFinding $finding): bool => !isset($ignoredLookup[$finding->findingId])
        ));

        if (in_array($activeTab, TranslationIssueType::all(), true)) {
            $findings = array_values(array_filter(
                $findings,
                static fn(TranslationFinding $finding): bool => $finding->issueType === $activeTab
            ));
        }

        return $findings;
    }

    private function isWritePreviewAction(string $action): bool
    {
        return in_array($action, [
            'enter_source_text',
            'enter_source_manually',
            'use_key_as_temporary_source',
            'write_todo_source',
            'create_todo_source',
            'create_alias_source_unit',
            'use_other_locale_as_source',
            'link_to_candidate',
            'enter_key_manually',
            'link_keyless_unit_to_key',
            'delete_invalid_unit_with_backup',
            'copy_source_value',
            'write_todo_target',
            'prefix_with_todo',
            'enter_target_text',
            'create_empty_target_unit',
            'delete_target_locale_only',
            'delete_source_and_targets',
            'create_target_xlf_file',
            'create_missing_units_as_todo',
        ], true);
    }

    private function actionNeedsDeleteConfirmation(string $action): bool
    {
        return in_array($action, ['delete_invalid_unit_with_backup', 'delete_target_locale_only', 'delete_source_and_targets'], true);
    }

    /**
     * @param array<string, string> $translatedValues
     * @return array<string, mixed>
     */
    private function writeValuesFromBody(array $body, array $translatedValues): array
    {
        return [
            'manual_source_text' => (string)($body['manual_source_text'] ?? ''),
            'manual_target_text' => (string)($body['manual_target_text'] ?? ''),
            'target_key' => (string)($body['target_key'] ?? ''),
            'translated_values' => $translatedValues,
        ];
    }

    /**
     * @param TranslationFinding[] $findings
     */
    private function reviewOnlyMessage(string $action, array $findings): string
    {
        if ($action === 'show_cannot_change_reason') {
            return $findings[0]->cannotChangeReason ?? 'No reason available.';
        }

        if ($action === 'show_scanned_usage') {
            $parts = [];
            foreach ($findings as $finding) {
                foreach ($finding->sourceFiles as $sourceFile) {
                    $parts[] = $finding->transUnitId . ' is used in ' . $sourceFile;
                }
                foreach ($finding->relatedCandidates as $candidate) {
                    $candidateKey = (string)($candidate['key'] ?? '');
                    foreach (($candidate['usageLocations'] ?? []) as $usageLocation) {
                        $parts[] = $candidateKey . ' is used in ' . (string)$usageLocation;
                    }
                }
            }

            return $parts !== []
                ? implode(' | ', array_values(array_unique($parts)))
                : 'The scanner did not find a static usage for the selected candidate(s). Dynamic usage is still possible.';
        }

        if ($action === 'show_key_mismatch_use_existing' || $action === 'show_key_mismatch_use_selected') {
            return $this->keyMismatchInstructionMessage($findings, $action === 'show_key_mismatch_use_existing');
        }

        return 'Review-only action completed.';
    }

    /**
     * @param TranslationFinding[] $findings
     */
    private function keyMismatchInstructionMessage(array $findings, bool $preferExistingKey): string
    {
        $parts = [];
        foreach ($findings as $finding) {
            $selectedKey = $finding->transUnitId;
            $selectedUsages = $finding->sourceFiles;
            foreach ($finding->relatedCandidates as $candidate) {
                $candidateKey = (string)($candidate['key'] ?? '');
                if ($candidateKey === '') {
                    continue;
                }

                $candidateUsages = array_values(array_filter(array_map('strval', (array)($candidate['usageLocations'] ?? []))));
                if ($preferExistingKey) {
                    $parts[] = sprintf(
                        'Prefer existing XLF key "%s": change "%s" usage(s) to "%s"%s.',
                        $candidateKey,
                        $selectedKey,
                        $candidateKey,
                        $selectedUsages !== [] ? ' in ' . implode(', ', $selectedUsages) : ''
                    );
                    if ($candidateUsages !== []) {
                        $parts[] = sprintf('"%s" is already used in %s.', $candidateKey, implode(', ', $candidateUsages));
                    }
                    continue;
                }

                $parts[] = sprintf(
                    'Prefer selected code key "%s": first create or rename the XLF source unit to "%s", then change "%s" usage(s) to "%s"%s.',
                    $selectedKey,
                    $selectedKey,
                    $candidateKey,
                    $selectedKey,
                    $candidateUsages !== [] ? ' in ' . implode(', ', $candidateUsages) : ''
                );
            }
        }

        return $parts !== []
            ? implode(' | ', array_values(array_unique($parts)))
            : 'No matching key candidate is available for this mismatch.';
    }

    private function message(string $type, string $text): array
    {
        return [
            'type' => $type,
            'text' => $text,
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function issueTabs(): array
    {
        $tabs = [[
            'value' => 'overview',
            'label' => 'Overview',
        ]];

        foreach (TranslationIssueType::all() as $issueType) {
            $tabs[] = [
                'value' => $issueType,
                'label' => TranslationIssueType::label($issueType),
            ];
        }

        return $tabs;
    }

    /**
     * @return string[]
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value), static fn(string $item): bool => $item !== ''));
    }

    /**
     * @return array<string, string>
     */
    private function normalizeStringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $item) {
            $stringValue = trim((string)$item);
            if ($stringValue !== '') {
                $map[(string)$key] = $stringValue;
            }
        }

        return $map;
    }
}
