<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto;

final class ScanScope
{
    public function __construct(
        public readonly string $inputPath,
        public readonly string $absolutePath,
        public readonly string $relativePath,
        public readonly string $extensionKey,
        public readonly bool $readOnly,
        public readonly bool $vendor,
        public readonly bool $writeAllowed,
        public readonly string $writeBlockReason = ''
    ) {}

    public function toArray(): array
    {
        return [
            'inputPath' => $this->inputPath,
            'absolutePath' => $this->absolutePath,
            'relativePath' => $this->relativePath,
            'extensionKey' => $this->extensionKey,
            'readOnly' => $this->readOnly,
            'vendor' => $this->vendor,
            'writeAllowed' => $this->writeAllowed,
            'writeBlockReason' => $this->writeBlockReason,
        ];
    }
}
