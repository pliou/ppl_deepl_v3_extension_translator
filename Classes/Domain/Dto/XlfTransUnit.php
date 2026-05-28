<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto;

final class XlfTransUnit
{
    public function __construct(
        public readonly string $transUnitId,
        public readonly string $source,
        public readonly string $targetValue,
        public readonly bool $hasTarget,
        public readonly bool $hasSource = true,
        public readonly string $rawId = '',
        public readonly bool $usableId = true,
        public readonly int $sequence = 0
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->transUnitId,
            'source' => $this->source,
            'target' => $this->targetValue,
            'hasTarget' => $this->hasTarget,
            'hasSource' => $this->hasSource,
            'rawId' => $this->rawId !== '' ? $this->rawId : $this->transUnitId,
            'usableId' => $this->usableId,
            'sequence' => $this->sequence,
        ];
    }
}
