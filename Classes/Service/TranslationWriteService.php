<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationFinding;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\WriteOperation;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\WritePreview;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue\TranslationIssueType;

final class TranslationWriteService
{
    private readonly TranslationCodeKeyReplacer $codeKeyReplacer;

    public function __construct(
        private readonly XlfLanguageFileWriter $writer,
        ?TranslationCodeKeyReplacer $codeKeyReplacer = null
    ) {
        $this->codeKeyReplacer = $codeKeyReplacer ?? new TranslationCodeKeyReplacer();
    }

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

        return new WritePreview($operations, array_values(array_unique($errors)), $resolutionAction, $resolutionAction);
    }

    /**
     * @return array{errors: string[], writtenRows: int, affectedFiles: int}
     */
    public function write(WritePreview $preview): array
    {
        if (!$preview->hasOperations()) {
            return [
                'errors' => ['No writable operations were selected.'],
                'writtenRows' => 0,
                'affectedFiles' => 0,
            ];
        }

        $errors = $this->writer->applyOperations($preview->operations);
        if ($errors !== []) {
            // applyOperations() is all-or-nothing: any failure rolls back every file it already wrote,
            // so a non-empty error list means NOTHING was persisted. Report zero written rows/files
            // instead of "operations minus errors", which previously claimed a phantom partial success.
            return [
                'errors' => array_merge($preview->errors, $errors),
                'writtenRows' => 0,
                'affectedFiles' => 0,
            ];
        }

        $files = [];
        foreach ($preview->operations as $operation) {
            $files[$operation->languageFile] = true;
        }

        return [
            'errors' => $preview->errors,
            'writtenRows' => count($preview->operations),
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
            'enter_source_text', 'enter_source_manually' => $this->sourceOperation($finding, $this->stringValue($values, 'manual_source_text'), $errors),
            'change_key_to_matching_key', 'replace_code_key_with_existing_key' => $this->keyToMatchingKeyOperations($finding, $this->targetKeyForMismatch($finding, $values, $errors), $errors),
            'create_alias_source_unit' => $this->sourceOperation($finding, $finding->sourceValue, $errors),
            'use_other_locale_as_source' => $this->sourceOperation($finding, $this->candidateSourceValue($finding), $errors),
            'copy_source_unit_without_target' => $this->sourceOperation($finding, $finding->sourceValue, $errors),
            'create_manual_translation_unit' => $this->manualTranslationUnitOperations($finding, $this->stringValue($values, 'manual_source_text'), $this->stringValue($values, 'manual_target_text'), $errors),
            'enter_key_manually', 'link_keyless_unit_to_key' => $this->keylessRenameOperation($finding, $this->stringValue($values, 'target_key'), $errors),
            'delete_invalid_unit' => [$this->deleteOperation($finding, ['keylessSequence' => (int)($finding->metadata['keylessSequence'] ?? 0)])],
            'copy_source_value' => $this->targetOperation($finding, $finding->sourceValue, $errors),
            'write_todo_target', 'prefix_with_todo' => $this->targetOperation($finding, 'TODO: ' . $finding->transUnitId, $errors),
            'enter_target_text' => $this->targetOperation($finding, $this->stringValue($values, 'manual_target_text'), $errors),
            'create_empty_target_unit' => $this->targetOperation($finding, '', $errors, true),
            'create_deepl_target_suggestion' => $this->targetOperation($finding, $this->translatedValue($finding, $values), $errors),
            'delete_target_locale_only' => $this->deleteTargetLocaleOnly($finding, $errors),
            'delete_translation_unit', 'delete_source_and_targets' => $this->deleteSourceAndTargets($finding),
            default => $this->unsupportedAction($finding, $resolutionAction, $errors),
        };
    }

    /**
     * @param string[] $errors
     * @return WriteOperation[]
     */
    private function keyToMatchingKeyOperations(TranslationFinding $finding, string $targetKey, array &$errors): array
    {
        $selectedKey = trim($finding->transUnitId);
        $targetKey = trim($targetKey);
        if (!$this->validateKeyChange($finding, $selectedKey, $targetKey, $errors)) {
            return [];
        }

        $operations = [];
        $xlfOperation = $this->xlfKeyChangeOperation($finding, $selectedKey, $targetKey);
        if ($xlfOperation instanceof WriteOperation) {
            $operations[] = $xlfOperation;
        }

        foreach ($this->replaceCodeKeyOperations($finding, $selectedKey, $targetKey, $errors) as $operation) {
            $operations[] = $operation;
        }

        if ($operations === []) {
            $errors[] = $finding->languageFile . ':' . $selectedKey . ' has no XLF unit and no supported static code usage to change.';
        }

        return $operations;
    }

    /**
     * @param string[] $errors
     */
    private function validateKeyChange(TranslationFinding $finding, string $selectedKey, string $targetKey, array &$errors): bool
    {
        if ($selectedKey === '') {
            $errors[] = $finding->languageFile . ' has no selected key.';
            return false;
        }
        if ($targetKey === '') {
            return false;
        }
        if ($targetKey === $selectedKey) {
            $errors[] = $finding->languageFile . ':' . $selectedKey . ' already uses the selected target key.';
            return false;
        }

        $allowedCandidateKeys = $this->candidateKeys($finding);
        if ($allowedCandidateKeys !== [] && !isset($allowedCandidateKeys[$targetKey])) {
            $errors[] = $finding->languageFile . ':' . $selectedKey . ' target key "' . $targetKey . '" is not one of the matching existing keys.';
            return false;
        }

        return true;
    }

    private function xlfKeyChangeOperation(TranslationFinding $finding, string $selectedKey, string $targetKey): ?WriteOperation
    {
        if ($finding->absoluteLanguageFile === '' || !is_file($finding->absoluteLanguageFile)) {
            return null;
        }
        if (!$this->transUnitExists($finding->absoluteLanguageFile, $selectedKey)) {
            return null;
        }

        return new WriteOperation(
            $finding->findingId . ':' . sha1($finding->languageFile . '|' . $selectedKey . '|' . $targetKey . '|xlf'),
            'change_xlf_key',
            TranslationIssueType::KEY_MISMATCH_CANDIDATE,
            $finding->absoluteLanguageFile,
            $finding->languageFile,
            $finding->locale,
            $selectedKey,
            $finding->sourceValue,
            $targetKey,
            $selectedKey,
            [
                'oldKey' => $selectedKey,
                'newKey' => $targetKey,
                'mergeIfTargetExists' => true,
            ]
        );
    }

    private function transUnitExists(string $absoluteLanguageFile, string $key): bool
    {
        $contents = is_file($absoluteLanguageFile) && is_readable($absoluteLanguageFile)
            ? (string)file_get_contents($absoluteLanguageFile)
            : '';
        if ($contents === '' || $key === '') {
            return false;
        }

        return preg_match('/<[^>]*trans-unit\b[^>]*\bid=("|\')' . preg_quote($key, '/') . '\1/iu', $contents) === 1;
    }

    /**
     * @param string[] $errors
     * @return WriteOperation[]
     */
    private function replaceCodeKeyOperations(TranslationFinding $finding, string $selectedKey, string $targetKey, array &$errors): array
    {
        $sourceFiles = array_values(array_unique(array_filter(
            $finding->sourceFiles,
            static fn(string $sourceFile): bool => trim($sourceFile) !== ''
        )));
        if ($sourceFiles === []) {
            return [];
        }

        $operations = [];
        foreach ($sourceFiles as $sourceFile) {
            $absoluteSourceFile = $this->absoluteSourceFile($finding, $sourceFile);
            if ($absoluteSourceFile === '' || !is_file($absoluteSourceFile)) {
                $errors[] = $sourceFile . ' was not found for code-key replacement.';
                continue;
            }

            $contents = (string)file_get_contents($absoluteSourceFile);
            $replaceResult = $this->codeKeyReplacer->replace($contents, $selectedKey, $targetKey);
            $replacementCount = (int)$replaceResult['replacements'];
            if ($replacementCount <= 0) {
                $errors[] = $sourceFile . ' contains no supported static usage of "' . $selectedKey . '".';
                continue;
            }

            $operations[] = new WriteOperation(
                $finding->findingId . ':' . sha1($sourceFile . '|' . $selectedKey . '|' . $targetKey),
                'replace_code_key',
                TranslationIssueType::KEY_MISMATCH_CANDIDATE,
                $absoluteSourceFile,
                $sourceFile,
                '',
                $selectedKey,
                $selectedKey,
                $targetKey,
                $selectedKey,
                [
                    'oldKey' => $selectedKey,
                    'newKey' => $targetKey,
                    'replacementCount' => $replacementCount,
                    'languageFile' => $finding->languageFile,
                ]
            );
        }

        return $operations;
    }

    /**
     * @param array<string, mixed> $values
     * @param string[] $errors
     */
    private function targetKeyForMismatch(TranslationFinding $finding, array $values, array &$errors): string
    {
        $targetKey = trim((string)($values['target_key'] ?? ''));
        if ($targetKey !== '') {
            return $targetKey;
        }

        $candidateKeys = array_keys($this->candidateKeys($finding));
        if (count($candidateKeys) === 1) {
            return $candidateKeys[0];
        }

        if ($candidateKeys === []) {
            $errors[] = $finding->languageFile . ':' . $finding->transUnitId . ' has no matching existing key.';
        } else {
            $errors[] = $finding->languageFile . ':' . $finding->transUnitId . ' has multiple matching existing keys. Choose one target key.';
        }

        return '';
    }

    /**
     * @return array<string, bool>
     */
    private function candidateKeys(TranslationFinding $finding): array
    {
        $keys = [];
        foreach ($finding->relatedCandidates as $candidate) {
            $key = trim((string)($candidate['key'] ?? $candidate['transUnitId'] ?? ''));
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    private function absoluteSourceFile(TranslationFinding $finding, string $sourceFile): string
    {
        $sourceFile = ltrim(str_replace('\\', '/', trim($sourceFile)), '/');
        $absoluteLanguageFile = str_replace('\\', '/', $finding->absoluteLanguageFile);
        $languageFile = ltrim(str_replace('\\', '/', $finding->languageFile), '/');
        if ($absoluteLanguageFile === '' || $languageFile === '' || !str_ends_with($absoluteLanguageFile, $languageFile)) {
            return '';
        }

        return substr($absoluteLanguageFile, 0, -strlen($languageFile)) . $sourceFile;
    }

    /**
     * @param string[] $errors
     * @return WriteOperation[]
     */
    private function sourceOperation(TranslationFinding $finding, string $sourceValue, array &$errors): array
    {
        $sourceValue = trim($sourceValue);
        if ($sourceValue === '') {
            $errors[] = $finding->languageFile . ':' . $finding->transUnitId . ' needs source text.';
            return [];
        }

        $operationType = !empty($finding->metadata['sourceExists']) ? 'update_source' : 'append';

        return [new WriteOperation(
            $finding->findingId,
            $operationType,
            $finding->baseIssueType !== '' ? $finding->baseIssueType : $finding->issueType,
            $finding->absoluteLanguageFile,
            $finding->languageFile,
            $finding->locale,
            $finding->transUnitId,
            $sourceValue,
            '',
            '',
            ['purpose' => $operationType === 'update_source' ? 'source_update' : 'source_unit']
        )];
    }

    /**
     * @param string[] $errors
     * @return WriteOperation[]
     */
    private function manualTranslationUnitOperations(TranslationFinding $finding, string $sourceValue, string $targetValue, array &$errors): array
    {
        $sourceValue = trim($sourceValue);
        $targetValue = trim($targetValue);
        $hardcodedConfigLabel = is_array($finding->metadata['hardcodedConfigLabel'] ?? null) ? $finding->metadata['hardcodedConfigLabel'] : [];
        if ($sourceValue === '' && $hardcodedConfigLabel !== [] && trim($finding->sourceValue) !== '') {
            $sourceValue = trim($finding->sourceValue);
        }
        $targetFiles = is_array($finding->metadata['targetLanguageFiles'] ?? null) ? $finding->metadata['targetLanguageFiles'] : [];
        $targetRequired = $hardcodedConfigLabel === [] || $targetFiles !== [];

        if ($sourceValue === '') {
            $errors[] = $finding->languageFile . ':' . $finding->transUnitId . ' needs source text.';
        }
        if ($targetRequired && $targetValue === '') {
            $errors[] = $finding->languageFile . ':' . $finding->transUnitId . ' needs target text.';
        }
        if ($sourceValue === '' || ($targetRequired && $targetValue === '')) {
            return [];
        }

        $operations = [];
        if (empty($finding->metadata['sourceExists'])) {
            $operations[] = new WriteOperation(
                $finding->findingId,
                'append',
                TranslationIssueType::MISSING_TRANSLATION_UNIT,
                $finding->absoluteLanguageFile,
                $finding->languageFile,
                $finding->locale,
                $finding->transUnitId,
                $sourceValue,
                '',
                '',
                ['purpose' => 'manual_source_unit']
            );
        }

        foreach ($targetFiles as $targetFile) {
            $absoluteLanguageFile = trim((string)($targetFile['absoluteLanguageFile'] ?? ''));
            $languageFile = trim((string)($targetFile['languageFile'] ?? ''));
            if ($absoluteLanguageFile === '' || $languageFile === '' || $languageFile === $finding->languageFile) {
                continue;
            }

            $operations[] = new WriteOperation(
                $finding->findingId . ':' . sha1($languageFile),
                'append',
                TranslationIssueType::MISSING_TRANSLATION_UNIT,
                $absoluteLanguageFile,
                $languageFile,
                (string)($targetFile['locale'] ?? ''),
                $finding->transUnitId,
                $sourceValue,
                $targetValue,
                '',
                ['purpose' => 'manual_target_unit', 'forceTarget' => true]
            );
        }

        if ($hardcodedConfigLabel !== []) {
            $configReplacement = $this->configLabelReplacementOperation($finding, $hardcodedConfigLabel, $errors);
            if ($configReplacement instanceof WriteOperation) {
                $operations[] = $configReplacement;
            }
        }

        return $operations;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param string[] $errors
     */
    private function configLabelReplacementOperation(TranslationFinding $finding, array $metadata, array &$errors): ?WriteOperation
    {
        $absoluteSourceFile = trim((string)($metadata['absoluteSourceFile'] ?? ''));
        $sourceFile = trim((string)($metadata['sourceFile'] ?? ''));
        $originalNeedle = (string)($metadata['originalNeedle'] ?? '');
        $replacementNeedle = (string)($metadata['replacementNeedle'] ?? '');
        if ($absoluteSourceFile === '' || $sourceFile === '' || $originalNeedle === '' || $replacementNeedle === '') {
            $errors[] = $finding->languageFile . ':' . $finding->transUnitId . ' has incomplete config label replacement metadata.';
            return null;
        }
        if (!is_file($absoluteSourceFile)) {
            $errors[] = $sourceFile . ' was not found for config label replacement.';
            return null;
        }

        return new WriteOperation(
            $finding->findingId . ':' . sha1($sourceFile . '|' . $finding->transUnitId . '|config-label'),
            'replace_config_label',
            TranslationIssueType::MISSING_TRANSLATION_UNIT,
            $absoluteSourceFile,
            $sourceFile,
            '',
            $finding->transUnitId,
            $originalNeedle,
            $replacementNeedle,
            $originalNeedle,
            [
                'originalNeedle' => $originalNeedle,
                'replacementNeedle' => $replacementNeedle,
                'languageFile' => $finding->languageFile,
            ]
        );
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

    private function candidateSourceValue(TranslationFinding $finding): string
    {
        foreach ($finding->relatedCandidates as $candidate) {
            $source = trim((string)($candidate['source'] ?? $candidate['text'] ?? ''));
            if ($source !== '') {
                return $source;
            }
        }

        return trim($finding->suggestedValue);
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
