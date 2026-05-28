<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\ScanScope;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\XlfTranslationFile;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\XlfTransUnit;

final class XlfLanguageFileReader
{
    /**
     * @param string[] $selectedFiles
     * @return XlfTranslationFile[]
     */
    public function findLanguageFiles(ScanScope $scope, array $selectedFiles = []): array
    {
        $selectedLookup = array_fill_keys(array_map([$this, 'normalizeRelativePath'], $selectedFiles), true);
        $files = [];
        $languageRoots = $this->findLanguageRoots($scope->absolutePath);

        foreach ($languageRoots as $languageRoot) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($languageRoot, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'xlf') {
                    continue;
                }

                $absoluteFile = str_replace('\\', '/', $file->getPathname());
                $relativeFile = $this->normalizeRelativePath(ltrim(substr($absoluteFile, strlen($scope->absolutePath)), '/'));
                if ($selectedLookup !== [] && !isset($selectedLookup[$relativeFile])) {
                    continue;
                }

                if (!$this->isSupportedFileName($file->getFilename())) {
                    continue;
                }

                $files[] = $this->read($absoluteFile, $relativeFile);
            }
        }

        usort($files, static fn(XlfTranslationFile $left, XlfTranslationFile $right): int => strcmp($left->relativePath, $right->relativePath));

        return $files;
    }

    public function read(string $absoluteFile, string $relativeFile): XlfTranslationFile
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;
        $loaded = @$document->load($absoluteFile, LIBXML_NONET | LIBXML_COMPACT);
        if (!$loaded) {
            throw new \RuntimeException('Could not read XLF file: ' . $relativeFile);
        }

        $xpath = new \DOMXPath($document);
        $fileElement = $xpath->query('/*[local-name()="xliff"]/*[local-name()="file"]')->item(0);
        if (!$fileElement instanceof \DOMElement) {
            throw new \RuntimeException('XLF file has no file element: ' . $relativeFile);
        }

        $fileName = basename($relativeFile);
        $filenameLocale = $this->localeFromFilename($fileName);
        $sourceLanguage = $fileElement->getAttribute('source-language') ?: 'en';
        $targetLanguage = $fileElement->getAttribute('target-language');
        $locale = $targetLanguage !== '' ? $targetLanguage : $filenameLocale;
        $canonical = $filenameLocale === '' && $targetLanguage === '';
        $units = [];
        $keylessUnits = [];
        $invalidUnits = [];
        $sequence = 0;

        foreach ($xpath->query('//*[local-name()="trans-unit"]') as $unitElement) {
            if (!$unitElement instanceof \DOMElement) {
                continue;
            }

            $sequence++;
            $rawId = $unitElement->hasAttribute('id') ? $unitElement->getAttribute('id') : '';
            $id = trim($rawId);
            $usableId = $this->isUsableTransUnitId($id);
            $sourceElement = $xpath->query('./*[local-name()="source"]', $unitElement)->item(0);
            $targetElement = $xpath->query('./*[local-name()="target"]', $unitElement)->item(0);
            $source = $sourceElement instanceof \DOMElement ? trim($sourceElement->textContent) : '';
            $target = $targetElement instanceof \DOMElement ? trim($targetElement->textContent) : '';

            if (!$usableId) {
                $bucketId = '__keyless_' . $sequence;
                $unit = new XlfTransUnit(
                    $bucketId,
                    $source,
                    $target,
                    $targetElement instanceof \DOMElement,
                    $sourceElement instanceof \DOMElement,
                    $rawId,
                    false,
                    $sequence
                );

                if ($source !== '' || $target !== '') {
                    $keylessUnits[] = $unit;
                } else {
                    $invalidUnits[] = $unit;
                }
                continue;
            }

            $units[$id] = new XlfTransUnit(
                $id,
                $source,
                $target,
                $targetElement instanceof \DOMElement,
                $sourceElement instanceof \DOMElement,
                $rawId,
                true,
                $sequence
            );
        }

        return new XlfTranslationFile(
            $absoluteFile,
            $relativeFile,
            $fileName,
            $this->baseNameFromFilename($fileName),
            $locale,
            $sourceLanguage,
            $targetLanguage,
            $canonical,
            $units,
            $keylessUnits,
            $invalidUnits
        );
    }

    private function isSupportedFileName(string $fileName): bool
    {
        return preg_match('#^(?:[a-z]{2}(?:[-_][A-Za-z0-9]+)?\.)?locallang(?:_(?:db|mod))?\.xlf$#i', $fileName) === 1;
    }

    /**
     * @return string[]
     */
    private function findLanguageRoots(string $scopePath): array
    {
        $directLanguageRoot = $scopePath . '/Resources/Private/Language';
        if (is_dir($directLanguageRoot)) {
            return [$directLanguageRoot];
        }

        $languageRoots = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($scopePath, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file): bool {
                    return !$file->isDir() || !in_array($file->getFilename(), ['.git', 'node_modules', 'vendor', 'var'], true);
                }
            ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || !$item->isDir() || $item->getFilename() !== 'Language') {
                continue;
            }

            $path = str_replace('\\', '/', $item->getPathname());
            if (str_ends_with($path, '/Resources/Private/Language')) {
                $languageRoots[] = $path;
            }
        }

        sort($languageRoots);

        return $languageRoots;
    }

    private function localeFromFilename(string $fileName): string
    {
        if (preg_match('#^([a-z]{2}(?:[-_][A-Za-z0-9]+)?)\.locallang#i', $fileName, $match) === 1) {
            return str_replace('_', '-', strtolower($match[1]));
        }

        return '';
    }

    private function baseNameFromFilename(string $fileName): string
    {
        return preg_replace('#^[a-z]{2}(?:[-_][A-Za-z0-9]+)?\.#i', '', $fileName) ?: $fileName;
    }

    private function isUsableTransUnitId(string $id): bool
    {
        return $id !== '' && preg_match('#^[^\s<>"\']+$#u', $id) === 1;
    }

    private function normalizeRelativePath(string $path): string
    {
        return str_replace('\\', '/', trim($path, '/'));
    }
}
