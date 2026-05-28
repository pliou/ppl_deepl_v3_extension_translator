<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\ScanScope;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationAuditReport;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationFinding;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\XlfTranslationFile;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\XlfTransUnit;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue\ActionState;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue\SourceStatus;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue\TranslationIssueType;

final class TranslationAuditService
{
    public function __construct(
        private readonly ExtensionPathResolver $pathResolver,
        private readonly XlfLanguageFileReader $xlfReader,
        private readonly TranslationKeyScanner $keyScanner,
        private readonly IgnoreRuleService $ignoreRuleService
    ) {}

    /**
     * @param string[] $selectedLanguageFiles
     */
    public function audit(string $inputPath, array $selectedLanguageFiles = []): TranslationAuditReport
    {
        $scope = $this->pathResolver->resolve($inputPath);
        $allLanguageFiles = $this->xlfReader->findLanguageFiles($scope);
        $languageFiles = $this->selectedLanguageFiles($allLanguageFiles, $selectedLanguageFiles);
        $codeKeys = $this->keyScanner->scan($scope);
        $groups = $this->groupLanguageFiles($languageFiles);
        $sourceIndex = $this->buildSourceTextIndex($languageFiles);
        $expectedTargetLocales = $this->expectedTargetLocales($groups);
        $findings = [];

        $this->addKeylessUnitFindings($findings, $scope, $languageFiles, $codeKeys);
        $this->addMissingSourceFindings($findings, $scope, $languageFiles, $groups, $codeKeys, $sourceIndex);
        $this->addLocaleGapFindings($findings, $scope, $groups, $expectedTargetLocales);
        $this->addMissingTargetFindings($findings, $scope, $groups);
        $this->addSuspiciousValueFindings($findings, $scope, $groups);
        $this->addUnusedCandidateFindings($findings, $scope, $languageFiles, $groups, $codeKeys);

        $findings = $this->ignoreRuleService->filterIgnored($findings);
        $findings = $this->overlayCannotChange($findings, $scope);

        usort($findings, static function (TranslationFinding $left, TranslationFinding $right): int {
            $order = array_flip(TranslationIssueType::all());
            return [
                $order[$left->issueType] ?? 99,
                $left->languageFile,
                $left->transUnitId,
            ] <=> [
                $order[$right->issueType] ?? 99,
                $right->languageFile,
                $right->transUnitId,
            ];
        });

        return new TranslationAuditReport(
            $scope,
            $allLanguageFiles,
            $findings,
            $this->buildSummary($scope, $languageFiles, $codeKeys, $findings),
            $selectedLanguageFiles
        );
    }

    /**
     * @param XlfTranslationFile[] $languageFiles
     * @param string[] $selectedLanguageFiles
     * @return XlfTranslationFile[]
     */
    private function selectedLanguageFiles(array $languageFiles, array $selectedLanguageFiles): array
    {
        if ($selectedLanguageFiles === []) {
            return $languageFiles;
        }
        if (in_array('__none__', $selectedLanguageFiles, true)) {
            return [];
        }

        $lookup = array_fill_keys($selectedLanguageFiles, true);

        return array_values(array_filter(
            $languageFiles,
            static fn(XlfTranslationFile $file): bool => isset($lookup[$file->relativePath])
        ));
    }

    /**
     * @param XlfTranslationFile[] $languageFiles
     * @return array<string, array{source: ?XlfTranslationFile, targets: XlfTranslationFile[]}>
     */
    private function groupLanguageFiles(array $languageFiles): array
    {
        $groups = [];

        foreach ($languageFiles as $file) {
            $groupKey = $this->languageFileGroupKey($file);
            $groups[$groupKey] ??= [
                'source' => null,
                'targets' => [],
            ];

            if ($file->canonical && $groups[$groupKey]['source'] === null) {
                $groups[$groupKey]['source'] = $file;
                continue;
            }

            $groups[$groupKey]['targets'][] = $file;
        }

        foreach ($groups as $groupKey => $group) {
            if ($group['source'] instanceof XlfTranslationFile) {
                continue;
            }

            $firstTarget = array_shift($groups[$groupKey]['targets']);
            $groups[$groupKey]['source'] = $firstTarget instanceof XlfTranslationFile ? $firstTarget : null;
        }

        return $groups;
    }

