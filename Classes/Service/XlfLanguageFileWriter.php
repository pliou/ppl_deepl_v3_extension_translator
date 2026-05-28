<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\WriteOperation;

final class XlfLanguageFileWriter
{
    /**
     * @param WriteOperation[] $operations
     * @return string[]
     */
    public function applyOperations(array $operations): array
    {
        $errors = [];
        $operationsByFile = [];

        foreach ($operations as $operation) {
            $operationsByFile[$operation->absoluteLanguageFile][] = $operation;
        }

        foreach ($operationsByFile as $absoluteFile => $fileOperations) {
            try {
                $createFileOperation = $this->firstCreateFileOperation($fileOperations);
                if ($createFileOperation instanceof WriteOperation && !is_file($absoluteFile)) {
                    $this->createFile($absoluteFile, $createFileOperation);
                }

                $fileOperations = array_values(array_filter(
                    $fileOperations,
                    static fn(WriteOperation $operation): bool => $operation->operationType !== 'create_file'
                ));

                if ($fileOperations === []) {
                    continue;
                }
                $this->applyFileOperations($absoluteFile, $fileOperations);
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
    private function applyFileOperations(string $absoluteFile, array $operations): void
    {
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

            if ($operation->operationType === 'rename_keyless') {
                $this->renameKeylessTransUnit($document, $operation);
                continue;
            }

            if ($operation->operationType === 'delete') {
                $this->deleteTransUnit($document, $operation);
            }
        }

        if ($document->save($absoluteFile) === false) {
            throw new \RuntimeException('Could not save XLF document.');
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
        }

        while ($target->firstChild instanceof \DOMNode) {
            $target->removeChild($target->firstChild);
        }

        $target->appendChild($document->createTextNode($operation->targetValue));
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

    /**
     * @param WriteOperation[] $operations
     */
    private function firstCreateFileOperation(array $operations): ?WriteOperation
    {
        foreach ($operations as $operation) {
            if ($operation->operationType === 'create_file') {
                return $operation;
            }
        }

        return null;
    }

    private function createFile(string $absoluteFile, WriteOperation $operation): void
    {
        if (is_file($absoluteFile)) {
            throw new \RuntimeException('XLF file already exists.');
        }

        $directory = dirname($absoluteFile);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $sourceLanguage = (string)($operation->metadata['sourceLanguage'] ?? 'en');
        $targetLanguage = $operation->locale !== '' ? $operation->locale : (string)($operation->metadata['targetLanguage'] ?? '');
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;
        $xliff = $document->createElement('xliff');
        $xliff->setAttribute('version', '1.0');
        $document->appendChild($xliff);
        $file = $document->createElement('file');
        $file->setAttribute('source-language', $sourceLanguage);
        if ($targetLanguage !== '') {
            $file->setAttribute('target-language', $targetLanguage);
        }
        $file->setAttribute('datatype', 'plaintext');
        $file->setAttribute('original', 'messages');
        $xliff->appendChild($file);
        $file->appendChild($document->createElement('header'));
        $file->appendChild($document->createElement('body'));

        if ($document->save($absoluteFile) === false) {
            throw new \RuntimeException('Could not create XLF file.');
        }
    }
}
