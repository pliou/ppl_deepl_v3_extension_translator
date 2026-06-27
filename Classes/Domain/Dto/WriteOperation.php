<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto;

final class WriteOperation
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $operationId,
        public readonly string $operationType,
        public readonly string $issueType,
        public readonly string $absoluteLanguageFile,
        public readonly string $languageFile,
        public readonly string $locale,
        public readonly string $transUnitId,
        public readonly string $sourceValue,
        public readonly string $targetValue,
        public readonly string $oldTargetValue,
        public readonly array $metadata = []
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->operationId,
            'operationType' => $this->operationType,
            'operationLabel' => $this->operationLabel(),
            'issueType' => $this->issueType,
            'absoluteLanguageFile' => $this->absoluteLanguageFile,
            'languageFile' => $this->languageFile,
            'locale' => $this->locale,
            'transUnitId' => $this->transUnitId,
            'displayTransUnitId' => $this->displayTransUnitId(),
            'sourceValue' => $this->sourceValue,
            'targetValue' => $this->targetValue,
            'oldTargetValue' => $this->oldTargetValue,
            'metadata' => $this->metadata,
        ];
    }

    private function operationLabel(): string
    {
        return match ($this->operationType) {
            'append' => 'Add XLF unit',
            'update' => 'Update XLF unit',
            'update_source' => 'Update XLF source',
            'change_xlf_key' => 'Change XLF key to matching key',
            'replace_code_key' => 'Carry code usage to matching key',
            'replace_config_label' => 'Replace config label reference',
            'rename_keyless' => 'Assign key to XLF unit',
            'delete' => 'Delete XLF unit',
            default => $this->operationType,
        };
    }

    private function displayTransUnitId(): string
    {
        if (str_starts_with($this->transUnitId, '__keyless_')) {
            return 'trans-unit without id';
        }

        return $this->transUnitId;
    }
}
