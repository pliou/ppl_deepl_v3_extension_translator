<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use Ppl\PplDeeplV3Requests\Service\DeeplGlossaryConfigurationService;
use Ppl\PplDeeplV3Requests\Service\DeeplLanguageConfigurationService;
use Ppl\PplDeeplV3Requests\Service\DeeplStyleRuleConfigurationService;

final class TranslationOptionProvider
{
    public function __construct(
        private readonly DeeplLanguageConfigurationService $languageConfigurationService,
        private readonly DeeplGlossaryConfigurationService $glossaryConfigurationService,
        private readonly DeeplStyleRuleConfigurationService $styleRuleConfigurationService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildOptions(string $sourceLanguage, string $targetLanguage): array
    {
        $sourceLanguage = strtoupper(trim($sourceLanguage)) ?: 'EN';
        $targetLanguage = strtoupper(trim($targetLanguage));
        $sourceLanguages = $this->fallbackSourceLanguages();
        $targetLanguages = $this->fallbackTargetLanguages();
        $glossaryOptionsByCombination = [];
        $styleRuleOptionsByLanguage = [];

        try {
            $sourceLanguages = $this->normalizeOptions(
                $this->languageConfigurationService->getEnabledSourceLanguages(),
                $sourceLanguages
            );
            $targetLanguages = $this->normalizeOptions(
                $this->languageConfigurationService->getEnabledTargetLanguages(),
                $targetLanguages
            );
        } catch (\Throwable) {
            // Keep safe fallback options when the shared V3 request configuration is unavailable.
        }

        try {
            $glossaryOptionsByCombination = $this->glossaryConfigurationService->getGlossaryOptionsByCombination();
        } catch (\Throwable) {
            $glossaryOptionsByCombination = [];
        }

        try {
            $styleRuleOptionsByLanguage = $this->styleRuleConfigurationService->getStyleRuleOptionsByLanguage();
        } catch (\Throwable) {
            $styleRuleOptionsByLanguage = [];
        }

        $effectiveTargetLanguage = $targetLanguage !== '' ? $targetLanguage : 'DE';
        $currentGlossaryKey = $this->languageConfigurationService->buildGlossaryCombinationKey($sourceLanguage, $effectiveTargetLanguage);
        $currentStyleLanguage = $this->languageConfigurationService->normalizeStyleRuleLanguage($effectiveTargetLanguage);

        return [
            'sourceLanguages' => $sourceLanguages,
            'targetLanguages' => $targetLanguages,
            'glossaryOptions' => $glossaryOptionsByCombination[$currentGlossaryKey] ?? [],
            'styleRuleOptions' => $styleRuleOptionsByLanguage[$currentStyleLanguage] ?? [],
            'glossaryOptionsByCombinationJson' => $this->jsonForHtml((object)$glossaryOptionsByCombination),
            'styleRuleOptionsByLanguageJson' => $this->jsonForHtml((object)$styleRuleOptionsByLanguage),
            'tagHandlingOptions' => [
                'html' => 'HTML',
                'xml' => 'XML',
            ],
        ];
    }

    /**
     * @param array<string, string> $options
     * @param array<string, string> $fallback
     * @return array<string, string>
     */
    private function normalizeOptions(array $options, array $fallback): array
    {
        $normalized = [];

        foreach ($options as $code => $label) {
            $code = strtoupper(trim((string)$code));
            if ($code !== '') {
                $normalized[$code] = trim((string)$label) !== '' ? (string)$label : $code;
            }
        }

        return $normalized !== [] ? $normalized : $fallback;
    }

    /**
     * @return array<string, string>
     */
    private function fallbackSourceLanguages(): array
    {
        return [
            'EN' => 'English',
            'DE' => 'German',
            'ES' => 'Spanish',
            'FR' => 'French',
            'IT' => 'Italian',
            'NL' => 'Dutch',
            'PT' => 'Portuguese',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function fallbackTargetLanguages(): array
    {
        return [
            'DE' => 'German',
            'EN-GB' => 'English (British)',
            'EN-US' => 'English (American)',
            'ES' => 'Spanish',
            'FR' => 'French',
            'IT' => 'Italian',
            'NL' => 'Dutch',
            'PT-PT' => 'Portuguese',
        ];
    }

    private function jsonForHtml(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_UNESCAPED_SLASHES
        );
    }
}