    /**
     * @param TranslationFinding[] $findings
     * @param XlfTranslationFile[] $languageFiles
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $codeKeys
     */
    private function addKeylessUnitFindings(array &$findings, ScanScope $scope, array $languageFiles, array $codeKeys): void
    {
        foreach ($languageFiles as $file) {
            foreach ($file->keylessUnits as $unit) {
                $candidateText = $unit->source !== '' ? $unit->source : $unit->targetValue;
                $related = $this->relatedCodeKeysByDefaultValue($codeKeys, $candidateText);

                $findings[] = $this->createFinding(
                    TranslationIssueType::KEYLESS_UNIT,
                    $scope,
                    $file,
                    $file->locale,
                    $unit->transUnitId,
                    $unit->source,
                    $unit->targetValue,
                    '',
                    [],
                    $scope->writeAllowed,
                    $unit->source !== '' ? SourceStatus::SOURCE_KNOWN_FROM_KEYLESS_UNIT : SourceStatus::MANUAL_SOURCE_REQUIRED,
                    'keyless_unit',
                    [],
                    $related,
                    ['enter_key_manually', 'link_keyless_unit_to_key', 'delete_invalid_unit_with_backup', 'ignore_finding_for_run'],
                    ActionState::NEEDS_SOURCE,
                    [
                        'keylessSequence' => $unit->sequence,
                        'rawId' => $unit->rawId,
                        'hasSource' => $unit->hasSource,
                        'hasTarget' => $unit->hasTarget,
                    ]
                );
            }
        }
    }

    /**
     * @param TranslationFinding[] $findings
     * @param XlfTranslationFile[] $languageFiles
     * @param array<string, array{source: ?XlfTranslationFile, targets: XlfTranslationFile[]}> $groups
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $codeKeys
     * @param array<string, array<int, array{key: string, file: XlfTranslationFile, unit: XlfTransUnit}>> $sourceIndex
     */
    private function addMissingSourceFindings(array &$findings, ScanScope $scope, array $languageFiles, array $groups, array $codeKeys, array $sourceIndex): void
    {
        foreach ($codeKeys as $key => $data) {
            $candidateFiles = $this->sourceFilesForCodeKey($languageFiles, $groups, $data['languageFiles'], $data['sourceFiles']);
            foreach ($candidateFiles as $file) {
                if ($file->hasUnit($key)) {
                    continue;
                }

                $defaultValue = trim((string)($data['defaultValue'] ?? ''));
                $keylessCandidates = $defaultValue !== '' ? $this->keylessCandidatesByText($languageFiles, $defaultValue) : [];
                $sourceCandidates = $defaultValue !== '' ? $this->sourceCandidatesByText($sourceIndex, $defaultValue, $key) : [];
                if ($sourceCandidates !== []) {
                    $first = $sourceCandidates[0];
                    $findings[] = $this->createFinding(
                        TranslationIssueType::KEY_MISMATCH_CANDIDATE,
                        $scope,
                        $file,
                        $file->locale,
                        $key,
                        $first['unit']->source,
                        '',
                        $first['unit']->source,
                        $data['sourceFiles'],
                        $scope->writeAllowed,
                        SourceStatus::SOURCE_KNOWN_FROM_OTHER_KEY,
                        $first['file']->relativePath . ':' . $first['key'],
                        $data['sourceFiles'],
                        $this->candidateRows($sourceCandidates, $codeKeys),
                        ['create_alias_source_unit', 'use_existing_key_in_code', 'mark_intentionally_reused', 'ignore_finding_for_run'],
                        ActionState::REVIEW_ONLY,
                        ['defaultValue' => $defaultValue]
                    );
                    continue;
                }

                $localeCandidates = $this->localeSourceCandidates($groups, $file, $key);
                if ($localeCandidates !== []) {
                    $first = $localeCandidates[0];
                    $findings[] = $this->createFinding(
                        TranslationIssueType::MISSING_SOURCE_FROM_LOCALE_CANDIDATE,
                        $scope,
                        $file,
                        $file->locale,
                        $key,
                        (string)$first['source'],
                        '',
                        (string)$first['source'],
                        $data['sourceFiles'],
                        $scope->writeAllowed,
                        SourceStatus::SOURCE_KNOWN_FROM_OTHER_LOCALE,
                        (string)$first['file'] . ':' . (string)$first['locale'],
                        $data['sourceFiles'],
                        $localeCandidates,
                        ['use_other_locale_as_source', 'enter_source_manually', 'mark_locale_source_candidate_reviewed', 'ignore_finding_for_run'],
                        ActionState::READY_TO_WRITE,
                        ['defaultValue' => $defaultValue]
                    );
                    continue;
                }

                $sourceStatus = $keylessCandidates !== [] ? SourceStatus::SOURCE_KNOWN_FROM_KEYLESS_UNIT : SourceStatus::MANUAL_SOURCE_REQUIRED;
                $sourceValue = (string)($keylessCandidates[0]['source'] ?? '');
                $findings[] = $this->createFinding(
                    TranslationIssueType::MISSING_SOURCE_UNIT,
                    $scope,
                    $file,
                    $file->locale,
                    $key,
                    $sourceValue,
                    '',
                    '',
                    $data['sourceFiles'],
                    $scope->writeAllowed,
                    $sourceStatus,
                    $keylessCandidates !== [] ? 'keyless_unit' : '',
                    $data['sourceFiles'],
                    $keylessCandidates,
                    ['enter_source_text', 'use_key_as_temporary_source', 'write_todo_source', 'link_to_candidate', 'ignore_finding_for_run'],
                    ActionState::NEEDS_SOURCE,
                    ['defaultValue' => $defaultValue]
                );
            }
        }
    }

