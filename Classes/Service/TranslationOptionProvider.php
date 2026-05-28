<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;

final class TranslationOptionProvider
{
    private const LANGUAGE_SERVICE = 'Ppl\\PplDeeplV3Translate\\Service\\DeeplLanguageService';
    private const GLOSSARY_SERVICE = 'Ppl\\PplDeeplV3Translate\\Service\\DeeplGlossaryService';
    private const STYLE_RULE_SERVICE = 'Ppl\\PplDeeplV3Translate\\Service\\DeeplStyleRuleService';

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

        if (class_exists(self::LANGUAGE_SERVICE)) {
            try {
                $languageService = GeneralUtility::makeInstance(self::LANGUAGE_SERVICE);
                $sourceLanguages = $this->normalizeOptions((array)$languageService->getSourceLanguages(), $sourceLanguages);
                $targetLanguages = $this->normalizeOptions((array)$languageService->getTargetLanguages(), $targetLanguages);
            } catch (\Throwable) {
                // Keep safe fallback options when the optional V3 Translate configuration is unavailable.
            }
        }

        if (class_exists(self::GLOSSARY_SERVICE)) {
            try {
                $glossaryService = GeneralUtility::makeInstance(self::GLOSSARY_SERVICE);
                $glossaryOptionsByCombination = (array)$glossaryService->getGlossaryOptionsByCombination();
            } catch (\Throwable) {
                $glossaryOptionsByCombination = [];
            }
        }

        if (class_exists(self::STYLE_RULE_SERVICE)) {
            try {
                $styleRuleService = GeneralUtility::makeInstance(self::STYLE_RULE_SERVICE);
                $styleRuleOptionsByLanguage = (array)$styleRuleService->getStyleRuleOptionsByLanguage();
            } catch (\Throwable) {
                $styleRuleOptionsByLanguage = [];
            }
        }

        $effectiveTargetLanguage = $targetLanguage !== '' ? $targetLanguage : 'DE';
        $currentGlossaryKey = $this->normalizeGlossaryLanguage($sourceLanguage) . ':' . $this->normalizeGlossaryLanguage($effectiveTargetLanguage);
        $currentStyleLanguage = $this->normalizeStyleRuleLanguage($effectiveTargetLanguage);

        return [
            'sourceLanguages' => $sourceLanguages,
            'targetLanguages' => $targetLanguages,
            'glossaryOptions' => $glossaryOptionsByCombination[$currentGlossaryKey] ?? [],
            'styleRuleOptions' => $styleRuleOptionsByLanguage[$currentStyleLanguage] ?? [],
            'glossaryOptionsByCombinationJson' => json_encode((object)$glossaryOptionsByCombination, JSON_THROW_ON_ERROR),
            'styleRuleOptionsByLanguageJson' => json_encode((object)$styleRuleOptionsByLanguage, JSON_THROW_ON_ERROR),
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

    private function normalizeGlossaryLanguage(string $language): string
    {
        $language = strtoupper(str_replace('_', '-', trim($language)));

        return match (true) {
            $language === 'DE-DE' => 'DE',
            str_starts_with($language, 'EN-') => 'EN',
            str_starts_with($language, 'PT-') => 'PT',
            str_starts_with($language, 'ES-') => 'ES',
            $language === 'ZH-HANS' || $language === 'ZH-HANT' => 'ZH',
            str_contains($language, '-') => explode('-', $language, 2)[0],
            default => $language,
        };
    }

    private function normalizeStyleRuleLanguage(string $language): string
    {
        $language = strtoupper(str_replace('_', '-', trim($language)));

        return match (true) {
            str_starts_with($language, 'EN') => 'EN',
            $language === 'DE' || $language === 'DE-DE' => 'DE',
            str_starts_with($language, 'ES') => 'ES',
            str_starts_with($language, 'FR') => 'FR',
            str_starts_with($language, 'IT') => 'IT',
            str_starts_with($language, 'JA') => 'JA',
            str_starts_with($language, 'KO') => 'KO',
            str_starts_with($language, 'ZH') => 'ZH',
            default => '',
        };
    }
}
