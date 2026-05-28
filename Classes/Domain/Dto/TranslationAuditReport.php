<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto;

final class TranslationAuditReport
{
    /**
     * @param XlfTranslationFile[] $languageFiles
     * @param TranslationFinding[] $findings
     * @param array<string, mixed> $summary
     * @param string[] $selectedLanguageFiles
     */
    public function __construct(
        public readonly ScanScope $scope,
        public readonly array $languageFiles,
        public readonly array $findings,
        public readonly array $summary,
        public readonly array $selectedLanguageFiles
    ) {}

    /**
     * @param string[] $ids
     * @return TranslationFinding[]
     */
    public function findByIds(array $ids): array
    {
        $lookup = array_fill_keys(array_map('strval', $ids), true);

        return array_values(array_filter(
            $this->findings,
            static fn(TranslationFinding $finding): bool => isset($lookup[$finding->findingId])
        ));
    }

    public function toArray(): array
    {
        return [
            'scope' => $this->scope->toArray(),
            'languageFiles' => array_map(static fn(XlfTranslationFile $file): array => $file->toArray(), $this->languageFiles),
            'findings' => array_map(static fn(TranslationFinding $finding): array => $finding->toArray(), $this->findings),
            'summary' => $this->summary,
            'selectedLanguageFiles' => $this->selectedLanguageFiles,
        ];
    }
}
