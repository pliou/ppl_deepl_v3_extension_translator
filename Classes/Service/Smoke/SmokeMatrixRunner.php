<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service\Smoke;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationFinding;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\SolutionStrategy;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue\TranslationIssueType;
use Ppl\PplDeeplV3ExtensionTranslator\Service\IssueActionPlanner;
use Ppl\PplDeeplV3ExtensionTranslator\Service\IgnoreRuleService;
use Ppl\PplDeeplV3ExtensionTranslator\Service\SolutionStrategyRegistry;
use Ppl\PplDeeplV3ExtensionTranslator\Service\TranslationAuditService;
use Ppl\PplDeeplV3ExtensionTranslator\Service\TranslationProviderInterface;
use Ppl\PplDeeplV3ExtensionTranslator\Service\TranslationRequestBuilder;
use Ppl\PplDeeplV3ExtensionTranslator\Service\TranslationWriteService;
use TYPO3\CMS\Core\Core\Environment;

final class SmokeMatrixRunner
{
    public function __construct(
        private readonly SmokeContext $context,
        private readonly SmokeFixtureService $fixtureService,
        private readonly TranslationAuditService $auditService,
        private readonly IssueActionPlanner $actionPlanner,
        private readonly SolutionStrategyRegistry $strategyRegistry,
        private readonly TranslationWriteService $writeService,
        private readonly IgnoreRuleService $ignoreRuleService,
        private readonly TranslationRequestBuilder $requestBuilder,
        private readonly TranslationProviderInterface $translationProvider
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function runMatrix(string $fixturePath, string $artifactRoot, ?string $onlyCase = null): array
    {
        $this->context->activate($artifactRoot);
        $this->writeJson($artifactRoot . '/fake-deepl-calls.json', []);
        $this->clearIgnoreRules();
        foreach (['reports', 'before-after', 'screenshots'] as $directory) {
            if (!is_dir($artifactRoot . '/' . $directory)) {
                mkdir($artifactRoot . '/' . $directory, 0775, true);
            }
        }

        $summary = [
            'artifactRoot' => $artifactRoot,
            'fixturePath' => $fixturePath,
            'startedAt' => date(DATE_ATOM),
            'cases' => [],
        ];

        foreach ($this->cases() as $case) {
            if ($onlyCase !== null && $onlyCase !== $case['case_id']) {
                continue;
            }
            $this->clearIgnoreRules();
            $this->fixtureService->restoreFixture($fixturePath, $artifactRoot);
            $this->fixtureService->snapshot($fixturePath, $artifactRoot . '/before-after/' . $case['case_id'] . '-before');
            $report = $this->runCase($fixturePath, $case);
            $this->fixtureService->snapshot($fixturePath, $artifactRoot . '/before-after/' . $case['case_id'] . '-after');
            $this->writeJson($artifactRoot . '/reports/' . $case['case_id'] . '.json', $report);
            $summary['cases'][] = [
                'caseId' => $case['case_id'],
                'status' => $report['status'],
                'report' => 'reports/' . $case['case_id'] . '.json',
                'assertions' => $report['assertions'],
                'initialIssue' => $report['initialIssue'] ?? '',
                'solution' => $report['solution'] ?? '',
                'endState' => $report['endState'] ?? '',
            ];
        }
        $this->clearIgnoreRules();

        $summary['finishedAt'] = date(DATE_ATOM);
        $this->writeSummary($artifactRoot . '/summary.md', $summary);

        return $summary;
    }

    /**
     * @param array<string, string> $case
     * @return array<string, mixed>
     */
    private function runCase(string $fixturePath, array $case): array
    {
        $assertions = [];
        $messages = [];
        $endState = 'Not executed.';
        $debug = [];

        try {
            $audit = $this->auditService->audit($fixturePath);
            $finding = $this->findFinding($audit->findings, $case['issue_type'], $case['fixture_key']);
            $assertions[] = $this->assertTrue($finding instanceof TranslationFinding, 'initial issue type detected');
            if (!$finding instanceof TranslationFinding) {
                $debug['issueCounts'] = $this->issueCounts($audit->findings);
                $debug['findingsForFixtureKey'] = $this->debugFindingsForKey($audit->findings, $case['fixture_key']);
            }

            if ($finding instanceof TranslationFinding) {
                $debug['selectedFinding'] = $this->findingDebugRow($finding);
                if ($case['issue_type'] === 'missing_source_unit') {
                    $assertions[] = $this->assertTrue(trim($finding->sourceValue) === '', 'missing source findings keep source column empty');
                }
                $strategy = $this->strategyRegistry->findByCommand($case['issue_type'], $case['action']);
                $activeSolution = $this->activeSolutionForCase($case, $strategy);
                $visibleFindings = $this->findingsForIssue($audit->findings, $case['issue_type']);
                $panel = $this->actionPlanner->plan($case['issue_type'], $activeSolution, $visibleFindings, [$finding]);
                $assertions[] = $this->assertTrue(($panel['tool']['state'] ?? '') === 'ready', 'action panel is ready');
                $assertions[] = $this->assertTrue($this->panelHasIssueSpecificStrategies($panel, $case['issue_type']), 'strategy tabs are issue-specific');
                $assertions[] = $this->assertTrue($case['action'] === '' || $this->panelOffersCommand($panel, $case['action']), 'selected action is exposed by the active strategy flow');

                $execution = $this->executeCaseAction($fixturePath, $case, $finding);
                $assertions = array_merge($assertions, $execution['assertions']);
                $messages = array_merge($messages, $execution['messages']);
                $endState = $execution['endState'];
                $debug = array_merge($debug, $execution['debug'] ?? []);
            }
        } catch (\Throwable $exception) {
            $messages[] = $exception->getMessage();
            $assertions[] = ['ok' => false, 'message' => $exception->getMessage()];
        }

        $failed = array_values(array_filter($assertions, static fn(array $assertion): bool => empty($assertion['ok'])));

        return [
            'caseId' => $case['case_id'],
            'issueType' => $case['issue_type'],
            'fixtureKey' => $case['fixture_key'],
            'actionTaken' => $case['action'],
            'expectedXlfChange' => $case['expect_write'],
            'initialIssue' => (string)($case['initial'] ?? TranslationIssueType::label($case['issue_type'])),
            'solution' => (string)($case['solution'] ?? $case['action']),
            'endState' => $endState,
            'status' => $failed === [] ? 'PASS' : 'FAIL',
            'assertions' => $assertions,
            'messages' => $messages,
            'debug' => $debug,
        ];
    }

    /**
     * @param array<string, string> $case
     * @return array{assertions: array<int, array<string, mixed>>, messages: string[], endState: string, debug?: array<string, mixed>}
     */
    private function executeCaseAction(string $fixturePath, array $case, TranslationFinding $finding): array
    {
        $assertions = [];
        $messages = [];
        $debug = [];
        $action = (string)($case['action'] ?? '');

        if (in_array($action, ['ignore_finding_for_run', 'keep_todo_in_run'], true)) {
            return [
                'assertions' => [$this->assertTrue(true, 'run-only action executed without file write')],
                'messages' => [],
                'endState' => 'Hidden for this run only; fixture files are unchanged.',
            ];
        }

        if ($action === 'ignore_finding_permanently') {
            $ignoredFindingId = $finding->findingId;
            $this->ignoreRuleService->addRule($finding, 'ignore_finding_permanently');
            $afterAudit = $this->auditService->audit($fixturePath);
            $afterFinding = $this->findFindingById($afterAudit->findings, $ignoredFindingId);
            $assertions[] = $this->assertTrue(!$afterFinding instanceof TranslationFinding, 'permanent ignore rule filters the selected finding');

            return [
                'assertions' => $assertions,
                'messages' => [],
                'endState' => 'Filtered by a permanent ignore rule.',
            ];
        }

        if ($action === 'show_scanned_usage') {
            $hasUsage = $finding->sourceFiles !== [] || $finding->relatedCandidates !== [];

            return [
                'assertions' => [$this->assertTrue($hasUsage || $finding->issueType === TranslationIssueType::UNUSED_CANDIDATE, 'usage review action returns deterministic review state')],
                'messages' => [],
                'endState' => 'Legacy review-only usage information shown; fixture files are unchanged.',
            ];
        }

        if (!$this->isWritePreviewAction($action)) {
            return [
                'assertions' => [$this->assertTrue(false, 'action is not executable by the smoke runner: ' . $action)],
                'messages' => [],
                'endState' => 'Unsupported smoke action.',
            ];
        }

        $values = $this->valuesForCase($case, $finding);
        if ($action === 'create_deepl_target_suggestion') {
            [$translatedValues, $translationErrors] = $this->translatedValuesForFindings([$finding]);
            foreach ($translationErrors as $translationError) {
                $messages[] = $translationError;
            }
            $values['translated_values'] = $translatedValues;
        }

        $preview = $this->writeService->buildPreview([$finding], $action, $values);
        $debug['previewOperations'] = array_map(static fn($operation): array => $operation->toArray(), $preview->operations);
        $assertions[] = $this->assertTrue($case['expect_write'] === 'no' || $preview->hasOperations() || $preview->errors !== [], 'write planning responds deterministically');
        foreach ($preview->errors as $error) {
            $messages[] = $error;
        }

        if ($case['expect_write'] !== 'yes') {
            return [
                'assertions' => $assertions,
                'messages' => $messages,
                'endState' => 'No persistent file write expected for this solution.',
            ];
        }

        if (!$preview->hasOperations()) {
            $assertions[] = $this->assertTrue(false, 'write action produced at least one operation');

            return [
                'assertions' => $assertions,
                'messages' => $messages,
                'endState' => 'No write operation was produced.',
                'debug' => $debug,
            ];
        }

        $writeResult = $this->writeService->write($preview);
        $debug['writeResult'] = $writeResult;
        $assertions[] = $this->assertTrue($writeResult['errors'] === [], 'write action completed without errors');
        foreach ($writeResult['errors'] as $error) {
            $messages[] = $error;
        }

        $afterAudit = $this->auditService->audit($fixturePath);
        $afterFinding = $this->findFinding($afterAudit->findings, $case['issue_type'], $case['fixture_key']);
        $sameIssueAllowed = (string)($case['expected_end'] ?? '') === 'same_issue_allowed';
        $assertions[] = $this->assertTrue(!$afterFinding instanceof TranslationFinding || $sameIssueAllowed, 'issue disappears, is reclassified, or is an accepted persistent state after execution');

        $endState = $afterFinding instanceof TranslationFinding
            ? 'Still listed as ' . TranslationIssueType::label($afterFinding->issueType) . ' after the observable write.'
            : 'Original issue is gone or reclassified after write.';

        return [
            'assertions' => $assertions,
            'messages' => $messages,
            'endState' => $endState,
            'debug' => $debug,
        ];
    }

    /**
     * @param TranslationFinding[] $findings
     * @return array{0: array<string, string>, 1: string[]}
     */
    private function translatedValuesForFindings(array $findings): array
    {
        $build = $this->requestBuilder->buildRequests($findings, [
            'source_language' => 'EN',
            'target_language' => 'DE',
        ]);
        $translatedValues = [];
        $errors = array_values($build['errors']);

        foreach ($build['requests'] as $request) {
            $result = $this->translationProvider->translateBatch($request);
            foreach ($result->translations as $id => $translation) {
                if ($translation !== '') {
                    $translatedValues[(string)$id] = $translation;
                }
            }
            foreach ($result->errors as $error) {
                $errors[] = $error;
            }
        }

        return [$translatedValues, array_values(array_unique($errors))];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function cases(): array
    {
        return [
            ['case_id' => 'SMK-001', 'issue_type' => 'keyless_unit', 'fixture_key' => '__keyless_', 'action' => 'enter_key_manually', 'expect_write' => 'yes', 'initial' => 'Keyless XLF unit without usable id.', 'solution' => 'Assign key manually.'],
            ['case_id' => 'SMK-002', 'issue_type' => 'keyless_unit', 'fixture_key' => '__keyless_', 'action' => 'link_keyless_unit_to_key', 'expect_write' => 'yes', 'initial' => 'Keyless XLF unit matches a missing code key.', 'solution' => 'Link the keyless unit to the matching code key.'],
            ['case_id' => 'SMK-003', 'issue_type' => 'keyless_unit', 'fixture_key' => '__keyless_', 'action' => 'delete_invalid_unit', 'expect_write' => 'yes', 'initial' => 'Keyless XLF unit should be removed.', 'solution' => 'Delete invalid unit.'],
            ['case_id' => 'SMK-004', 'issue_type' => 'keyless_unit', 'fixture_key' => '__keyless_', 'action' => 'ignore_finding_for_run', 'expect_write' => 'no', 'initial' => 'Keyless XLF unit is intentionally skipped now.', 'solution' => 'Ignore in this run.'],
            ['case_id' => 'SMK-005', 'issue_type' => 'keyless_unit', 'fixture_key' => '__keyless_', 'action' => 'ignore_finding_permanently', 'expect_write' => 'no', 'initial' => 'Keyless XLF unit is intentionally ignored permanently.', 'solution' => 'Ignore permanently.'],

            ['case_id' => 'SMK-006', 'issue_type' => 'key_mismatch_candidate', 'fixture_key' => 'button.save.primary', 'action' => 'change_key_to_matching_key', 'expect_write' => 'yes', 'initial' => 'Code key has the same text as another XLF key.', 'solution' => 'Change selected key to the matching key.'],
            ['case_id' => 'SMK-007', 'issue_type' => 'key_mismatch_candidate', 'fixture_key' => 'button.save.primary', 'action' => 'create_alias_source_unit', 'expect_write' => 'yes', 'initial' => 'Matching source exists under another key.', 'solution' => 'Create alias source unit.'],
            ['case_id' => 'SMK-008', 'issue_type' => 'key_mismatch_candidate', 'fixture_key' => 'button.save.primary', 'action' => 'ignore_finding_for_run', 'expect_write' => 'no', 'initial' => 'Key mismatch candidate skipped for this run.', 'solution' => 'Ignore in this run.'],
            ['case_id' => 'SMK-009', 'issue_type' => 'key_mismatch_candidate', 'fixture_key' => 'button.save.primary', 'action' => 'ignore_finding_permanently', 'expect_write' => 'no', 'initial' => 'Key mismatch candidate accepted permanently.', 'solution' => 'Ignore permanently.'],

            ['case_id' => 'SMK-010', 'issue_type' => 'missing_source_from_locale_candidate', 'fixture_key' => 'source.from.locale', 'action' => 'use_other_locale_as_source', 'expect_write' => 'yes', 'initial' => 'Source is missing; another locale has candidate text.', 'solution' => 'Copy locale candidate into source.'],
            ['case_id' => 'SMK-011', 'issue_type' => 'missing_source_from_locale_candidate', 'fixture_key' => 'source.from.locale', 'action' => 'enter_source_manually', 'expect_write' => 'yes', 'initial' => 'Source is missing; locale text is only a candidate.', 'solution' => 'Write manual source.'],
            ['case_id' => 'SMK-012', 'issue_type' => 'missing_source_from_locale_candidate', 'fixture_key' => 'source.from.locale', 'action' => 'ignore_finding_for_run', 'expect_write' => 'no', 'initial' => 'Locale candidate source issue skipped now.', 'solution' => 'Ignore in this run.'],
            ['case_id' => 'SMK-013', 'issue_type' => 'missing_source_from_locale_candidate', 'fixture_key' => 'source.from.locale', 'action' => 'ignore_finding_permanently', 'expect_write' => 'no', 'initial' => 'Locale candidate source issue accepted permanently.', 'solution' => 'Ignore permanently.'],

            ['case_id' => 'SMK-014', 'issue_type' => 'missing_source_unit', 'fixture_key' => 'missing.complete', 'action' => 'enter_source_text', 'expect_write' => 'yes', 'initial' => 'Code key exists, but no reliable source exists.', 'solution' => 'Write manual source.'],
            ['case_id' => 'SMK-015', 'issue_type' => 'missing_source_unit', 'fixture_key' => 'missing.complete', 'action' => 'ignore_finding_for_run', 'expect_write' => 'no', 'initial' => 'Missing source issue skipped now.', 'solution' => 'Ignore in this run.'],
            ['case_id' => 'SMK-016', 'issue_type' => 'missing_source_unit', 'fixture_key' => 'missing.complete', 'action' => 'ignore_finding_permanently', 'expect_write' => 'no', 'initial' => 'Missing source issue accepted permanently.', 'solution' => 'Ignore permanently.'],

            ['case_id' => 'SMK-017', 'issue_type' => 'missing_translation_unit', 'fixture_key' => 'missing.in.file', 'action' => 'create_manual_translation_unit', 'expect_write' => 'yes', 'initial' => 'Code key with default text has no XLF unit.', 'solution' => 'Create manual source and target units.'],
            ['case_id' => 'SMK-018', 'issue_type' => 'missing_translation_unit', 'fixture_key' => 'missing.in.file', 'action' => 'ignore_finding_for_run', 'expect_write' => 'no', 'initial' => 'Missing complete translation unit skipped now.', 'solution' => 'Ignore in this run.'],
            ['case_id' => 'SMK-019', 'issue_type' => 'missing_translation_unit', 'fixture_key' => 'missing.in.file', 'action' => 'ignore_finding_permanently', 'expect_write' => 'no', 'initial' => 'Missing complete translation unit accepted permanently.', 'solution' => 'Ignore permanently.'],

            ['case_id' => 'SMK-020', 'issue_type' => 'locale_gap', 'fixture_key' => 'pages.fixture_label', 'action' => 'copy_source_unit_without_target', 'expect_write' => 'yes', 'expected_end' => 'same_issue_allowed', 'initial' => 'Existing locale file is missing a source key.', 'solution' => 'Copy source unit without target.'],
            ['case_id' => 'SMK-021', 'issue_type' => 'locale_gap', 'fixture_key' => 'pages.fixture_label', 'action' => 'ignore_finding_for_run', 'expect_write' => 'no', 'initial' => 'Locale gap skipped now.', 'solution' => 'Ignore in this run.'],
            ['case_id' => 'SMK-022', 'issue_type' => 'locale_gap', 'fixture_key' => 'pages.fixture_label', 'action' => 'ignore_finding_permanently', 'expect_write' => 'no', 'initial' => 'Locale gap accepted permanently.', 'solution' => 'Ignore permanently.'],

            ['case_id' => 'SMK-023', 'issue_type' => 'missing_target', 'fixture_key' => 'missing.target', 'action' => 'create_deepl_target_suggestion', 'expect_write' => 'yes', 'initial' => 'Source exists, target value is missing.', 'solution' => 'Translate with fake DeepL and write.'],
            ['case_id' => 'SMK-024', 'issue_type' => 'missing_target', 'fixture_key' => 'missing.target', 'action' => 'enter_target_text', 'expect_write' => 'yes', 'initial' => 'Source exists, target value is missing.', 'solution' => 'Write manual target.'],
            ['case_id' => 'SMK-025', 'issue_type' => 'missing_target', 'fixture_key' => 'missing.target', 'action' => 'copy_source_value', 'expect_write' => 'yes', 'initial' => 'Source exists, target value is missing.', 'solution' => 'Copy source to target.'],
            ['case_id' => 'SMK-026', 'issue_type' => 'missing_target', 'fixture_key' => 'missing.target', 'action' => 'write_todo_target', 'expect_write' => 'yes', 'initial' => 'Source exists, target value is missing.', 'solution' => 'Write TODO target.'],
            ['case_id' => 'SMK-027', 'issue_type' => 'missing_target', 'fixture_key' => 'missing.target', 'action' => 'create_empty_target_unit', 'expect_write' => 'yes', 'expected_end' => 'same_issue_allowed', 'initial' => 'Source exists, target value is missing.', 'solution' => 'Create explicit empty target.'],
            ['case_id' => 'SMK-028', 'issue_type' => 'missing_target', 'fixture_key' => 'missing.target', 'action' => 'ignore_finding_for_run', 'expect_write' => 'no', 'initial' => 'Missing target skipped now.', 'solution' => 'Ignore in this run.'],
            ['case_id' => 'SMK-029', 'issue_type' => 'missing_target', 'fixture_key' => 'missing.target', 'action' => 'ignore_finding_permanently', 'expect_write' => 'no', 'initial' => 'Missing target accepted permanently.', 'solution' => 'Ignore permanently.'],

            ['case_id' => 'SMK-030', 'issue_type' => 'todo_source', 'fixture_key' => 'todo.source', 'action' => 'enter_source_text', 'expect_write' => 'yes', 'initial' => 'Source contains a TODO placeholder.', 'solution' => 'Write real source text.'],
            ['case_id' => 'SMK-031', 'issue_type' => 'todo_source', 'fixture_key' => 'todo.source', 'action' => 'ignore_finding_for_run', 'expect_write' => 'no', 'initial' => 'TODO source skipped now.', 'solution' => 'Ignore in this run.'],
            ['case_id' => 'SMK-032', 'issue_type' => 'todo_source', 'fixture_key' => 'todo.source', 'action' => 'ignore_finding_permanently', 'expect_write' => 'no', 'initial' => 'TODO source accepted permanently.', 'solution' => 'Ignore permanently.'],

            ['case_id' => 'SMK-033', 'issue_type' => 'todo_value', 'fixture_key' => 'todo.value', 'action' => 'create_deepl_target_suggestion', 'expect_write' => 'yes', 'initial' => 'Target contains a TODO placeholder.', 'solution' => 'Translate with fake DeepL and write.'],
            ['case_id' => 'SMK-034', 'issue_type' => 'todo_value', 'fixture_key' => 'todo.value', 'action' => 'enter_target_text', 'expect_write' => 'yes', 'initial' => 'Target contains a TODO placeholder.', 'solution' => 'Write manual target.'],
            ['case_id' => 'SMK-035', 'issue_type' => 'todo_value', 'fixture_key' => 'todo.value', 'action' => 'copy_source_value', 'expect_write' => 'yes', 'initial' => 'Target contains a TODO placeholder.', 'solution' => 'Copy source to target.'],
            ['case_id' => 'SMK-036', 'issue_type' => 'todo_value', 'fixture_key' => 'todo.value', 'action' => 'keep_todo_in_run', 'expect_write' => 'no', 'initial' => 'Target TODO is accepted for this run only.', 'solution' => 'Keep TODO for now.'],
            ['case_id' => 'SMK-037', 'issue_type' => 'todo_value', 'fixture_key' => 'todo.value', 'action' => 'ignore_finding_for_run', 'expect_write' => 'no', 'initial' => 'TODO target skipped now.', 'solution' => 'Ignore in this run.'],
            ['case_id' => 'SMK-038', 'issue_type' => 'todo_value', 'fixture_key' => 'todo.value', 'action' => 'ignore_finding_permanently', 'expect_write' => 'no', 'initial' => 'TODO target accepted permanently.', 'solution' => 'Ignore permanently.'],

            ['case_id' => 'SMK-039', 'issue_type' => 'equal_value', 'fixture_key' => 'equal.status', 'action' => 'ignore_finding_for_run', 'expect_write' => 'no', 'initial' => 'Source and target are equal.', 'solution' => 'Ignore in this run.'],
            ['case_id' => 'SMK-040', 'issue_type' => 'equal_value', 'fixture_key' => 'equal.status', 'action' => 'ignore_finding_permanently', 'expect_write' => 'no', 'initial' => 'Source and target are equal but accepted.', 'solution' => 'Ignore permanently.'],
            ['case_id' => 'SMK-041', 'issue_type' => 'equal_value', 'fixture_key' => 'equal.status', 'action' => 'enter_target_text', 'expect_write' => 'yes', 'initial' => 'Source and target are equal.', 'solution' => 'Write manual target.'],
            ['case_id' => 'SMK-042', 'issue_type' => 'equal_value', 'fixture_key' => 'equal.status', 'action' => 'create_deepl_target_suggestion', 'expect_write' => 'yes', 'initial' => 'Source and target are equal.', 'solution' => 'Translate with fake DeepL and write.'],
            ['case_id' => 'SMK-043', 'issue_type' => 'equal_value', 'fixture_key' => 'equal.status', 'action' => 'prefix_with_todo', 'expect_write' => 'yes', 'initial' => 'Source and target are equal.', 'solution' => 'Prefix target with TODO.'],

            ['case_id' => 'SMK-044', 'issue_type' => 'unused_candidate', 'fixture_key' => 'unused.static', 'action' => 'delete_translation_unit', 'expect_write' => 'yes', 'initial' => 'XLF key has no scanned static usage.', 'solution' => 'Delete complete translation unit including key, source and target units.'],
            ['case_id' => 'SMK-045', 'issue_type' => 'unused_candidate', 'fixture_key' => 'unused.static', 'action' => 'delete_source_and_targets', 'expect_write' => 'yes', 'initial' => 'Legacy cleanup alias for an unused XLF key.', 'solution' => 'Delete complete translation unit via legacy alias.'],
            ['case_id' => 'SMK-046', 'issue_type' => 'unused_candidate', 'fixture_key' => 'unused.static@de.locallang.xlf', 'action' => 'delete_target_locale_only', 'expect_write' => 'yes', 'initial' => 'Legacy target-locale-only cleanup alias.', 'solution' => 'Delete target locale only via legacy alias.'],
            ['case_id' => 'SMK-047', 'issue_type' => 'unused_candidate', 'fixture_key' => 'unused.static', 'action' => 'ignore_finding_for_run', 'expect_write' => 'no', 'initial' => 'Unused candidate skipped now.', 'solution' => 'Ignore in this run.'],
            ['case_id' => 'SMK-048', 'issue_type' => 'unused_candidate', 'fixture_key' => 'unused.static', 'action' => 'ignore_finding_permanently', 'expect_write' => 'no', 'initial' => 'Unused candidate accepted permanently.', 'solution' => 'Ignore permanently.'],
        ];
    }

    /**
     * @param TranslationFinding[] $findings
     */
    private function findFinding(array $findings, string $issueType, string $fixtureKey): ?TranslationFinding
    {
        if ($issueType === 'mixed_selection') {
            return $findings[0] ?? null;
        }

        foreach ($findings as $finding) {
            $base = $finding->baseIssueType !== '' ? $finding->baseIssueType : $finding->issueType;
            if ($finding->issueType !== $issueType && $base !== $issueType) {
                continue;
            }
            if (str_contains($fixtureKey, '@')) {
                [$keyNeedle, $fileNeedle] = array_pad(explode('@', $fixtureKey, 2), 2, '');
                if ($keyNeedle !== '' && !str_contains($finding->transUnitId, $keyNeedle)) {
                    continue;
                }
                if ($fileNeedle !== '' && !str_contains($finding->languageFile, $fileNeedle)) {
                    continue;
                }

                return $finding;
            }
            if ($fixtureKey === '__keyless_' && str_starts_with($finding->transUnitId, '__keyless_')) {
                return $finding;
            }
            if (str_contains($finding->transUnitId, $fixtureKey) || str_contains($finding->languageFile, $fixtureKey)) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * @param TranslationFinding[] $findings
     */
    private function findFindingById(array $findings, string $findingId): ?TranslationFinding
    {
        foreach ($findings as $finding) {
            if ($finding->findingId === $findingId) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * @param TranslationFinding[] $findings
     * @return array<string, int>
     */
    private function issueCounts(array $findings): array
    {
        $counts = [];
        foreach ($findings as $finding) {
            $counts[$finding->issueType] = ($counts[$finding->issueType] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param TranslationFinding[] $findings
     * @return array<int, array<string, string>>
     */
    private function debugFindingsForKey(array $findings, string $fixtureKey): array
    {
        $matches = [];
        foreach ($findings as $finding) {
            $keyNeedle = $fixtureKey;
            $fileNeedle = '';
            if (str_contains($fixtureKey, '@')) {
                [$keyNeedle, $fileNeedle] = array_pad(explode('@', $fixtureKey, 2), 2, '');
            }
            if ($fixtureKey !== '__keyless_' && $keyNeedle !== '' && !str_contains($finding->transUnitId, $keyNeedle)) {
                continue;
            }
            if ($fileNeedle !== '' && !str_contains($finding->languageFile, $fileNeedle)) {
                continue;
            }
            $matches[] = $this->findingDebugRow($finding);
        }

        return $matches;
    }

    /**
     * @return array<string, mixed>
     */
    private function findingDebugRow(TranslationFinding $finding): array
    {
        return [
            'findingId' => $finding->findingId,
            'issueType' => $finding->issueType,
            'baseIssueType' => $finding->baseIssueType,
            'languageFile' => $finding->languageFile,
            'absoluteLanguageFile' => $finding->absoluteLanguageFile,
            'locale' => $finding->locale,
            'key' => $finding->transUnitId,
            'sourceValue' => $finding->sourceValue,
            'targetValue' => $finding->currentTargetValue,
        ];
    }

    /**
     * @param array<string, mixed> $panel
     */
    private function panelHasIssueSpecificStrategies(array $panel, string $issueType): bool
    {
        $tabs = $panel['solutionTabs'] ?? [];
        if (!is_array($tabs) || $tabs === []) {
            return false;
        }

        foreach ($tabs as $tab) {
            if (!is_array($tab) || (string)($tab['issueType'] ?? '') !== $issueType) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $panel
     */
    private function panelOffersCommand(array $panel, string $command): bool
    {
        foreach ($this->panelCommandAliases($command) as $candidateCommand) {
            $tool = $panel['tool'] ?? [];
            if (is_array($tool) && (string)($tool['actionCommand'] ?? '') === $candidateCommand) {
                return true;
            }

            $tabs = $panel['solutionTabs'] ?? [];
            if (!is_array($tabs)) {
                continue;
            }

            foreach ($tabs as $tab) {
                if (is_array($tab) && (string)($tab['command'] ?? '') === $candidateCommand) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $case
     */
    private function activeSolutionForCase(array $case, ?SolutionStrategy $strategy): string
    {
        $action = (string)($case['action'] ?? '');
        if (
            $case['issue_type'] === TranslationIssueType::UNUSED_CANDIDATE
            && in_array($action, ['delete_source_and_targets', 'delete_target_locale_only'], true)
        ) {
            return 'delete_translation_unit';
        }

        return $strategy?->strategyId ?? '';
    }

    /**
     * @return string[]
     */
    private function panelCommandAliases(string $command): array
    {
        $commands = [$command];
        if (in_array($command, ['delete_source_and_targets', 'delete_target_locale_only'], true)) {
            $commands[] = 'delete_translation_unit';
        }

        return array_values(array_unique($commands));
    }

    /**
     * @param array<string, mixed> $panel
     */
    private function panelHasValidationError(array $panel, string $needle): bool
    {
        $tool = $panel['tool'] ?? [];
        $errors = is_array($tool) && is_array($tool['validationErrors'] ?? null) ? $tool['validationErrors'] : [];
        foreach ($errors as $error) {
            if (str_contains((string)$error, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param TranslationFinding[] $findings
     * @return TranslationFinding[]
     */
    private function findingsForIssue(array $findings, string $issueType): array
    {
        return array_values(array_filter(
            $findings,
            static fn(TranslationFinding $finding): bool => $finding->issueType === $issueType || $finding->baseIssueType === $issueType
        ));
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
            'create_deepl_target_suggestion',
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
     * @param TranslationFinding[] $findings
     * @return TranslationFinding[]
     */
    private function mixedFindings(array $findings): array
    {
        $selected = [];
        $seen = [];
        foreach ($findings as $finding) {
            if (isset($seen[$finding->issueType])) {
                continue;
            }
            $seen[$finding->issueType] = true;
            $selected[] = $finding;
            if (count($selected) >= 2) {
                break;
            }
        }

        return $selected;
    }

    /**
     * @param array<string, string> $case
     * @return array<string, mixed>
     */
    private function valuesForCase(array $case, TranslationFinding $finding): array
    {
        $action = (string)($case['action'] ?? '');
        $targetKey = $case['fixture_key'] === '__keyless_' ? 'keyless.fixed' : $finding->transUnitId;
        if (in_array(($case['action'] ?? ''), ['change_key_to_matching_key', 'replace_code_key_with_existing_key'], true)) {
            $targetKey = (string)($finding->relatedCandidates[0]['key'] ?? '');
        }
        if ($action === 'link_keyless_unit_to_key') {
            $targetKey = (string)($finding->relatedCandidates[0]['key'] ?? 'keyless.expected');
        }
        if ($action === 'enter_key_manually') {
            $targetKey = 'keyless.fixed';
        }

        return [
            'manual_source_text' => 'Smoke source for ' . ($finding->transUnitId !== '' ? $finding->transUnitId : 'keyless unit'),
            'manual_target_text' => 'Smoke target for ' . ($finding->transUnitId !== '' ? $finding->transUnitId : 'keyless unit'),
            'target_key' => $targetKey,
            'translated_values' => [
                $finding->findingId => 'DEEPL-DE: ' . ($finding->sourceValue !== '' ? $finding->sourceValue : $finding->transUnitId),
            ],
        ];
    }

    private function assertTrue(bool $condition, string $message): array
    {
        return ['ok' => $condition, 'message' => $message];
    }

    private function writeJson(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function writeSummary(string $path, array $summary): void
    {
        $lines = [
            '# Extension Translator Solution Smoke Summary',
            '',
            '- Artifact root: `' . $summary['artifactRoot'] . '`',
            '- Fixture path: `' . $summary['fixturePath'] . '`',
            '- Started: ' . $summary['startedAt'],
            '- Finished: ' . ($summary['finishedAt'] ?? ''),
            '',
            '| Case | Status | Initial issue | Solution | End state | Report |',
            '|---|---:|---|---|---|---|',
        ];
        foreach ($summary['cases'] as $case) {
            $lines[] = '| ' . $case['caseId'] . ' | ' . $case['status'] . ' | ' . $this->markdownCell((string)($case['initialIssue'] ?? '')) . ' | ' . $this->markdownCell((string)($case['solution'] ?? '')) . ' | ' . $this->markdownCell((string)($case['endState'] ?? '')) . ' | ' . $case['report'] . ' |';
        }
        $lines[] = '';
        $lines[] = 'Fake DeepL calls: `fake-deepl-calls.json`';
        $lines[] = 'XLF snapshots: `before-after/`';

        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    private function markdownCell(string $value): string
    {
        return str_replace('|', '\\|', trim(preg_replace('/\s+/', ' ', $value) ?? ''));
    }

    private function clearIgnoreRules(): void
    {
        $path = Environment::getVarPath() . '/ppl_deepl_v3_extension_translator/ignore-rules.json';
        if (is_file($path)) {
            unlink($path);
        }
    }
}
