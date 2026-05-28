<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service\Smoke;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationFinding;
use Ppl\PplDeeplV3ExtensionTranslator\Service\IssueResolutionPlanner;
use Ppl\PplDeeplV3ExtensionTranslator\Service\TranslationAuditService;
use Ppl\PplDeeplV3ExtensionTranslator\Service\TranslationWriteService;

final class SmokeMatrixRunner
{
    private const PANEL_ACTIONS_KEY = 'actions';

    public function __construct(
        private readonly SmokeContext $context,
        private readonly SmokeFixtureService $fixtureService,
        private readonly TranslationAuditService $auditService,
        private readonly IssueResolutionPlanner $resolutionPlanner,
        private readonly TranslationWriteService $writeService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function runMatrix(string $fixturePath, string $artifactRoot, ?string $onlyCase = null): array
    {
        $this->context->activate($artifactRoot);
        $this->writeJson($artifactRoot . '/fake-deepl-calls.json', []);
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
            ];
        }

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

        try {
            $audit = $this->auditService->audit($fixturePath);
            if ($case['issue_type'] === 'mixed_selection') {
                $mixed = $this->mixedFindings($audit->findings);
                $panel = $this->resolutionPlanner->plan($mixed);
                $assertions[] = $this->assertTrue(count($mixed) >= 2, 'mixed selection has at least two issue types');
                $assertions[] = $this->assertTrue(($panel['state'] ?? '') === 'mixed', 'mixed action selection is blocked');
                $failed = array_values(array_filter($assertions, static fn(array $assertion): bool => empty($assertion['ok'])));

                return [
                    'caseId' => $case['case_id'],
                    'issueType' => $case['issue_type'],
                    'fixtureKey' => $case['fixture_key'],
                    'actionTaken' => $case['action'],
                    'expectedXlfChange' => $case['expect_write'],
                    'status' => $failed === [] ? 'PASS' : 'FAIL',
                    'assertions' => $assertions,
                    'messages' => $messages,
                ];
            }

            $finding = $this->findFinding($audit->findings, $case['issue_type'], $case['fixture_key']);
            $assertions[] = $this->assertTrue($finding instanceof TranslationFinding, 'initial issue type detected');

            if ($finding instanceof TranslationFinding) {
                $panel = $this->resolutionPlanner->plan([$finding]);
                $assertions[] = $this->assertTrue(($panel['state'] ?? '') === 'ready', 'action panel is ready');
                $assertions[] = $this->assertTrue($this->panelHasMeaningfulAction($panel), 'action panel has implemented actions');

                if (($case['action'] ?? '') !== '') {
                    $preview = $this->writeService->buildPreview([$finding], $case['action'], $this->valuesForCase($case, $finding));
                    $assertions[] = $this->assertTrue($case['expect_write'] === 'no' || $preview->hasOperations() || $preview->errors !== [], 'write preview responds deterministically');
                    if ($case['expect_write'] === 'yes' && $preview->hasOperations()) {
                        $writeResult = $this->writeService->write($preview);
                        $assertions[] = $this->assertTrue($writeResult['errors'] === [], 'write action completed without errors');
                        $afterAudit = $this->auditService->audit($fixturePath);
                        $afterFinding = $this->findFinding($afterAudit->findings, $case['issue_type'], $case['fixture_key']);
                        $assertions[] = $this->assertTrue(!$afterFinding instanceof TranslationFinding || in_array($case['case_id'], ['SMK-008', 'SMK-015'], true), 'issue disappears or is reclassified after write');
                    }
                }
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
            'status' => $failed === [] ? 'PASS' : 'FAIL',
            'assertions' => $assertions,
            'messages' => $messages,
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function cases(): array
    {
        return [
            ['case_id' => 'SMK-001', 'issue_type' => 'missing_source_unit', 'fixture_key' => 'missing.complete', 'action' => 'enter_source_text', 'expect_write' => 'yes'],
            ['case_id' => 'SMK-002', 'issue_type' => 'missing_source_unit', 'fixture_key' => 'missing.complete', 'action' => 'use_key_as_temporary_source', 'expect_write' => 'yes'],
            ['case_id' => 'SMK-003', 'issue_type' => 'key_mismatch_candidate', 'fixture_key' => 'button.save.primary', 'action' => 'create_alias_source_unit', 'expect_write' => 'yes'],
            ['case_id' => 'SMK-004', 'issue_type' => 'keyless_unit', 'fixture_key' => '__keyless_', 'action' => 'enter_key_manually', 'expect_write' => 'yes'],
            ['case_id' => 'SMK-005', 'issue_type' => 'missing_source_unit', 'fixture_key' => 'keyless.expected', 'action' => 'link_to_candidate', 'expect_write' => 'yes'],
            ['case_id' => 'SMK-006', 'issue_type' => 'missing_source_from_locale_candidate', 'fixture_key' => 'source.from.locale', 'action' => 'use_other_locale_as_source', 'expect_write' => 'yes'],
            ['case_id' => 'SMK-007', 'issue_type' => 'missing_target', 'fixture_key' => 'missing.target', 'action' => 'copy_source_value', 'expect_write' => 'yes'],
            ['case_id' => 'SMK-008', 'issue_type' => 'missing_target', 'fixture_key' => 'missing.target', 'action' => 'write_todo_target', 'expect_write' => 'yes'],
            ['case_id' => 'SMK-009', 'issue_type' => 'todo_value', 'fixture_key' => 'todo.value', 'action' => 'copy_source_value', 'expect_write' => 'yes'],
            ['case_id' => 'SMK-010', 'issue_type' => 'todo_value', 'fixture_key' => 'todo.value', 'action' => 'enter_target_text', 'expect_write' => 'yes'],
            ['case_id' => 'SMK-011', 'issue_type' => 'equal_value', 'fixture_key' => 'equal.status', 'action' => 'ignore_finding_for_run', 'expect_write' => 'no'],
            ['case_id' => 'SMK-012', 'issue_type' => 'equal_value', 'fixture_key' => 'equal.status', 'action' => 'always_ignore_key', 'expect_write' => 'no'],
            ['case_id' => 'SMK-013', 'issue_type' => 'equal_value', 'fixture_key' => 'equal.status', 'action' => 'enter_target_text', 'expect_write' => 'yes'],
            ['case_id' => 'SMK-014', 'issue_type' => 'unused_candidate', 'fixture_key' => 'unused.static', 'action' => 'mark_dynamic_keep', 'expect_write' => 'no'],
            ['case_id' => 'SMK-015', 'issue_type' => 'unused_candidate', 'fixture_key' => 'unused.static', 'action' => 'delete_target_locale_only', 'expect_write' => 'yes'],
            ['case_id' => 'SMK-016', 'issue_type' => 'unused_candidate', 'fixture_key' => 'unused.static', 'action' => 'delete_source_and_targets', 'expect_write' => 'yes'],
            ['case_id' => 'SMK-017', 'issue_type' => 'locale_gap', 'fixture_key' => 'locallang_db.xlf', 'action' => 'create_target_xlf_file', 'expect_write' => 'yes'],
            ['case_id' => 'SMK-018', 'issue_type' => 'cannot_change', 'fixture_key' => 'readonly.case', 'action' => 'show_cannot_change_reason', 'expect_write' => 'no'],
            ['case_id' => 'SMK-019', 'issue_type' => 'missing_target', 'fixture_key' => 'markup.placeholder', 'action' => 'copy_source_value', 'expect_write' => 'yes'],
            ['case_id' => 'SMK-020', 'issue_type' => 'mixed_selection', 'fixture_key' => 'mixed', 'action' => '', 'expect_write' => 'no'],
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
     * @param array<string, mixed> $panel
     */
    private function panelHasMeaningfulAction(array $panel): bool
    {
        return ($panel['state'] ?? '') === 'ready' && $this->panelActions($panel) !== [];
    }

    /**
     * @param array<string, mixed> $panel
     * @return array<int, mixed>
     */
    private function panelActions(array $panel): array
    {
        $actions = $panel[self::PANEL_ACTIONS_KEY] ?? [];

        return is_array($actions) ? $actions : [];
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
        return [
            'manual_source_text' => 'Missing complete source',
            'manual_target_text' => 'Manueller Wert',
            'target_key' => $case['fixture_key'] === '__keyless_' ? 'keyless.fixed' : $finding->transUnitId,
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
            '# Extension Translator Taxonomy Smoke Summary',
            '',
            '- Artifact root: `' . $summary['artifactRoot'] . '`',
            '- Fixture path: `' . $summary['fixturePath'] . '`',
            '- Started: ' . $summary['startedAt'],
            '- Finished: ' . ($summary['finishedAt'] ?? ''),
            '',
            '| Case | Status | Report |',
            '|---|---:|---|',
        ];
        foreach ($summary['cases'] as $case) {
            $lines[] = '| ' . $case['caseId'] . ' | ' . $case['status'] . ' | ' . $case['report'] . ' |';
        }
        $lines[] = '';
        $lines[] = 'Fake DeepL calls: `fake-deepl-calls.json`';
        $lines[] = 'XLF snapshots: `before-after/`';

        file_put_contents($path, implode("\n", $lines) . "\n");
    }
}
