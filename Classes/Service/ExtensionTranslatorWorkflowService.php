<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationAuditReport;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationFinding;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\WritePreview;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue\SourceStatus;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue\TranslationIssueType;
use Ppl\PplDeeplV3Requests\Service\DeeplConfigurationService;

final class ExtensionTranslatorWorkflowService
{
    private const PERMANENT_IGNORE_TAB = 'permanent_ignores';
    private const MAX_RENDERED_FINDINGS = 500;

    public function __construct(
        private readonly ExtensionPathResolver $pathResolver,
        private readonly ExtensionMetadataResolver $extensionMetadataResolver,
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
        $selectedIgnoreRuleIds = $this->normalizeStringList($body['selected_ignore_rules'] ?? []);
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
                } elseif ($action === 'delete_ignore_rules') {
                    if ($selectedIgnoreRuleIds === []) {
                        $messages[] = $this->message('warning', 'Select at least one blacklist entry.');
                    } else {
                        $deletedRules = $this->ignoreRuleService->deleteRulesByIds($selectedIgnoreRuleIds);
                        $selectedIgnoreRuleIds = [];
                        $messages[] = $deletedRules > 0
                            ? $this->message('success', sprintf('Deleted %d blacklist entr%s.', $deletedRules, $deletedRules === 1 ? 'y' : 'ies'))
                            : $this->message('warning', 'No matching blacklist entry was found.');
                        $report = $this->auditService->audit($scanPath, $selectedLanguageFiles);
                    }
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
                } elseif (in_array($action, ['ignore_finding_permanently', 'mark_dynamic_keep', 'mark_intentionally_reused', 'mark_todo_reviewed', 'mark_locale_source_candidate_reviewed'], true)) {
                    if ($selectedFindings === []) {
                        $messages[] = $this->message('warning', 'Select at least one row.');
                    } else {
                        foreach ($selectedFindings as $finding) {
                            $this->ignoreRuleService->addRule($finding, $this->permanentIgnoreAction($action));
                        }
                        $selectedIds = [];
                        $messages[] = $this->message('success', 'Permanent review rule saved.');
                        $report = $this->auditService->audit($scanPath, $selectedLanguageFiles);
                    }
                } elseif (in_array($action, ['show_scanned_usage', 'show_key_mismatch_use_existing', 'show_key_mismatch_use_selected', 'export_findings'], true)) {
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
                        $preview = $this->writeService->buildPreview($selectedFindings, $action, ['translated_values' => $translatedValues]);
                        $execution = $this->executePreviewWrite($preview);
                        $messages = array_merge($messages, $execution['messages']);
                        $writeResult = $execution['writeResult'];
                        $preview = null;

                        if ($execution['success']) {
                            $report = $this->auditService->audit($scanPath, $selectedLanguageFiles);
                            $selectedIds = [];
                            $translatedValues = [];
                            $resolutionAction = '';
                            $this->suggestionWorkspaceService->clearAfterWrite($scanPath, $selectedLanguageFiles);
                            $redirectAfterWrite = true;
                            $redirectNotice = 'write_complete';
                            $redirectWrittenRows = (int)($writeResult['writtenRows'] ?? 0);
                            $redirectAffectedFiles = (int)($writeResult['affectedFiles'] ?? 0);
                        }
                    }
                } elseif ($this->isWritePreviewAction($action)) {
                    $selectionErrors = $this->selectionErrors($selectedFindings, $activeTab, $strategy);
                    if ($selectionErrors !== []) {
                        foreach ($selectionErrors as $selectionError) {
                            $messages[] = $this->message('warning', $selectionError);
                        }
                    } else {
                        $preview = $this->writeService->buildPreview($selectedFindings, $action, $this->writeValuesFromBody($body, $translatedValues));
                        $execution = $this->executePreviewWrite($preview);
                        $messages = array_merge($messages, $execution['messages']);
                        $writeResult = $execution['writeResult'];
                        $preview = null;

                        if ($execution['success']) {
                            $report = $this->auditService->audit($scanPath, $selectedLanguageFiles);
                            $selectedIds = [];
                            $translatedValues = [];
                            $resolutionAction = '';
                            $this->suggestionWorkspaceService->clearAfterWrite($scanPath, $selectedLanguageFiles);
                            $redirectAfterWrite = true;
                            $redirectNotice = 'write_complete';
                            $redirectWrittenRows = (int)($writeResult['writtenRows'] ?? 0);
                            $redirectAffectedFiles = (int)($writeResult['affectedFiles'] ?? 0);
                        }
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
        $formData = $this->formData($body, $scanPath, $sourceLanguage, $targetLanguage, $selectedFindingsForPanel);
        $ignoreRules = $this->ignoreRulesForView();
        $blacklistActive = $activeTab === self::PERMANENT_IGNORE_TAB;

        return [
            'activeTab' => $activeTab,
            'activeSolution' => $activeSolution,
            'authKeyConfigured' => $this->configurationService->getAuthKey() !== '',
            'capabilities' => $this->translationProvider->getCapabilities()->toArray(),
            'formData' => $formData,
            'messages' => $messages,
            'preview' => $preview instanceof WritePreview ? $preview->toArray() : null,
            'report' => $reportArray,
            'safety' => $this->environmentGuard->getSafetyState(),
            'scopeOptions' => $this->pathResolver->getScopeOptions(),
            'selectedIds' => $selectedIds,
            'ignoredIds' => $ignoredIds,
            'selectedLanguageFiles' => $selectedLanguageFiles,
            'selectedIgnoreRuleIds' => $selectedIgnoreRuleIds,
            'blacklist' => [
                'active' => $blacklistActive,
                'rules' => $ignoreRules,
                'count' => count($ignoreRules),
            ],
            'actionPanel' => $blacklistActive
                ? $this->blacklistActionPanel($ignoreRules)
                : $this->issueActionPlanner->plan($activeTab, $activeSolution, $visibleFindingsForPanel, $selectedFindingsForPanel, $translatedValues),
            'translationOptions' => $this->translationOptionProvider->buildOptions($sourceLanguage, $targetLanguage),
            'translatedValues' => $translatedValues,
            'writeResult' => $writeResult,
            'issueTabs' => $this->issueTabs($reportArray['summary']['issueCounts'] ?? [], count($ignoreRules)),
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
        $reportArray['metricCards'] = $this->metricCards($reportArray['summary']);
        $reportArray['languageFiles'] = array_map(
            function (array $file) use ($selectedFileLookup, $findings, $report): array {
                $extensionMetadata = $this->extensionMetadataResolver->forLanguageFile(
                    $report->scope->absolutePath,
                    (string)$file['relativePath'],
                    $report->scope->extensionKey
                );
                $file['selected'] = $selectedFileLookup === [] || isset($selectedFileLookup[$file['relativePath']]);
                $file['extensionKey'] = $extensionMetadata['extensionKey'];
                $file['shortRelativePath'] = $this->shortLanguageFilePath((string)$file['relativePath']);
                $file['displayName'] = $extensionMetadata['displayName'];
                $file['localeLabel'] = $this->languageFileLocaleLabel($file);
                $file['fileKindLabelKey'] = $this->languageFileKindLabelKey((string)$file['baseName']);
                $file['statusSummary'] = $this->languageFileStatusSummary((string)$file['relativePath'], $findings);
                $file['searchText'] = mb_strtolower(implode(' ', [
                    $file['extensionKey'],
                    $file['displayName'],
                    $file['relativePath'],
                    $file['localeLabel'],
                    $file['baseName'],
                    $file['statusSummary'],
                ]));

                return $file;
            },
            $reportArray['languageFiles']
        );
        $reportArray['fileGroups'] = $this->languageFileGroups($reportArray['languageFiles']);

        if ($activeTab === self::PERMANENT_IGNORE_TAB) {
            $findings = [];
        } elseif (in_array($activeTab, TranslationIssueType::all(), true)) {
            $findings = array_values(array_filter(
                $findings,
                static fn(TranslationFinding $finding): bool => $finding->issueType === $activeTab
            ));
        }

        $filteredFindingsTotal = count($findings);
        if ($filteredFindingsTotal > self::MAX_RENDERED_FINDINGS) {
            $findings = array_slice($findings, 0, self::MAX_RENDERED_FINDINGS);
        }
        $reportArray['filteredFindingsTotal'] = $filteredFindingsTotal;
        $reportArray['filteredFindingsRendered'] = count($findings);
        $reportArray['filteredFindingsLimited'] = $filteredFindingsTotal > count($findings);

        $reportArray['filteredFindings'] = array_map(
            function (TranslationFinding $finding) use ($selectedLookup, $translatedLookup, $report): array {
                $extensionMetadata = $this->extensionMetadataResolver->forLanguageFile(
                    $report->scope->absolutePath,
                    $finding->languageFile,
                    $report->scope->extensionKey
                );
                $row = $finding->toArray();
                $row['extensionKey'] = $extensionMetadata['extensionKey'];
                $row['extensionName'] = $extensionMetadata['displayName'];
                $row['shortLanguageFile'] = $this->shortLanguageFilePath($finding->languageFile);
                $row['selected'] = isset($selectedLookup[$finding->findingId]);
                $row['issueLabelKey'] = $this->issueLabelKey($finding->issueType);
                $row['filterState'] = $this->rowFilterState($finding);
                $row['needsSource'] = $finding->sourceStatus === SourceStatus::MANUAL_SOURCE_REQUIRED || trim($finding->sourceValue) === '';
                $row['searchText'] = mb_strtolower(implode(' ', [
                    $row['issueLabel'],
                    $row['extensionKey'],
                    $row['extensionName'],
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
     * @param array<int, array<string, mixed>> $languageFiles
     * @return array<int, array<string, mixed>>
     */
    private function languageFileGroups(array $languageFiles): array
    {
        $groups = [];
        foreach ($languageFiles as $file) {
            $extensionKey = trim((string)($file['extensionKey'] ?? ''));
            $groupKey = $extensionKey !== '' ? $extensionKey : 'unknown';
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'groupId' => preg_replace('/[^a-z0-9_-]+/i', '-', $groupKey) ?: 'extension',
                    'extensionKey' => $groupKey,
                    'displayName' => (string)($file['displayName'] ?? $groupKey),
                    'files' => [],
                    'fileCount' => 0,
                    'selectedCount' => 0,
                    'unitCount' => 0,
                    'searchParts' => [$groupKey, (string)($file['displayName'] ?? '')],
                ];
            }

            $groups[$groupKey]['files'][] = $file;
            $groups[$groupKey]['fileCount']++;
            $groups[$groupKey]['unitCount'] += (int)($file['unitCount'] ?? 0);
            if ((bool)($file['selected'] ?? false)) {
                $groups[$groupKey]['selectedCount']++;
            }
        }

        $groups = array_map(
            static function (array $group): array {
                $fileCount = (int)$group['fileCount'];
                $selectedCount = (int)$group['selectedCount'];
                $group['allSelected'] = $fileCount > 0 && $selectedCount === $fileCount;
                $group['partiallySelected'] = $selectedCount > 0 && $selectedCount < $fileCount;
                $group['searchText'] = mb_strtolower(implode(' ', array_unique(array_map('strval', $group['searchParts']))));
                unset($group['searchParts']);

                return $group;
            },
            array_values($groups)
        );

        usort(
            $groups,
            static fn(array $left, array $right): int => strcasecmp(
                (string)($left['displayName'] ?? $left['extensionKey'] ?? ''),
                (string)($right['displayName'] ?? $right['extensionKey'] ?? '')
            )
        );

        return $groups;
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

        $parts = ['File issue summary'];
        foreach ($counts as $issueType => $count) {
            $parts[] = $count . ' ' . TranslationIssueType::label((string)$issueType);
        }

        return implode(' · ', $parts);
    }

    private function shortLanguageFilePath(string $relativePath): string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        if (str_starts_with($relativePath, 'Resources/Private/Language/')) {
            return $relativePath;
        }

        $languageRootPosition = strpos($relativePath, '/Resources/Private/Language/');
        if ($languageRootPosition === false) {
            return $relativePath;
        }

        return substr($relativePath, $languageRootPosition + 1);
    }

    /**
     * @param array<string, mixed> $file
     */
    private function languageFileLocaleLabel(array $file): string
    {
        $language = '';
        if ((bool)($file['canonical'] ?? false)) {
            $language = (string)($file['sourceLanguage'] ?? '');
        } else {
            $language = (string)($file['locale'] ?? '');
            if (trim($language) === '') {
                $language = (string)($file['targetLanguage'] ?? '');
            }
        }

        $language = trim($language) !== '' ? trim($language) : '?';

        return strtoupper($language);
    }

    private function languageFileKindLabelKey(string $baseName): string
    {
        return match (strtolower(trim($baseName))) {
            'locallang_mod.xlf' => 'label.fileKind.module',
            'locallang_db.xlf' => 'label.fileKind.database',
            default => 'label.fileKind.main',
        };
    }

    /**
     * @param string[] $selectedIds
     * @return string[]
     */
    private function selectedIdsForActiveTab(TranslationAuditReport $report, array $selectedIds, string $activeTab): array
    {
        if ($activeTab === self::PERMANENT_IGNORE_TAB) {
            return [];
        }

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
        if ($activeTab === self::PERMANENT_IGNORE_TAB) {
            return [];
        }

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
            'change_key_to_matching_key',
            'replace_code_key_with_existing_key',
            'create_alias_source_unit',
            'use_other_locale_as_source',
            'copy_source_unit_without_target',
            'create_manual_translation_unit',
            'enter_key_manually',
            'link_keyless_unit_to_key',
            'delete_invalid_unit',
            'copy_source_value',
            'write_todo_target',
            'prefix_with_todo',
            'enter_target_text',
            'create_empty_target_unit',
            'delete_translation_unit',
            'delete_target_locale_only',
            'delete_source_and_targets',
        ], true);
    }

    /**
     * @return array{messages: array<int, array{type: string, text: string}>, writeResult: ?array{errors: string[], writtenRows: int, affectedFiles: int}, success: bool}
     */
    private function executePreviewWrite(WritePreview $preview): array
    {
        $messages = [];
        if (!$preview->hasOperations()) {
            $errors = $preview->errors !== [] ? $preview->errors : ['No writable operations were selected.'];
            foreach ($errors as $error) {
                $messages[] = $this->message('warning', $error);
            }

            return [
                'messages' => $messages,
                'writeResult' => null,
                'success' => false,
            ];
        }

        $writeResult = $this->writeService->write($preview);
        $messages[] = $this->message(
            $writeResult['errors'] === [] ? 'success' : 'warning',
            sprintf('Write complete: %d operation(s), %d file(s).', $writeResult['writtenRows'], $writeResult['affectedFiles'])
        );
        foreach ($writeResult['errors'] as $error) {
            $messages[] = $this->message('error', $error);
        }

        return [
            'messages' => $messages,
            'writeResult' => $writeResult,
            'success' => $writeResult['errors'] === [],
        ];
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
     * @param TranslationFinding[] $selectedFindings
     * @return array<string, string>
     */
    private function formData(array $body, string $scanPath, string $sourceLanguage, string $targetLanguage, array $selectedFindings): array
    {
        return [
            'scanPath' => $scanPath,
            'sourceLanguage' => $sourceLanguage,
            'targetLanguage' => $targetLanguage,
            'glossaryId' => (string)($body['glossary_id'] ?? ''),
            'styleRuleId' => (string)($body['style_rule_id'] ?? ''),
            'tagHandling' => (string)($body['tag_handling'] ?? ''),
            'customInstructions' => (string)($body['custom_instructions'] ?? ''),
            'manualSourceText' => $this->bodyValueOrDefault($body, 'manual_source_text', $this->manualSourceDefault($selectedFindings)),
            'manualTargetText' => $this->bodyValueOrDefault($body, 'manual_target_text', $this->manualTargetDefault($selectedFindings)),
            'targetKey' => $this->bodyValueOrDefault($body, 'target_key', $this->targetKeyDefault($selectedFindings)),
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function bodyValueOrDefault(array $body, string $field, string $default): string
    {
        return array_key_exists($field, $body) ? (string)$body[$field] : $default;
    }

    /**
     * @param TranslationFinding[] $findings
     */
    private function manualSourceDefault(array $findings): string
    {
        return $this->uniqueDefaultValue($findings, static function (TranslationFinding $finding): string {
            foreach ([
                $finding->sourceValue,
                $finding->suggestedValue,
                (string)($finding->metadata['defaultValue'] ?? ''),
            ] as $value) {
                if (trim($value) !== '') {
                    return $value;
                }
            }

            return '';
        });
    }

    /**
     * @param TranslationFinding[] $findings
     */
    private function manualTargetDefault(array $findings): string
    {
        return $this->uniqueDefaultValue($findings, static function (TranslationFinding $finding): string {
            foreach ([
                $finding->currentTargetValue,
                $finding->suggestedValue,
            ] as $value) {
                if (trim($value) !== '') {
                    return $value;
                }
            }

            return '';
        });
    }

    /**
     * @param TranslationFinding[] $findings
     */
    private function targetKeyDefault(array $findings): string
    {
        return $this->uniqueDefaultValue($findings, static function (TranslationFinding $finding): string {
            foreach ($finding->relatedCandidates as $candidate) {
                $key = (string)($candidate['key'] ?? '');
                if (trim($key) !== '') {
                    return $key;
                }
            }

            return !str_starts_with($finding->transUnitId, '__keyless_') ? $finding->transUnitId : '';
        });
    }

    /**
     * @param TranslationFinding[] $findings
     */
    private function uniqueDefaultValue(array $findings, \Closure $valueForFinding): string
    {
        $values = [];
        foreach ($findings as $finding) {
            $value = (string)$valueForFinding($finding);
            if (trim($value) === '') {
                continue;
            }
            $values[trim($value)] = $value;
        }

        return count($values) === 1 ? (string)reset($values) : '';
    }

    /**
     * @param TranslationFinding[] $findings
     */
    private function reviewOnlyMessage(string $action, array $findings): string
    {
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
                        'Change selected key "%s" to matching key "%s"%s.',
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
                    'Keep selected key "%s" as an alias: create or rename the XLF source unit to "%s", then change "%s" usage(s) to "%s"%s.',
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
     * @return array<int, array<string, mixed>>
     */
    private function ignoreRulesForView(): array
    {
        return array_map(
            function (array $rule): array {
                $issueType = (string)($rule['issueType'] ?? '');
                $rule['issueLabelKey'] = $this->issueLabelKey($issueType);

                return $rule;
            },
            $this->ignoreRuleService->readRulesForView()
        );
    }

    /**
     * @param array<int, array<string, mixed>> $ignoreRules
     * @return array<string, mixed>
     */
    private function blacklistActionPanel(array $ignoreRules): array
    {
        return [
            'issueInfo' => [
                'active' => true,
                'issueType' => self::PERMANENT_IGNORE_TAB,
                'issueLabel' => 'Ignore permanently',
                'issueLabelKey' => 'issue.ignorePermanently',
                'message' => 'These entries are hidden by the permanent ignore list. Delete blacklisting entries to show them in scans again.',
                'visibleRows' => count($ignoreRules),
                'selectedRows' => 0,
                'canChangeCount' => 0,
                'needsSourceCount' => 0,
                'cannotChangeCount' => 0,
                'currentUsages' => [],
                'relatedCandidates' => [],
                'keyMismatchConflict' => false,
            ],
            'solutionTabs' => [],
            'activeSolution' => '',
            'selectionSummary' => [
                'selectedRows' => 0,
                'canChange' => 0,
                'needsSource' => 0,
                'cannotChange' => 0,
            ],
            'tool' => [
                'state' => 'blacklist',
                'title' => 'Permanent ignore list',
                'message' => 'Select blacklist entries and delete them to restore the default scanner result.',
                'fields' => [],
                'validationErrors' => [],
            ],
            'suggestionSummary' => [
                'count' => 0,
                'hasSuggestions' => false,
            ],
            'writeSummary' => [],
        ];
    }

    /**
     * @param array<string, int> $issueCounts
     * @return array{primary: array<int, array<string, mixed>>, other: array<int, array<string, mixed>>, otherCount: int}
     */
    private function issueTabs(array $issueCounts, int $permanentIgnoreCount): array
    {
        $primaryTypes = [
            TranslationIssueType::KEYLESS_UNIT,
            TranslationIssueType::KEY_MISMATCH_CANDIDATE,
            TranslationIssueType::MISSING_SOURCE_UNIT,
            TranslationIssueType::MISSING_TARGET,
            TranslationIssueType::TODO_SOURCE,
            TranslationIssueType::TODO_VALUE,
            TranslationIssueType::UNUSED_CANDIDATE,
            TranslationIssueType::LOCALE_GAP,
        ];

        $primary = [[
            'value' => 'overview',
            'labelKey' => 'tab.overview',
            'count' => array_sum(array_map('intval', $issueCounts)),
        ]];

        foreach ($primaryTypes as $issueType) {
            $primary[] = $this->issueTab($issueType, $issueCounts);
        }

        $other = [];
        foreach (TranslationIssueType::all() as $issueType) {
            if (in_array($issueType, $primaryTypes, true)) {
                continue;
            }
            $other[] = $this->issueTab($issueType, $issueCounts);
        }
        $other[] = [
            'value' => self::PERMANENT_IGNORE_TAB,
            'labelKey' => 'issue.ignorePermanently',
            'count' => $permanentIgnoreCount,
        ];

        return [
            'primary' => $primary,
            'other' => $other,
            'otherCount' => array_sum(array_map(static fn(array $tab): int => (int)$tab['count'], $other)),
        ];
    }

    /**
     * @param array<string, int> $issueCounts
     * @return array<string, mixed>
     */
    private function issueTab(string $issueType, array $issueCounts): array
    {
        return [
            'value' => $issueType,
            'labelKey' => $this->issueLabelKey($issueType),
            'count' => (int)($issueCounts[$issueType] ?? 0),
        ];
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

    /**
     * @param array<string, mixed> $summary
     * @return array<int, array<string, mixed>>
     */
    private function metricCards(array $summary): array
    {
        return [
            ['value' => (int)($summary['xlfFiles'] ?? 0), 'labelKey' => 'summary.xlfFiles', 'kind' => 'neutral'],
            ['value' => (int)($summary['codeKeys'] ?? 0), 'labelKey' => 'summary.codeKeys', 'kind' => 'neutral'],
            ['value' => (int)($summary[TranslationIssueType::KEYLESS_UNIT] ?? 0), 'labelKey' => 'summary.keyless', 'kind' => 'blue'],
            ['value' => (int)($summary[TranslationIssueType::MISSING_SOURCE_UNIT] ?? 0), 'labelKey' => 'summary.missingSource', 'kind' => 'amber'],
            ['value' => (int)($summary[TranslationIssueType::MISSING_TARGET] ?? 0), 'labelKey' => 'summary.missingTarget', 'kind' => 'amber'],
            ['value' => (int)($summary[TranslationIssueType::TODO_SOURCE] ?? 0), 'labelKey' => 'summary.todoSource', 'kind' => 'green'],
            ['value' => (int)($summary[TranslationIssueType::TODO_VALUE] ?? 0), 'labelKey' => 'summary.todoTarget', 'kind' => 'neutral'],
            ['value' => (int)($summary[TranslationIssueType::UNUSED_CANDIDATE] ?? 0), 'labelKey' => 'summary.extra', 'kind' => 'neutral'],
        ];
    }

    private function rowFilterState(TranslationFinding $finding): string
    {
        if (!$finding->canChange) {
            return 'read_only';
        }
        if ($finding->sourceStatus === SourceStatus::MANUAL_SOURCE_REQUIRED || trim($finding->sourceValue) === '') {
            return 'needs_source';
        }
        if ($finding->actionState !== '') {
            return $finding->actionState;
        }

        return 'changeable';
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

    private function permanentIgnoreAction(string $action): string
    {
        return in_array($action, ['mark_dynamic_keep', 'mark_intentionally_reused', 'mark_todo_reviewed', 'mark_locale_source_candidate_reviewed'], true)
            ? 'ignore_finding_permanently'
            : $action;
    }
}