    /**
     * @param TranslationFinding[] $findings
     * @param array<string, array{source: ?XlfTranslationFile, targets: XlfTranslationFile[]}> $groups
     * @param string[] $expectedTargetLocales
     */
    private function addLocaleGapFindings(array &$findings, ScanScope $scope, array $groups, array $expectedTargetLocales): void
    {
        if ($expectedTargetLocales === []) {
            return;
        }

        foreach ($groups as $group) {
            $sourceFile = $group['source'];
            if (!$sourceFile instanceof XlfTranslationFile) {
                continue;
            }

            $existing = [];
            foreach ($group['targets'] as $targetFile) {
                if ($targetFile->locale !== '') {
                    $existing[$targetFile->locale] = true;
                }
            }

            foreach ($expectedTargetLocales as $locale) {
                if (isset($existing[$locale])) {
                    continue;
                }

                $targetRelativePath = $this->targetRelativePath($sourceFile, $locale);
                $findings[] = $this->createFinding(
                    TranslationIssueType::LOCALE_GAP,
                    $scope,
                    $sourceFile,
                    $locale,
                    $sourceFile->baseName,
                    '',
                    '',
                    '',
                    [],
                    $scope->writeAllowed,
                    SourceStatus::NOT_TRANSLATABLE,
                    '',
                    [],
                    [],
                    ['create_target_xlf_file', 'create_missing_units_as_todo', 'create_missing_units_with_deepl', 'ignore_finding_for_run'],
                    ActionState::READY_TO_WRITE,
                    [
                        'targetRelativePath' => $targetRelativePath,
                        'targetAbsolutePath' => dirname($sourceFile->absolutePath) . '/' . basename($targetRelativePath),
                        'sourceRelativePath' => $sourceFile->relativePath,
                        'sourceLanguage' => $sourceFile->sourceLanguage,
                        'sourceUnits' => array_map(static fn(XlfTransUnit $unit): array => [
                            'id' => $unit->transUnitId,
                            'source' => $unit->source,
                        ], array_values($sourceFile->units)),
                    ]
                );
            }
        }
    }

