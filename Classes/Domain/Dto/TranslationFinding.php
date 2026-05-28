<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue\ActionState;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue\SourceStatus;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Issue\TranslationIssueType;

final class TranslationFinding
{
    /**
     * @param string[] $sourceFiles
     * @param string[] $usageLocations
     * @param array<int, array<string, mixed>> $relatedCandidates
     * @param string[] $recommendedActions
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $findingId,
        public readonly string $issueType,
        public readonly string $extensionKey,
        public readonly string $languageFile,
        public readonly string $absoluteLanguageFile,
        public readonly string $locale,
        public readonly string $transUnitId,
        public readonly string $sourceValue,
        public readonly string $currentTargetValue,
        public readonly string $suggestedValue,
        public readonly array $sourceFiles,
        public readonly bool $readOnly,
        public readonly bool $canWrite,
        public readonly bool $requiresDeepl,
        public readonly string $errorState = '',
        public readonly string $baseIssueType = '',
        public readonly string $expectedLanguageFile = '',
        public readonly string $sourceStatus = SourceStatus::MANUAL_SOURCE_REQUIRED,
        public readonly string $sourceOrigin = '',
        public readonly array $usageLocations = [],
        public readonly array $relatedCandidates = [],
        public readonly array $recommendedActions = [],
        public readonly string $actionState = ActionState::REVIEW_ONLY,
        public readonly bool $canChange = false,
        public readonly string $cannotChangeReason = '',
        public readonly array $metadata = []
    ) {}

    public static function buildId(string $issueType, string $languageFile, string $locale, string $transUnitId): string
    {
        return sha1($issueType . '|' . $languageFile . '|' . $locale . '|' . $transUnitId);
    }

    public function withSuggestedValue(string $suggestedValue, bool $requiresDeepl = false): self
    {
        return new self(
            $this->findingId,
            $this->issueType,
            $this->extensionKey,
            $this->languageFile,
            $this->absoluteLanguageFile,
            $this->locale,
            $this->transUnitId,
            $this->sourceValue,
            $this->currentTargetValue,
            $suggestedValue,
            $this->sourceFiles,
            $this->readOnly,
            $this->canWrite,
            $requiresDeepl,
            $this->errorState,
            $this->baseIssueType,
            $this->expectedLanguageFile,
            $this->sourceStatus,
            $this->sourceOrigin,
            $this->usageLocations,
            $this->relatedCandidates,
            $this->recommendedActions,
            $this->actionState,
            $this->canChange,
            $this->cannotChangeReason,
            $this->metadata
        );
    }

    public function withErrorState(string $errorState): self
    {
        return new self(
            $this->findingId,
            $this->issueType,
            $this->extensionKey,
            $this->languageFile,
            $this->absoluteLanguageFile,
            $this->locale,
            $this->transUnitId,
            $this->sourceValue,
            $this->currentTargetValue,
            $this->suggestedValue,
            $this->sourceFiles,
            $this->readOnly,
            $this->canWrite,
            $this->requiresDeepl,
            $errorState,
            $this->baseIssueType,
            $this->expectedLanguageFile,
            $this->sourceStatus,
            $this->sourceOrigin,
            $this->usageLocations,
            $this->relatedCandidates,
            $this->recommendedActions,
            $this->actionState,
            $this->canChange,
            $this->cannotChangeReason,
            $this->metadata
        );
    }

    public function withCannotChange(string $reason): self
    {
        return new self(
            TranslationFinding::buildId(TranslationIssueType::CANNOT_CHANGE, $this->languageFile, $this->locale, $this->transUnitId),
            TranslationIssueType::CANNOT_CHANGE,
            $this->extensionKey,
            $this->languageFile,
            $this->absoluteLanguageFile,
            $this->locale,
            $this->transUnitId,
            $this->sourceValue,
            $this->currentTargetValue,
            $this->suggestedValue,
            $this->sourceFiles,
            true,
            false,
            false,
            $this->errorState,
            $this->baseIssueType !== '' ? $this->baseIssueType : $this->issueType,
            $this->expectedLanguageFile,
            $this->sourceStatus,
            $this->sourceOrigin,
            $this->usageLocations,
            $this->relatedCandidates,
            ['show_reason', 'copy_details', 'export_findings'],
            ActionState::CANNOT_CHANGE,
            false,
            $reason,
            $this->metadata
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->findingId,
            'issueType' => $this->issueType,
            'issue_type' => $this->issueType,
            'issueLabel' => $this->getIssueLabel(),
            'baseIssueType' => $this->baseIssueType !== '' ? $this->baseIssueType : $this->issueType,
            'base_issue_type' => $this->baseIssueType !== '' ? $this->baseIssueType : $this->issueType,
            'extensionKey' => $this->extensionKey,
            'languageFile' => $this->languageFile,
            'absoluteLanguageFile' => $this->absoluteLanguageFile,
            'expectedLanguageFile' => $this->expectedLanguageFile,
            'locale' => $this->locale,
            'displayLocale' => $this->displayLocale(),
            'transUnitId' => $this->transUnitId,
            'displayTransUnitId' => $this->displayTransUnitId(),
            'displayKey' => $this->displayTransUnitId(),
            'key' => $this->transUnitId,
            'sourceValue' => $this->sourceValue,
            'sourceText' => $this->sourceValue,
            'source_status' => $this->sourceStatus,
            'sourceStatus' => $this->sourceStatus,
            'source_origin' => $this->sourceOrigin,
            'sourceOrigin' => $this->sourceOrigin,
            'currentTargetValue' => $this->currentTargetValue,
            'targetText' => $this->currentTargetValue,
            'suggestedValue' => $this->suggestedValue,
            'suggestionText' => $this->suggestedValue,
            'sourceFiles' => $this->sourceFiles,
            'sourceFilesLabel' => implode(', ', $this->sourceFiles),
            'usageLocations' => $this->usageLocations,
            'usageLocationsLabel' => implode(', ', $this->usageLocations),
            'relatedCandidates' => $this->relatedCandidates,
            'relatedCandidatesLabel' => $this->relatedCandidatesLabel(),
            'recommendedActions' => $this->recommendedActions,
            'actionState' => $this->actionState,
            'action_state' => $this->actionState,
            'readOnly' => $this->readOnly,
            'canWrite' => $this->canWrite,
            'canChange' => $this->canChange,
            'can_change' => $this->canChange,
            'cannotChangeReason' => $this->cannotChangeReason,
            'cannot_change_reason' => $this->cannotChangeReason,
            'requiresDeepl' => $this->requiresDeepl,
            'errorState' => $this->errorState,
            'metadata' => $this->metadata,
        ];
    }

    private function getIssueLabel(): string
    {
        return TranslationIssueType::label($this->issueType);
    }

    private function relatedCandidatesLabel(): string
    {
        $labels = [];
        foreach ($this->relatedCandidates as $candidate) {
            $key = (string)($candidate['key'] ?? $candidate['transUnitId'] ?? '');
            $file = (string)($candidate['file'] ?? $candidate['languageFile'] ?? '');
            $source = (string)($candidate['source'] ?? $candidate['text'] ?? '');
            $labels[] = trim($key . ($file !== '' ? ' @ ' . $file : '') . ($source !== '' ? ' = ' . $source : ''));
        }

        return implode(', ', array_values(array_filter($labels)));
    }

    private function displayTransUnitId(): string
    {
        if ($this->isKeylessUnit()) {
            return '';
        }

        return $this->transUnitId;
    }

    private function isKeylessUnit(): bool
    {
        return $this->issueType === TranslationIssueType::KEYLESS_UNIT
            || $this->baseIssueType === TranslationIssueType::KEYLESS_UNIT
            || str_starts_with($this->transUnitId, '__keyless_');
    }

    private function displayLocale(): string
    {
        $displayLocale = trim((string)($this->metadata['displayLocale'] ?? ''));
        if ($displayLocale !== '') {
            return $displayLocale;
        }

        return $this->locale;
    }
}
