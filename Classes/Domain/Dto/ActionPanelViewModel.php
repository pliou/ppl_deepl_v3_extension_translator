<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto;

final class ActionPanelViewModel
{
    /**
     * @param array<string, mixed> $issueInfo
     * @param array<int, array<string, mixed>> $solutionTabs
     * @param array<string, mixed> $selectionSummary
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $suggestionSummary
     * @param array<string, mixed> $writeSummary
     */
    public function __construct(
        public readonly array $issueInfo,
        public readonly array $solutionTabs,
        public readonly string $activeSolution,
        public readonly array $selectionSummary,
        public readonly array $tool,
        public readonly array $suggestionSummary = [],
        public readonly array $writeSummary = []
    ) {}

    public function toArray(): array
    {
        return [
            'issueInfo' => $this->issueInfo,
            'solutionTabs' => $this->solutionTabs,
            'activeSolution' => $this->activeSolution,
            'selectionSummary' => $this->selectionSummary,
            'tool' => $this->tool,
            'suggestionSummary' => $this->suggestionSummary,
            'writeSummary' => $this->writeSummary,
        ];
    }
}