    /**
     * @param TranslationFinding[] $findings
     * @param array<string, array{source: ?XlfTranslationFile, targets: XlfTranslationFile[]}> $groups
     */
    private function addMissingTargetFindings(array &$findings, ScanScope $scope, array $groups): void
    {
        foreach ($groups as $group) {
            $sourceFile = $group['source'];
            if (!$sourceFile instanceof XlfTranslationFile) {
                continue;
            }

            foreach ($group['targets'] as $targetFile) {
                foreach ($sourceFile->units as $id => $sourceUnit) {
                    $targetUnit = $targetFile->getUnit($id);
                    if ($targetUnit instanceof XlfTransUnit && $targetUnit->hasTarget && trim($targetUnit->targetValue) !== '') {
                        continue;
                    }

                    $findings[] = $this->createFinding(
                        TranslationIssueType::MISSING_TARGET,
                        $scope,
                        $targetFile,
                        $targetFile->locale,
                        $id,
                        $sourceUnit->source,
                        $targetUnit instanceof XlfTransUnit ? $targetUnit->targetValue : '',
                        $sourceUnit->source,
                        [],
                        $scope->writeAllowed,
                        SourceStatus::SOURCE_KNOWN,
                        $sourceFile->relativePath,
                        [],
                        [],
                        ['create_deepl_target_suggestion', 'copy_source_value', 'write_todo_target', 'enter_target_text', 'create_empty_target_unit', 'ignore_finding_for_run'],
                        ActionState::READY_TO_CREATE_SUGGESTION,
                        [
                            'targetExists' => $targetUnit instanceof XlfTransUnit,
                            'sourceLanguageFile' => $sourceFile->relativePath,
                            'fixtureCannotChange' => $id === 'readonly.case',
                        ]
                    );
                }
            }
        }
    }

    /**
     * @param TranslationFinding[] $findings
     * @param array<string, array{source: ?XlfTranslationFile, targets: XlfTranslationFile[]}> $groups
     */
    private function addSuspiciousValueFindings(array &$findings, ScanScope $scope, array $groups): void
    {
        foreach ($groups as $group) {
            $sourceFile = $group['source'];
            foreach ($group['targets'] as $targetFile) {
                foreach ($targetFile->units as $id => $targetUnit) {
                    $sourceUnit = $sourceFile instanceof XlfTranslationFile ? $sourceFile->getUnit($id) : null;
                    $sourceValue = $sourceUnit instanceof XlfTransUnit ? $sourceUnit->source : $targetUnit->source;
                    $targetValue = $targetUnit->hasTarget ? $targetUnit->targetValue : '';

                    if (str_starts_with(ltrim($targetValue), 'TODO:')) {
                        $findings[] = $this->createFinding(
                            TranslationIssueType::TODO_VALUE,
                            $scope,
                            $targetFile,
                            $targetFile->locale,
                            $id,
                            $sourceValue,
                            $targetValue,
                            $sourceValue,
                            [],
                            $scope->writeAllowed,
                            $sourceValue !== '' ? SourceStatus::SOURCE_KNOWN : SourceStatus::MANUAL_SOURCE_REQUIRED,
                            $sourceFile instanceof XlfTranslationFile ? $sourceFile->relativePath : '',
                            [],
                            [],
                            ['create_deepl_target_suggestion', 'enter_target_text', 'copy_source_value', 'keep_todo_in_run', 'mark_todo_reviewed'],
                            ActionState::READY_TO_CREATE_SUGGESTION,
                            ['targetExists' => true]
                        );
                    }

                    if ($targetValue !== '' && $sourceValue !== '' && trim($targetValue) === trim($sourceValue)) {
                        $findings[] = $this->createFinding(
                            TranslationIssueType::EQUAL_VALUE,
                            $scope,
                            $targetFile,
                            $targetFile->locale,
                            $id,
                            $sourceValue,
                            $targetValue,
                            $sourceValue,
                            [],
                            $scope->writeAllowed,
                            SourceStatus::SOURCE_KNOWN,
                            $sourceFile instanceof XlfTranslationFile ? $sourceFile->relativePath : '',
                            [],
                            [],
                            ['ignore_finding_for_run', 'always_ignore_key', 'add_to_edit_list', 'enter_target_text', 'create_deepl_target_suggestion', 'prefix_with_todo'],
                            ActionState::REVIEW_ONLY,
                            ['targetExists' => true]
                        );
                    }
                }
            }
        }
    }

