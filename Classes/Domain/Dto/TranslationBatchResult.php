<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto;

final class TranslationBatchResult
{
    /**
     * @param array<string, string> $translations
     * @param array<string, string> $errors
     */
    public function __construct(
        public readonly array $translations,
        public readonly array $errors
    ) {}

    public static function fromBatchError(TranslationBatchRequest $request, string $message): self
    {
        return new self([], array_fill_keys(array_keys($request->texts), $message));
    }
}
