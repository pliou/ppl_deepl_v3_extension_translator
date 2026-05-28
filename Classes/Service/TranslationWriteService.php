<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationFinding;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\WriteOperation;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\WritePreview;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue\TranslationIssueType;

final class TranslationWriteService
{
    public function __construct(
        private readonly TranslationBackupService $backupService,
        private readonly XlfLanguageFileWriter $writer
    ) {}

    /**
     * @param TranslationFinding[] $findings
     * @param array<string, mixed> $values
     */
    public function buildPreview(array $findings, string $resolutionAction, array $values = []): WritePreview
    {
        $operations = [];
        $errors = [];

        foreach ($findings as $finding) {
            if (!$finding->canChange) {
                $errors[] = $finding->languageFile . ':' . $finding->transUnitId . ' cannot be changed' . ($finding->cannotChangeReason !== '' ? ' - ' . $finding->cannotChangeReason : '.');
                continue;
            }

            foreach ($this->operationsForFinding($finding, $resolutionAction, $values, $errors) as $operation) {
                $operations[] = $operation;
            }
        }

        return new WritePreview($operations, array_values(array_unique($errors)), $this->backupService->buildPreviewRoot(), $resolutionAction, $resolutionAction);
    }

    /**
     * @return array{backupRoot: string, errors: string[], writtenRows: int, affectedFiles: int}
     */
    public function write(WritePreview $preview): array
    {
        if (!$preview->hasOperations()) {
            return [
                'backupRoot' => '',
                'errors' => ['No writable operations were selected.'],
                'writtenRows' => 0,
                'affectedFiles' => 0,
            ];
        }

        $backupRoot = $this->backupService->createBackups($preview->operations);
        $errors = $this->writer->applyOperations($preview->operations);
        $files = [];

        foreach ($preview->operations as $operation) {
            $files[$operation->languageFile] = true;
        }

        return [
            'backupRoot' => $backupRoot,
            'errors' => array_merge($preview->errors, $errors),
            'writtenRows' => count($preview->operations) - count($errors),
            'affectedFiles' => count($files),
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @param string[] $errors
     * @return WriteOperation[]
     */
    private function operationsForFinding(TranslationFinding $finding, string $resolutionAction, array $values, array &$errors): array
    {
        return match ($resolutionAction) {
            'enter_source_text', 'enter_source_manually' => $this->sourceAppendOperation($finding, $this->stringValue($values, 'manual_source_text'), $errors),
            'use_key_as_temporary_source' => $this->sourceAppendOperation($finding, $finding->transUnitId, $errors),
            'write_todo_source', 'create_todo_source' => $this->sourceAppendOperation($finding, 'TODO: ' . $finding->transUnitId, $errors),
            'create_alias_source_unit', 'use_other_locale_as_source', 'link_to_candidate' => $this->sourceAppendOperation($finding, $finding->sourceValue, $errors),
            'enter_key_manually', 'link_keyless_unit_to_key' => $this->keylessRenameOperation($finding, $this->stringValue($values, 'target_key'), $errors),
            'delete_invalid_unit_with_backup' => [$this->deleteOperation($finding, ['keylessSequence' => (int)($finding->metadata['keylessSequence'] ?? 0)])],
            'copy_source_value' => $this->targetOperation($finding, $finding->sourceValue, $errors),
            'write_todo_target', 'prefix_with_todo' => $this->targetOperation($finding, 'TODO: ' . $finding->transUnitId, $errors),
            'enter_target_text' => $this->targetOperation($finding, $this->stringValue($values, 'manual_target_text'), $errors),
            'create_empty_target_unit' => $this->targetOperation($finding, '', $errors, true),
            'create_deepl_target_suggestion' => $this->targetOperation($finding, $this->translatedValue($finding, $values), $errors),
            'delete_target_locale_only' => $this->deleteTargetLocaleOnly($finding, $errors),
            'delete_source_and_targets' => $this->deleteSourceAndTargets($finding),
            'create_target_xlf_file' => [$this->createFileOperation($finding)],
            'create_missing_units_as_todo' => $this->createMissingUnitsAsTodoOperations($finding),
            default => $this->unsupportedAction($finding, $resolutionAction, $errors),
        };
    }

    /**
     * @param string[] $errors
     * @return WriteOperation[]
     */
    private function sourceAppendOperation(TranslationFinding $finding, string $sourceValue, array &$errors): array
    {
        $sourceValue = trim($sourceValue);
        if ($sourceValue === '') {
            $errors[] = $finding->languageFile . ':' . $finding->transUnitId . ' needs source text.';
            return [];
        }

        return [new WriteOperation(
            $finding->findingId,
            'append',
            $finding->baseIssueType !== '' ? $finding->baseIssueType : $finding->issueType,
            $finding->absoluteLanguageFile,
            $finding->languageFile,
            $finding->locale,
            $finding->transUnitId,
            $sourceValue,
            '',
            '',
            ['purpose' => 'source_unit']
        )];
    }

    /**
     * @param string[] $errors
     * @return WriteOperation[]
     */
    private function keylessRenameOperation(TranslationFinding $finding, string $targetKey, array &$errors): array
    {
        $targetKey = trim($targetKey);
        if ($targetKey === '') {
            $errors[] = $finding->languageFile . ':' . $finding->transUnitId . ' needs a target key.';
            return [];
        }

        return [new WriteOperation(
            $finding->findingId,
            'rename_keyless',
            TranslationIssueType::KEYLESS_UNIT,
            $finding->absoluteLanguageFile,
            $finding->languageFile,
            $finding->locale,
            $targetKey,
            $finding->sourceValue,
            $finding->currentTargetValue,
            '',
            ['keylessSequence' => (int)($finding->metadata['keylessSequence'] ?? 0)]
        )];
    }

    /**
     * @param string[] $errors
     * @return WriteOperation[]
     */
    private function targetOperation(TranslationFinding $finding, string $targetValue, array &$errors, bool $allowEmptyTarget = false): array
    {
        if (!$allowEmptyTarget && trim($targetValue) === '') {
            $errors[] = $finding->languageFile . ':' . $finding->transUnitId . ' needs target text.';
            return [];
        }

        if (trim($finding->sourceValue) === '' && !$allowEmptyTarget) {
            $errors[] = $finding->languageFile . ':' . $finding->transUnitId . ' has no known source text.';
            return [];
        }

        $operationType = !empty($finding->metadata['targetExists']) ? 'update' : 'append';

        return [new WriteOperation(
            $finding->findingId,
            $operationType,
            $finding->baseIssueType !== '' ? $finding->baseIssueType : $finding->issueType,
            $finding->absoluteLanguageFile,
            $finding->languageFile,
            $finding->locale,
            $finding->transUnitId,
            $finding->sourceValue !== '' ? $finding->sourceValue : $finding->transUnitId,
            $targetValue,
            $finding->currentTargetValue,
            ['forceTarget' => $allowEmptyTarget]
        )];
    }

    /**
     * @param string[] $errors
     * @return WriteOperation[]
     */
    private function deleteTargetLocaleOnly(TranslationFinding $finding, array &$errors): array
    {
        if ($finding->locale === '' || $finding->issueType !== TranslationIssueType::UNUSED_CANDIDATE) {
            $errors[] = $finding->languageFile . ':' . $finding->transUnitId . ' is not a target-locale unused candidate.';
            return [];
        }

        return [$this->deleteOperation($finding)];
    }

    /**
     * @return WriteOperation[]
     */
    private function deleteSourceAndTargets(TranslationFinding $finding): array
    {
        $operations = [$this->deleteOperation($finding)];
        foreach ($finding->relatedCandidates as $candidate) {
            $absolute = (string)($candidate['absoluteLanguageFile'] ?? '');
            $languageFile = (string)($candidate['file'] ?? '');
            if ($absolute === '' || $languageFile === $finding->languageFile) {
                continue;
            }
            $operations[] = new WriteOperation(
                $finding->findingId . ':' . sha1($languageFile),
                'delete',
                TranslationIssueType::UNUSED_CANDIDATE,
                $absolute,
                $languageFile,
                (string)($candidate['locale'] ?? ''),
                $finding->transUnitId,
                (string)($candidate['source'] ?? ''),
                (string)($candidate['target'] ?? ''),
                ''
            );
        }

        return $operations;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function deleteOperation(TranslationFinding $finding, array $metadata = []): WriteOperation
    {
        return new WriteOperation(
            $finding->findingId,
            'delete',
            $finding->baseIssueType !== '' ? $finding->baseIssueType : $finding->issueType,
            $finding->absoluteLanguageFile,
            $finding->languageFile,
            $finding->locale,
            $finding->transUnitId,
            $finding->sourceValue,
            $finding->currentTargetValue,
            '',
            $metadata
        );
    }

    private function createFileOperation(TranslationFinding $finding): WriteOperation
    {
        return new WriteOperation(
            $finding->findingId,
            'create_file',
            TranslationIssueType::LOCALE_GAP,
            $finding->absoluteLanguageFile,
            $finding->languageFile,
            $finding->locale,
            $finding->transUnitId,
            '',
            '',
            '',
            $finding->metadata
        );
    }

    /**
     * @return WriteOperation[]
     */
    private function createMissingUnitsAsTodoOperations(TranslationFinding $finding): array
    {
        $operations = [$this->createFileOperation($finding)];
        $sourceUnits = is_array($finding->metadata['sourceUnits'] ?? null) ? $finding->metadata['sourceUnits'] : [];
        foreach ($sourceUnits as $sourceUnit) {
            $key = trim((string)($sourceUnit['id'] ?? ''));
            if ($key === '') {
                continue;
            }
            $operations[] = new WriteOperation(
                $finding->findingId . ':' . sha1($key),
                'append',
                TranslationIssueType::LOCALE_GAP,
                $finding->absoluteLanguageFile,
                $finding->languageFile,
                $finding->locale,
                $key,
                (string)($sourceUnit['source'] ?? ''),
                'TODO: ' . $key,
                ''
            );
        }

        return $operations;
    }

    /**
     * @param string[] $errors
     * @return WriteOperation[]
     */
    private function unsupportedAction(TranslationFinding $finding, string $resolutionAction, array &$errors): array
    {
        $errors[] = $finding->languageFile . ':' . $finding->transUnitId . ' does not support action ' . $resolutionAction . '.';

        return [];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function stringValue(array $values, string $key): string
    {
        return trim((string)($values[$key] ?? ''));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function translatedValue(TranslationFinding $finding, array $values): string
    {
        $translated = $values['translated_values'] ?? [];
        if (is_array($translated)) {
            return trim((string)($translated[$finding->findingId] ?? ''));
        }

        return '';
    }
}