    /**
     * @param TranslationFinding[] $findings
     * @param XlfTranslationFile[] $languageFiles
     * @param array<string, array{source: ?XlfTranslationFile, targets: XlfTranslationFile[]}> $groups
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $codeKeys
     */
    private function addUnusedCandidateFindings(array &$findings, ScanScope $scope, array $languageFiles, array $groups, array $codeKeys): void
    {
        foreach ($languageFiles as $file) {
            foreach ($file->units as $id => $unit) {
                if (isset($codeKeys[$id])) {
                    continue;
                }

                $findings[] = $this->createFinding(
                    TranslationIssueType::UNUSED_CANDIDATE,
                    $scope,
                    $file,
                    $file->locale,
                    $id,
                    $unit->source,
                    $unit->targetValue,
                    '',
                    [],
                    $scope->writeAllowed,
                    SourceStatus::NOT_TRANSLATABLE,
                    '',
                    [],
                    $this->sameKeyCandidates($groups, $file, $id),
                    ['show_scanned_usage', 'mark_dynamic_keep', 'ignore_finding_for_run', 'delete_target_locale_only', 'delete_source_and_targets'],
                    ActionState::REVIEW_ONLY,
                    ['targetExists' => true]
                );
            }
        }
    }

    /**
     * @param XlfTranslationFile[] $languageFiles
     * @param array<string, array{source: ?XlfTranslationFile, targets: XlfTranslationFile[]}> $groups
     * @param string[] $languageFileHints
     * @param string[] $sourceFiles
     * @return XlfTranslationFile[]
     */
    private function sourceFilesForCodeKey(array $languageFiles, array $groups, array $languageFileHints, array $sourceFiles): array
    {
        $matches = [];

        foreach ($languageFileHints as $hint) {
            $hint = str_replace('\\', '/', trim($hint, '/'));
            foreach ($languageFiles as $file) {
                if (!$file->canonical) {
                    continue;
                }
                if ($file->relativePath === $hint || str_ends_with($file->relativePath, '/' . $hint)) {
                    $matches[$file->relativePath] = $file;
                }
            }
        }

        if ($matches !== []) {
            return array_values($matches);
        }

        $sourceRoot = $this->firstPathSegmentFromSourceFiles($sourceFiles);
        if ($sourceRoot !== '') {
            foreach ($groups as $group) {
                if (
                    $group['source'] instanceof XlfTranslationFile
                    && str_starts_with($group['source']->relativePath, $sourceRoot . '/')
                    && $group['source']->fileName === 'locallang.xlf'
                ) {
                    return [$group['source']];
                }
            }

            foreach ($groups as $group) {
                if ($group['source'] instanceof XlfTranslationFile && str_starts_with($group['source']->relativePath, $sourceRoot . '/')) {
                    return [$group['source']];
                }
            }
        }

        foreach ($groups as $group) {
            if ($group['source'] instanceof XlfTranslationFile && $group['source']->fileName === 'locallang.xlf') {
                return [$group['source']];
            }
        }

        foreach ($groups as $group) {
            if ($group['source'] instanceof XlfTranslationFile) {
                return [$group['source']];
            }
        }

        return [];
    }

