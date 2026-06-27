<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service\Smoke;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationBatchRequest;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationBatchResult;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationCapabilities;
use Ppl\PplDeeplV3ExtensionTranslator\Service\TranslationProviderInterface;

final class SmokeTranslationProvider implements TranslationProviderInterface
{
    public function __construct(
        private readonly SmokeContext $context
    ) {}

    public function translateBatch(TranslationBatchRequest $request): TranslationBatchResult
    {
        $translations = [];
        foreach ($request->texts as $id => $text) {
            $translations[$id] = 'DEEPL-' . $request->targetLanguage . ': ' . $text;
        }

        $this->appendCall($request, $translations);

        return new TranslationBatchResult($translations, []);
    }

    public function getCapabilities(): TranslationCapabilities
    {
        return new TranslationCapabilities(true, true, true, true);
    }

    /**
     * @param array<string, string> $translations
     */
    private function appendCall(TranslationBatchRequest $request, array $translations): void
    {
        $path = $this->context->fakeDeeplCallLogPath();
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $calls = [];
        if (is_file($path)) {
            try {
                $decoded = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                $calls = is_array($decoded) ? $decoded : [];
            } catch (\Throwable) {
                $calls = [];
            }
        }

        $calls[] = [
            'createdAt' => date(DATE_ATOM),
            'sourceLanguage' => $request->sourceLanguage,
            'targetLanguage' => $request->targetLanguage,
            'texts' => $request->texts,
            'translations' => $translations,
        ];

        file_put_contents($path, json_encode($calls, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
