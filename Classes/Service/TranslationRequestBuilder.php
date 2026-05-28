<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationBatchRequest;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationFinding;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue\SourceStatus;

final class TranslationRequestBuilder
{
    public function __construct(
        private readonly LocaleLanguageMapper $languageMapper
    ) {}

    /**
     * @param TranslationFinding[] $findings
     * @return array{requests: TranslationBatchRequest[], errors: array<string, string>}
     */
    public function buildRequests(array $findings, array $settings): array
    {
        $groups = [];
        $errors = [];
        $sourceLanguage = $this->languageMapper->normalizeSourceLanguage((string)($settings['source_language'] ?? 'EN'));
        $targetOverride = trim((string)($settings['target_language'] ?? ''));
        $glossaryId = trim((string)($settings['glossary_id'] ?? ''));
        $styleRuleId = trim((string)($settings['style_rule_id'] ?? ''));
        $tagHandling = trim((string)($settings['tag_handling'] ?? ''));
        $customInstructions = $this->normalizeCustomInstructions((string)($settings['custom_instructions'] ?? ''));

        foreach ($findings as $finding) {
            if (!$finding->canWrite) {
                $errors[$finding->findingId] = 'Row is read-only or write blocked.';
                continue;
            }

            $sourceText = $this->sourceTextForFinding($finding);
            if ($sourceText === '') {
                $errors[$finding->findingId] = 'No source value available for a DeepL suggestion.';
                continue;
            }

            $targetLanguage = $targetOverride !== ''
                ? $this->languageMapper->normalizeTargetLanguage($targetOverride)
                : $this->languageMapper->fromLocale($finding->locale);
            $groupKey = implode('|', [
                $sourceLanguage,
                $targetLanguage,
                $glossaryId,
                $styleRuleId,
                $tagHandling,
                md5(json_encode($customInstructions, JSON_THROW_ON_ERROR)),
            ]);

            $groups[$groupKey] ??= [
                'sourceLanguage' => $sourceLanguage,
                'targetLanguage' => $targetLanguage,
                'glossaryId' => $glossaryId !== '' ? $glossaryId : null,
                'styleRuleId' => $styleRuleId,
                'tagHandling' => $tagHandling,
                'customInstructions' => $customInstructions,
                'texts' => [],
            ];
            $groups[$groupKey]['texts'][$finding->findingId] = $sourceText;
        }

        $requests = [];
        foreach ($groups as $group) {
            $requests[] = new TranslationBatchRequest(
                $group['sourceLanguage'],
                $group['targetLanguage'],
                $group['texts'],
                $group['glossaryId'],
                $group['styleRuleId'],
                $group['tagHandling'],
                $group['customInstructions']
            );
        }

        return [
            'requests' => $requests,
            'errors' => $errors,
        ];
    }

    private function sourceTextForFinding(TranslationFinding $finding): string
    {
        if (!SourceStatus::canUseForDeepl($finding->sourceStatus)) {
            return '';
        }

        return trim($finding->sourceValue);
    }

    /**
     * @return string[]
     */
    private function normalizeCustomInstructions(string $customInstructions): array
    {
        $lines = preg_split('/\R/', trim($customInstructions)) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));

        return array_slice(array_values(array_unique($lines)), 0, 10);
    }
}
