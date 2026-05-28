<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

final class LocaleLanguageMapper
{
    public function normalizeSourceLanguage(string $language): string
    {
        $language = strtoupper(str_replace('_', '-', trim($language)));

        return match (true) {
            $language === '' => 'EN',
            $language === 'DE-DE' => 'DE',
            str_starts_with($language, 'EN-') => 'EN',
            str_starts_with($language, 'PT-') => 'PT',
            str_starts_with($language, 'ES-') => 'ES',
            $language === 'ZH-HANS' || $language === 'ZH-HANT' => 'ZH',
            str_contains($language, '-') => explode('-', $language, 2)[0],
            default => $language,
        };
    }

    public function normalizeTargetLanguage(string $language): string
    {
        $language = strtoupper(str_replace('_', '-', trim($language)));

        return match ($language) {
            '' => 'DE',
            'EN' => 'EN-GB',
            'PT' => 'PT-PT',
            'DE-DE' => 'DE',
            default => $language,
        };
    }

    public function fromLocale(string $locale): string
    {
        return $this->normalizeTargetLanguage($locale);
    }

    public function fromXlfLanguage(string $language): string
    {
        return $this->normalizeTargetLanguage($language);
    }
}
