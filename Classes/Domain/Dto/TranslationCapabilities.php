<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto;

final class TranslationCapabilities
{
    public function __construct(
        public readonly bool $supportsGlossaries,
        public readonly bool $supportsStyleRules,
        public readonly bool $supportsCustomInstructions,
        public readonly bool $supportsTagHandling
    ) {}

    public function toArray(): array
    {
        return [
            'supportsGlossaries' => $this->supportsGlossaries,
            'supportsStyleRules' => $this->supportsStyleRules,
            'supportsCustomInstructions' => $this->supportsCustomInstructions,
            'supportsTagHandling' => $this->supportsTagHandling,
        ];
    }
}
