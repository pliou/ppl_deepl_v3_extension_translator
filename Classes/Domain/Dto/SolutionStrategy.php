<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto;

final class SolutionStrategy
{
    public function __construct(
        public readonly string $strategyId,
        public readonly string $issueType,
        public readonly string $label,
        public readonly string $command,
        public readonly bool $requiresSelection = true,
        public readonly bool $requiresKnownSource = false,
        public readonly bool $requiresManualSource = false,
        public readonly bool $requiresManualTarget = false,
        public readonly bool $requiresTargetKey = false,
        public readonly bool $requiresDeepl = false,
        public readonly bool $destructive = false,
        public readonly string $description = ''
    ) {}

    public function toArray(bool $active = false, bool $disabled = false, string $disabledReason = ''): array
    {
        return [
            'id' => $this->strategyId,
            'issueType' => $this->issueType,
            'label' => $this->label,
            'command' => $this->command,
            'requiresSelection' => $this->requiresSelection,
            'requiresKnownSource' => $this->requiresKnownSource,
            'requiresManualSource' => $this->requiresManualSource,
            'requiresManualTarget' => $this->requiresManualTarget,
            'requiresTargetKey' => $this->requiresTargetKey,
            'requiresDeepl' => $this->requiresDeepl,
            'destructive' => $this->destructive,
            'description' => $this->description,
            'active' => $active,
            'disabled' => $disabled,
            'disabledReason' => $disabledReason,
        ];
    }
}