    private function languageFileGroupKey(XlfTranslationFile $file): string
    {
        $relativePath = str_replace('\\', '/', $file->relativePath);
        $languageRootPosition = strpos($relativePath, '/Resources/Private/Language/');
        if ($languageRootPosition === false) {
            return trim(dirname($relativePath), './') . '/' . $file->baseName;
        }

        $extensionPath = substr($relativePath, 0, $languageRootPosition);

        return ($extensionPath !== '' ? $extensionPath : '.') . '/' . $file->baseName;
    }

    /**
     * @param string[] $sourceFiles
     */
    private function firstPathSegmentFromSourceFiles(array $sourceFiles): string
    {
        foreach ($sourceFiles as $sourceFile) {
            $parts = explode('/', trim(str_replace('\\', '/', $sourceFile), '/'));
            $segment = (string)($parts[0] ?? '');
            if ($segment !== '') {
                return $segment;
            }
        }

        return '';
    }

    /**
     * @param XlfTranslationFile[] $languageFiles
     * @return array<string, array<int, array{key: string, file: XlfTranslationFile, unit: XlfTransUnit}>>
     */
    private function buildSourceTextIndex(array $languageFiles): array
    {
        $index = [];
        foreach ($languageFiles as $file) {
            foreach ($file->units as $key => $unit) {
                $source = trim($unit->source !== '' ? $unit->source : $unit->targetValue);
                if ($source === '') {
                    continue;
                }
                $index[mb_strtolower($source)][] = [
                    'key' => $key,
                    'file' => $file,
                    'unit' => $unit,
                ];
            }
        }

        return $index;
    }

    /**
     * @param array<string, array<int, array{key: string, file: XlfTranslationFile, unit: XlfTransUnit}>> $sourceIndex
     * @return array<int, array{key: string, file: XlfTranslationFile, unit: XlfTransUnit}>
     */
    private function sourceCandidatesByText(array $sourceIndex, string $text, string $expectedKey): array
    {
        $candidates = $sourceIndex[mb_strtolower(trim($text))] ?? [];

        return array_values(array_filter(
            $candidates,
            static fn(array $candidate): bool => (string)$candidate['key'] !== $expectedKey
        ));
    }

    /**
     * @param array<int, array{key: string, file: XlfTranslationFile, unit: XlfTransUnit}> $candidates
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $codeKeys
     * @return array<int, array<string, mixed>>
     */
    private function candidateRows(array $candidates, array $codeKeys): array
    {
        return array_map(static function (array $candidate) use ($codeKeys): array {
            $key = (string)$candidate['key'];
            $usageLocations = $codeKeys[$key]['sourceFiles'] ?? [];

            return [
                'type' => 'source_unit',
                'key' => $key,
                'file' => $candidate['file']->relativePath,
                'locale' => $candidate['file']->locale,
                'source' => $candidate['unit']->source,
                'target' => $candidate['unit']->targetValue,
                'usedInCode' => $usageLocations !== [],
                'usageLocations' => $usageLocations,
                'usageCount' => count($usageLocations),
            ];
        }, $candidates);
    }

    /**
     * @param XlfTranslationFile[] $languageFiles
     * @return array<int, array<string, mixed>>
     */
    private function keylessCandidatesByText(array $languageFiles, string $text): array
    {
        $normalized = mb_strtolower(trim($text));
        $matches = [];
        foreach ($languageFiles as $file) {
            foreach ($file->keylessUnits as $unit) {
                $unitText = trim($unit->source !== '' ? $unit->source : $unit->targetValue);
                if ($unitText === '' || mb_strtolower($unitText) !== $normalized) {
                    continue;
                }
                $matches[] = [
                    'type' => 'keyless_unit',
                    'key' => $unit->transUnitId,
                    'file' => $file->relativePath,
                    'locale' => $file->locale,
                    'source' => $unit->source,
                    'target' => $unit->targetValue,
                    'keylessSequence' => $unit->sequence,
                ];
            }
        }

        return $matches;
    }

