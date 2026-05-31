<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\WriteOperation;
use TYPO3\CMS\Core\Core\Environment;

final class XlfLanguageFileWriter
{
    private readonly TranslationCodeKeyReplacer $codeKeyReplacer;

    public function __construct(
        ?TranslationCodeKeyReplacer $codeKeyReplacer = null
    ) {
        $this->codeKeyReplacer = $codeKeyReplacer ?? new TranslationCodeKeyReplacer();
    }

    /**
     * @param WriteOperation[] $operations
     * @return string[]
     */
    public function applyOperations(array $operations): array
    {
        $errors = [];
        $operationsByFile = [];
        $backupRoot = $this->createBackupRoot();

        foreach ($operations as $operation) {
            $operationsByFile[$operation->absoluteLanguageFile][] = $operation;
        }

        foreach ($operationsByFile as $absoluteFile => $fileOperations) {
            try {
                if ($fileOperations === []) {
                    continue;
                }
                $this->applyFileOperations($absoluteFile, $fileOperations, $backupRoot);
            } catch (\Throwable $exception) {
                foreach ($fileOperations as $operation) {
                    $errors[] = $operation->languageFile . ':' . $operation->transUnitId . ' - ' . $exception->getMessage();
                }
            }
        }

        return $errors;
    }

    /**
     * @param WriteOperation[] $operations
     */
    private function applyFileOperations(string $absoluteFile, array $operations, string $backupRoot): void
    {
        if ($this->containsOnlyCodeKeyOperations($operations)) {
            $this->applyCodeKeyOperations($absoluteFile, $operations, $backupRoot);
            return;
        }
        if ($this->containsOnlyConfigLabelOperations($operations)) {
            $this->applyConfigLabelOperations($absoluteFile, $operations, $backupRoot);
            return;
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;

        if (!@$document->load($absoluteFile, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \RuntimeException('Could not load XLF document.');
        }

        $xpath = new \DOMXPath($document);
        $body = $xpath->query('/*[local-name()="xliff"]/*[local-name()="file"]/*[local-name()="body"]')->item(0);
        if (!$body instanceof \DOMElement) {
            throw new \RuntimeException('XLF document has no body element.');
        }

        foreach ($operations as $operation) {
            if ($operation->operationType === 'append') {
                $this->appendTransUnit($document, $body, $operation);
                continue;
            }

            if ($operation->operationType === 'update') {
                $this->updateTransUnit($document, $xpath, $operation);
                continue;
            }

            if ($operation->operationType === 'update_source') {
                $this->updateSourceTransUnit($document, $xpath, $operation);
                continue;
            }

            if ($operation->operationType === 'change_xlf_key') {
                $this->changeXlfKey($document, $xpath, $operation);
                continue;
            }

            if ($operation->operationType === 'rename_keyless') {
                $this->renameKeylessTransUnit($document, $operation);
                continue;
            }

            if ($operation->operationType === 'delete') {
                $this->deleteTransUnit($document, $operation);
                continue;
            }

            if ($operation->operationType === 'replace_code_key') {
                throw new \RuntimeException('Code-key replacement cannot be mixed with XLF operations in one file.');
            }

            if ($operation->operationType === 'replace_config_label') {
                throw new \RuntimeException('Config label replacement cannot be mixed with XLF operations in one file.');
            }
        }

        $this->backupFile($absoluteFile, $operations[0], $backupRoot);
        if ($document->save($absoluteFile) === false) {
            throw new \RuntimeException('Could not save XLF document.');
        }
    }

    /**
     * @param WriteOperation[] $operations
     */
    private function containsOnlyCodeKeyOperations(array $operations): bool
    {
        if ($operations === []) {
            return false;
        }

        foreach ($operations as $operation) {
            if ($operation->operationType !== 'replace_code_key') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param WriteOperation[] $operations
     */
    private function containsOnlyConfigLabelOperations(array $operations): bool
    {
        if ($operations === []) {
            return false;
        }

        foreach ($operations as $operation) {
            if ($operation->operationType !== 'replace_config_label') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param WriteOperation[] $operations
     */
    private function applyCodeKeyOperations(string $absoluteFile, array $operations, string $backupRoot): void
    {
        if (!is_file($absoluteFile)) {
            throw new \RuntimeException('Code file was not found.');
        }

        $contents = (string)file_get_contents($absoluteFile);
        $totalReplacements = 0;
        foreach ($operations as $operation) {
            $oldKey = trim((string)($operation->metadata['oldKey'] ?? ($operation->sourceValue !== '' ? $operation->sourceValue : $operation->transUnitId)));
            $newKey = trim((string)($operation->metadata['newKey'] ?? $operation->targetValue));
            $replaceResult = $this->codeKeyReplacer->replace($contents, $oldKey, $newKey);
            $replacementCount = (int)$replaceResult['replacements'];
            if ($replacementCount <= 0) {
                throw new \RuntimeException('No supported static usage was found for code key "' . $oldKey . '".');
            }
            $contents = (string)$replaceResult['contents'];
            $totalReplacements += $replacementCount;
        }

        if ($totalReplacements <= 0) {
            throw new \RuntimeException('No code-key replacement was applied.');
        }

        $this->backupFile($absoluteFile, $operations[0], $backupRoot);
        if (file_put_contents($absoluteFile, $contents) === false) {
            throw new \RuntimeException('Could not save code file.');
        }
    }

    /**
     * @param WriteOperation[] $operations
     */
    private function applyConfigLabelOperations(string $absoluteFile, array $operations, string $backupRoot): void
    {
        if (!is_file($absoluteFile)) {
            throw new \RuntimeException('Config file was not found.');
        }

        $contents = (string)file_get_contents($absoluteFile);
        $totalReplacements = 0;
        foreach ($operations as $operation) {
            $originalNeedle = (string)($operation->metadata['originalNeedle'] ?? $operation->sourceValue);
            $replacementNeedle = (string)($operation->metadata['replacementNeedle'] ?? $operation->targetValue);
            if ($originalNeedle === '' || $replacementNeedle === '') {
                throw new \RuntimeException('Config label replacement metadata is incomplete.');
            }

            $position = strpos($contents, $originalNeedle);
            if ($position === false) {
                throw new \RuntimeException('No matching hardcoded config label was found for "' . $operation->transUnitId . '".');
            }

            $contents = substr_replace($contents, $replacementNeedle, $position, strlen($originalNeedle));
            $totalReplacements++;
        }

        if ($totalReplacements <= 0) {
            throw new \RuntimeException('No config label replacement was applied.');
        }

        $this->backupFile($absoluteFile, $operations[0], $backupRoot);
        if (file_put_contents($absoluteFile, $contents) === false) {
            throw new \RuntimeException('Could not save config file.');
        }
    }

    private function appendTransUnit(\DOMDocument $document, \DOMElement $body, WriteOperation $operation): void
    {
        if ($this->findTransUnit($document, $operation->transUnitId) instanceof \DOMElement) {
            throw new \RuntimeException('Trans-unit already exists: ' . $operation->transUnitId);
        }

        $namespace = $body->namespaceURI;
        $transUnit = $this->createElement($document, $namespace, 'trans-unit');
        $transUnit->setAttribute('id', $operation->transUnitId);

        $source = $this->createElement($document, $namespace, 'source');
        $source->appendChild($document->createTextNode($operation->sourceValue));
        $transUnit->appendChild($source);

        if ($operation->targetValue !== '' || !empty($operation->metadata['forceTarget'])) {
            $target = $this->createElement($document, $namespace, 'target');
            $target->appendChild($document->createTextNode($operation->targetValue));
            $transUnit->appendChild($target);
        }

        $body->appendChild($transUnit);
    }

    private function renameKeylessTransUnit(\DOMDocument $document, WriteOperation $operation): void
    {
        $sequence = (int)($operation->metadata['keylessSequence'] ?? 0);
        $transUnit = $sequence > 0 ? $this->findTransUnitBySequence($document, $sequence) : $this->findTransUnit($document, '');
        if (!$transUnit instanceof \DOMElement) {
            throw new \RuntimeException('Keyless trans-unit was not found.');
        }

        if ($this->findTransUnit($document, $operation->transUnitId) instanceof \DOMElement) {
            throw new \RuntimeException('Target trans-unit already exists: ' . $operation->transUnitId);
        }

        $transUnit->setAttribute('id', $operation->transUnitId);
    }

    private function deleteTransUnit(\DOMDocument $document, WriteOperation $operation): void
    {
        $transUnit = $this->findTransUnit($document, $operation->transUnitId);
        if (!$transUnit instanceof \DOMElement) {
            $sequence = (int)($operation->metadata['keylessSequence'] ?? 0);
            $transUnit = $sequence > 0 ? $this->findTransUnitBySequence($document, $sequence) : null;
        }
        if (!$transUnit instanceof \DOMElement || !$transUnit->parentNode instanceof \DOMNode) {
            throw new \RuntimeException('Trans-unit was not found: ' . $operation->transUnitId);
        }

        $this->assertTransUnitHasNoInlineMarkup($document, $transUnit, $operation->transUnitId);
        $transUnit->parentNode->removeChild($transUnit);
    }

    private function updateTransUnit(\DOMDocument $document, \DOMXPath $xpath, WriteOperation $operation): void
    {
        $transUnit = $this->findTransUnit($document, $operation->transUnitId);
        if (!$transUnit instanceof \DOMElement) {
            throw new \RuntimeException('Trans-unit was not found: ' . $operation->transUnitId);
        }

        $target = $xpath->query('./*[local-name()="target"]', $transUnit)->item(0);
        if (!$target instanceof \DOMElement) {
            $target = $this->createElement($document, $transUnit->namespaceURI, 'target');
            $transUnit->appendChild($target);
        } else {
            $this->assertElementHasNoInlineMarkup($target, $operation->transUnitId);
        }

        while ($target->firstChild instanceof \DOMNode) {
            $target->removeChild($target->firstChild);
        }

        $target->appendChild($document->createTextNode($operation->targetValue));
    }

    private function updateSourceTransUnit(\DOMDocument $document, \DOMXPath $xpath, WriteOperation $operation): void
    {
        $transUnit = $this->findTransUnit($document, $operation->transUnitId);
        if (!$transUnit instanceof \DOMElement) {
            throw new \RuntimeException('Trans-unit was not found: ' . $operation->transUnitId);
        }

        $source = $xpath->query('./*[local-name()="source"]', $transUnit)->item(0);
        if (!$source instanceof \DOMElement) {
            $source = $this->createElement($document, $transUnit->namespaceURI, 'source');
            if ($transUnit->firstChild instanceof \DOMNode) {
                $transUnit->insertBefore($source, $transUnit->firstChild);
            } else {
                $transUnit->appendChild($source);
            }
        } else {
            $this->assertElementHasNoInlineMarkup($source, $operation->transUnitId);
        }

        while ($source->firstChild instanceof \DOMNode) {
            $source->removeChild($source->firstChild);
        }

        $source->appendChild($document->createTextNode($operation->sourceValue));
    }

    private function changeXlfKey(\DOMDocument $document, \DOMXPath $xpath, WriteOperation $operation): void
    {
        $oldKey = trim((string)($operation->metadata['oldKey'] ?? $operation->transUnitId));
        $newKey = trim((string)($operation->metadata['newKey'] ?? $operation->targetValue));
        if ($oldKey === '' || $newKey === '' || $oldKey === $newKey) {
            throw new \RuntimeException('Invalid key change operation.');
        }

        $sourceUnit = $this->findTransUnit($document, $oldKey);
        if (!$sourceUnit instanceof \DOMElement) {
            throw new \RuntimeException('Trans-unit was not found: ' . $oldKey);
        }

        $targetUnit = $this->findTransUnit($document, $newKey);
        if (!$targetUnit instanceof \DOMElement) {
            $sourceUnit->setAttribute('id', $newKey);
            return;
        }

        $this->assertTransUnitHasNoInlineMarkup($document, $sourceUnit, $oldKey);
        $this->assertTransUnitHasNoInlineMarkup($document, $targetUnit, $newKey);

        $selectedSource = trim($this->childText($xpath, $sourceUnit, 'source'));
        $existingSource = trim($this->childText($xpath, $targetUnit, 'source'));
        if ($selectedSource !== '' && $existingSource !== '' && $selectedSource !== $existingSource) {
            throw new \RuntimeException('Target key already exists with a different source value: ' . $newKey);
        }

        $selectedTarget = trim($this->childText($xpath, $sourceUnit, 'target'));
        $existingTarget = trim($this->childText($xpath, $targetUnit, 'target'));
        if ($selectedTarget !== '' && $existingTarget !== '' && $selectedTarget !== $existingTarget) {
            throw new \RuntimeException('Target key already exists with a different target value: ' . $newKey);
        }

        if ($existingSource === '' && $selectedSource !== '') {
            $this->setChildText($document, $xpath, $targetUnit, 'source', $selectedSource, true);
        }
        if ($existingTarget === '' && $selectedTarget !== '') {
            $this->setChildText($document, $xpath, $targetUnit, 'target', $selectedTarget, false);
        }

        if (!$sourceUnit->parentNode instanceof \DOMNode) {
            throw new \RuntimeException('Selected trans-unit has no parent node: ' . $oldKey);
        }
        $sourceUnit->parentNode->removeChild($sourceUnit);
    }

    private function childText(\DOMXPath $xpath, \DOMElement $transUnit, string $childName): string
    {
        $child = $xpath->query('./*[local-name()="' . $childName . '"]', $transUnit)->item(0);
        return $child instanceof \DOMElement ? (string)$child->textContent : '';
    }

    private function setChildText(\DOMDocument $document, \DOMXPath $xpath, \DOMElement $transUnit, string $childName, string $value, bool $insertFirst): void
    {
        $child = $xpath->query('./*[local-name()="' . $childName . '"]', $transUnit)->item(0);
        if (!$child instanceof \DOMElement) {
            $child = $this->createElement($document, $transUnit->namespaceURI, $childName);
            if ($insertFirst && $transUnit->firstChild instanceof \DOMNode) {
                $transUnit->insertBefore($child, $transUnit->firstChild);
            } else {
                $transUnit->appendChild($child);
            }
        }

        while ($child->firstChild instanceof \DOMNode) {
            $child->removeChild($child->firstChild);
        }
        $child->appendChild($document->createTextNode($value));
    }

    private function findTransUnit(\DOMDocument $document, string $id): ?\DOMElement
    {
        $xpath = new \DOMXPath($document);

        foreach ($xpath->query('//*[local-name()="trans-unit"]') as $transUnit) {
            if ($transUnit instanceof \DOMElement && $transUnit->getAttribute('id') === $id) {
                return $transUnit;
            }
        }

        return null;
    }

    private function findTransUnitBySequence(\DOMDocument $document, int $sequence): ?\DOMElement
    {
        $xpath = new \DOMXPath($document);
        $current = 0;
        foreach ($xpath->query('//*[local-name()="trans-unit"]') as $transUnit) {
            if (!$transUnit instanceof \DOMElement) {
                continue;
            }
            $current++;
            if ($current === $sequence) {
                return $transUnit;
            }
        }

        return null;
    }

    private function createElement(\DOMDocument $document, ?string $namespace, string $name): \DOMElement
    {
        return $namespace !== null && $namespace !== ''
            ? $document->createElementNS($namespace, $name)
            : $document->createElement($name);
    }

    private function assertTransUnitHasNoInlineMarkup(\DOMDocument $document, \DOMElement $transUnit, string $transUnitId): void
    {
        $xpath = new \DOMXPath($document);
        foreach (['source', 'target'] as $childName) {
            $child = $xpath->query('./*[local-name()="' . $childName . '"]', $transUnit)->item(0);
            if ($child instanceof \DOMElement) {
                $this->assertElementHasNoInlineMarkup($child, $transUnitId);
            }
        }
    }

    private function assertElementHasNoInlineMarkup(\DOMElement $element, string $transUnitId): void
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                throw new \RuntimeException('Inline XLF markup is not supported for automatic writing: ' . $transUnitId);
            }
        }
    }

    private function createBackupRoot(): string
    {
        $microtime = str_replace('.', '', sprintf('%.6f', microtime(true)));

        return rtrim(str_replace('\\', '/', Environment::getVarPath()), '/')
            . '/ppl_deepl_v3_extension_translator/backups/'
            . date('Ymd-His')
            . '-'
            . substr($microtime, -6);
    }

    private function backupFile(string $absoluteFile, WriteOperation $operation, string $backupRoot): void
    {
        if (!is_file($absoluteFile)) {
            throw new \RuntimeException('Cannot back up missing file.');
        }

        $backupFile = $backupRoot . '/' . $this->backupRelativePath($operation, $absoluteFile);
        $backupDirectory = dirname($backupFile);
        if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0775, true) && !is_dir($backupDirectory)) {
            throw new \RuntimeException('Could not create backup directory.');
        }

        if (!copy($absoluteFile, $backupFile)) {
            throw new \RuntimeException('Could not create backup file.');
        }
    }

    private function backupRelativePath(WriteOperation $operation, string $absoluteFile): string
    {
        $relativePath = str_replace('\\', '/', trim($operation->languageFile));
        if ($relativePath === '') {
            $relativePath = basename($absoluteFile);
        }

        $parts = array_values(array_filter(
            explode('/', trim($relativePath, '/')),
            static fn(string $part): bool => $part !== '' && $part !== '.' && $part !== '..'
        ));

        return $parts !== [] ? implode('/', $parts) : basename($absoluteFile);
    }

}
