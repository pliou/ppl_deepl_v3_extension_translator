<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto;

final class XlfTranslationFile
{
    /**
     * @param array<string, XlfTransUnit> $units
     * @param XlfTransUnit[] $keylessUnits
     * @param XlfTransUnit[] $invalidUnits
     */
    public function __construct(
        public readonly string $absolutePath,
        public readonly string $relativePath,
        public readonly string $fileName,
        public readonly string $baseName,
        public readonly string $locale,
        public readonly string $sourceLanguage,
        public readonly string $targetLanguage,
        public readonly bool $canonical,
        public readonly array $units,
        public readonly array $keylessUnits = [],
        public readonly array $invalidUnits = []
    ) {}

    public function hasUnit(string $id): bool
    {
        return isset($this->units[$id]);
    }

    public function getUnit(string $id): ?XlfTransUnit
    {
        return $this->units[$id] ?? null;
    }

    public function toArray(): array
    {
        return [
            'absolutePath' => $this->absolutePath,
            'relativePath' => $this->relativePath,
            'fileName' => $this->fileName,
            'baseName' => $this->baseName,
            'locale' => $this->locale,
            'sourceLanguage' => $this->sourceLanguage,
            'targetLanguage' => $this->targetLanguage,
            'canonical' => $this->canonical,
            'unitCount' => count($this->units),
            'keylessUnitCount' => count($this->keylessUnits),
            'invalidUnitCount' => count($this->invalidUnits),
        ];
    }
}