    /**
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $codeKeys
     * @return array<int, array<string, mixed>>
     */
    private function relatedCodeKeysByDefaultValue(array $codeKeys, string $text): array
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return [];
        }

        $related = [];
        foreach ($codeKeys as $key => $data) {
            foreach ($data['defaultValues'] as $defaultValue) {
                if (mb_strtolower(trim($defaultValue)) !== $normalized) {
                    continue;
                }
                $related[] = [
                    'type' => 'code_usage',
                    'key' => $key,
                    'sourceFiles' => $data['sourceFiles'],
                    'source' => $defaultValue,
                ];
            }
        }

        return $related;
    }

    /**
     * @param array<string, array{source: ?XlfTranslationFile, targets: XlfTranslationFile[]}> $groups
     * @return array<int, array<string, mixed>>
     */
    private function localeSourceCandidates(array $groups, XlfTranslationFile $sourceFile, string $key): array
    {
        $groupKey = $this->languageFileGroupKey($sourceFile);
        $group = $groups[$groupKey] ?? null;
        if (!is_array($group)) {
            return [];
        }

        $candidates = [];
        foreach ($group['targets'] as $targetFile) {
            $targetUnit = $targetFile->getUnit($key);
            if (!$targetUnit instanceof XlfTransUnit) {
                continue;
            }
            $source = trim($targetUnit->targetValue !== '' ? $targetUnit->targetValue : $targetUnit->source);
            if ($source === '') {
                continue;
            }
            $candidates[] = [
                'type' => 'locale_unit',
                'key' => $key,
                'file' => $targetFile->relativePath,
                'locale' => $targetFile->locale,
                'source' => $source,
            ];
        }

        return $candidates;
    }

    /**
     * @param array<string, array{source: ?XlfTranslationFile, targets: XlfTranslationFile[]}> $groups
     * @return array<int, array<string, mixed>>
     */
    private function sameKeyCandidates(array $groups, XlfTranslationFile $file, string $key): array
    {
        $groupKey = $this->languageFileGroupKey($file);
        $group = $groups[$groupKey] ?? null;
        if (!is_array($group)) {
            return [];
        }

        $files = [];
        if ($group['source'] instanceof XlfTranslationFile) {
            $files[] = $group['source'];
        }
        foreach ($group['targets'] as $targetFile) {
            $files[] = $targetFile;
        }

        $candidates = [];
        foreach ($files as $candidateFile) {
            $unit = $candidateFile->getUnit($key);
            if (!$unit instanceof XlfTransUnit) {
                continue;
            }
            $candidates[] = [
                'type' => 'same_key_unit',
                'key' => $key,
                'file' => $candidateFile->relativePath,
                'locale' => $candidateFile->locale,
                'source' => $unit->source,
                'target' => $unit->targetValue,
                'absoluteLanguageFile' => $candidateFile->absolutePath,
            ];
        }

        return $candidates;
    }

    /**
     * @param array<string, array{source: ?XlfTranslationFile, targets: XlfTranslationFile[]}> $groups
     * @return string[]
     */
    private function expectedTargetLocales(array $groups): array
    {
        $locales = [];
        foreach ($groups as $group) {
            foreach ($group['targets'] as $targetFile) {
                if ($targetFile->locale !== '') {
                    $locales[$targetFile->locale] = true;
                }
            }
        }

        return array_keys($locales);
    }

    private function targetRelativePath(XlfTranslationFile $sourceFile, string $locale): string
    {
        $directory = trim(dirname($sourceFile->relativePath), '.\\/');

        return ($directory !== '' ? $directory . '/' : '') . $locale . '.' . $sourceFile->baseName;
    }

    /**
     * @param TranslationFinding[] $findings
     * @return TranslationFinding[]
     */
    private function overlayCannotChange(array $findings, ScanScope $scope): array
    {
        return array_map(function (TranslationFinding $finding) use ($scope): TranslationFinding {
            $reason = $scope->writeBlockReason;
            if ($reason === '' && $finding->absoluteLanguageFile !== '' && is_file($finding->absoluteLanguageFile) && !is_writable($finding->absoluteLanguageFile)) {
                $reason = 'File is not writable';
            }

            if ($reason === '' || $finding->issueType === TranslationIssueType::CANNOT_CHANGE) {
                return $finding;
            }

            return $finding->withCannotChange($reason);
        }, $findings);
    }

    /**
     * @param string[] $sourceFiles
     * @param string[] $usageLocations
     * @param array<int, array<string, mixed>> $relatedCandidates
     * @param string[] $recommendedActions
     * @param array<string, mixed> $metadata
     */
    private function createFinding(
        string $issueType,
        ScanScope $scope,
        XlfTranslationFile $file,
        string $locale,
        string $transUnitId,
        string $sourceValue,
        string $currentTargetValue,
        string $suggestedValue,
        array $sourceFiles,
        bool $canWrite,
        string $sourceStatus,
        string $sourceOrigin,
        array $usageLocations,
        array $relatedCandidates,
        array $recommendedActions,
        string $actionState,
        array $metadata = []
    ): TranslationFinding {
        $fixtureCannotChange = !empty($metadata['fixtureCannotChange']);
        $effectiveIssueType = $fixtureCannotChange ? TranslationIssueType::CANNOT_CHANGE : $issueType;
        $languageFile = (string)($metadata['targetRelativePath'] ?? $file->relativePath);
        $absoluteLanguageFile = (string)($metadata['targetAbsolutePath'] ?? $file->absolutePath);
        $id = TranslationFinding::buildId($effectiveIssueType, $languageFile, $locale, $transUnitId);
        $canChange = !$fixtureCannotChange && $canWrite && !$scope->readOnly;
        $metadata['displayLocale'] ??= $this->displayLocaleForFile($file, $locale);

        return new TranslationFinding(
            $id,
            $effectiveIssueType,
            $scope->extensionKey,
            $languageFile,
            $absoluteLanguageFile,
            $locale,
            $transUnitId,
            $sourceValue,
            $currentTargetValue,
            $suggestedValue,
            $sourceFiles,
            $scope->readOnly || $fixtureCannotChange,
            $canChange,
            false,
            '',
            $issueType,
            $languageFile,
            $sourceStatus,
            $sourceOrigin,
            $usageLocations,
            $relatedCandidates,
            $recommendedActions,
            $fixtureCannotChange ? ActionState::CANNOT_CHANGE : $actionState,
            $canChange,
            $fixtureCannotChange ? 'Fixture read-only case' : '',
            $metadata
        );
    }

    private function displayLocaleForFile(XlfTranslationFile $file, string $locale): string
    {
        if ($locale !== '') {
            return $locale;
        }
        if ($file->targetLanguage !== '') {
            return $file->targetLanguage;
        }
        if ($file->sourceLanguage !== '') {
            return $file->sourceLanguage;
        }

        return 'source';
    }

    /**
     * @param XlfTranslationFile[] $languageFiles
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $codeKeys
     * @param TranslationFinding[] $findings
     * @return array<string, mixed>
     */
    private function buildSummary(ScanScope $scope, array $languageFiles, array $codeKeys, array $findings): array
    {
        $issueCounts = array_fill_keys(TranslationIssueType::all(), 0);
        $summary = [
            'extensionsScanned' => 1,
            'xlfFiles' => count($languageFiles),
            'codeKeys' => count($codeKeys),
            'writableRows' => 0,
            'readOnlyRows' => 0,
            'issueCounts' => $issueCounts,
        ];

        foreach ($findings as $finding) {
            $summary['issueCounts'][$finding->issueType] = (int)($summary['issueCounts'][$finding->issueType] ?? 0) + 1;
            $summary[$finding->issueType] = (int)($summary[$finding->issueType] ?? 0) + 1;

            if ($finding->canChange && $scope->writeAllowed) {
                $summary['writableRows']++;
            } else {
                $summary['readOnlyRows']++;
            }
        }

        foreach (TranslationIssueType::all() as $issueType) {
            $summary[$issueType] ??= 0;
        }

        return $summary;
    }
}
