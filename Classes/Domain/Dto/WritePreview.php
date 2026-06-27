<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto;

final class WritePreview
{
    /**
     * @param WriteOperation[] $operations
     * @param string[] $errors
     */
    public function __construct(
        public readonly array $operations,
        public readonly array $errors,
        public readonly string $valueMode,
        public readonly string $resolutionAction = ''
    ) {}

    public function hasOperations(): bool
    {
        return $this->operations !== [];
    }

    public function toArray(): array
    {
        $files = [];
        $appends = 0;
        $updates = 0;
        $deletes = 0;
        foreach ($this->operations as $operation) {
            $files[$operation->languageFile] = true;
            if ($operation->operationType === 'append') {
                $appends++;
            }
            if (in_array($operation->operationType, ['update', 'update_source', 'change_xlf_key', 'replace_code_key'], true)) {
                $updates++;
            }
            if ($operation->operationType === 'delete') {
                $deletes++;
            }
        }

        return [
            'operations' => array_map(static fn(WriteOperation $operation): array => $operation->toArray(), $this->operations),
            'errors' => $this->errors,
            'hasContent' => $this->operations !== [] || $this->errors !== [],
            'valueMode' => $this->valueMode,
            'resolutionAction' => $this->resolutionAction !== '' ? $this->resolutionAction : $this->valueMode,
            'operationCount' => count($this->operations),
            'affectedFileCount' => count($files),
            'appendCount' => $appends,
            'updateCount' => $updates,
            'deleteCount' => $deletes,
        ];
    }
}
