<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationFinding;
use TYPO3\CMS\Core\Core\Environment;

final class IgnoreRuleService
{
    private const RULE_FILE = 'ppl_deepl_v3_extension_translator/ignore-rules.json';

    /**
     * @param TranslationFinding[] $findings
     * @return TranslationFinding[]
     */
    public function filterIgnored(array $findings): array
    {
        $rules = $this->readRules();

        return array_values(array_filter(
            $findings,
            fn(TranslationFinding $finding): bool => !$this->matchesAnyRule($finding, $rules)
        ));
    }

    public function addRule(TranslationFinding $finding, string $ruleType, string $note = ''): void
    {
        $rules = $this->readRules();
        $rules[] = [
            'ruleType' => $ruleType,
            'issueType' => $finding->baseIssueType !== '' ? $finding->baseIssueType : $finding->issueType,
            'effectiveIssueType' => $finding->issueType,
            'extensionKey' => $finding->extensionKey,
            'languageFile' => $finding->languageFile,
            'locale' => $finding->locale,
            'key' => $finding->transUnitId,
            'sourceValueHash' => sha1(trim($finding->sourceValue)),
            'note' => $note,
            'createdAt' => date(DATE_ATOM),
        ];

        $this->writeRules($this->deduplicateRules($rules));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function readRules(): array
    {
        $path = $this->ruleFilePath();
        if (!is_file($path)) {
            return [];
        }

        try {
            $decoded = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     */
    private function matchesAnyRule(TranslationFinding $finding, array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($this->matchesRule($finding, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function matchesRule(TranslationFinding $finding, array $rule): bool
    {
        $baseIssueType = $finding->baseIssueType !== '' ? $finding->baseIssueType : $finding->issueType;
        $ruleIssueType = (string)($rule['issueType'] ?? '');
        if ($ruleIssueType !== '' && $ruleIssueType !== $baseIssueType && $ruleIssueType !== $finding->issueType) {
            return false;
        }

        foreach (['extensionKey', 'languageFile', 'locale'] as $field) {
            $value = (string)($rule[$field] ?? '');
            if ($value !== '' && $value !== $finding->{$field}) {
                return false;
            }
        }

        $key = (string)($rule['key'] ?? '');
        if ($key !== '' && $key !== $finding->transUnitId) {
            return false;
        }

        $hash = (string)($rule['sourceValueHash'] ?? '');
        if ($hash !== '' && trim($finding->sourceValue) !== '' && $hash !== sha1(trim($finding->sourceValue))) {
            return false;
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateRules(array $rules): array
    {
        $seen = [];
        $deduplicated = [];
        foreach ($rules as $rule) {
            $identity = implode('|', [
                (string)($rule['ruleType'] ?? ''),
                (string)($rule['issueType'] ?? ''),
                (string)($rule['extensionKey'] ?? ''),
                (string)($rule['languageFile'] ?? ''),
                (string)($rule['locale'] ?? ''),
                (string)($rule['key'] ?? ''),
                (string)($rule['sourceValueHash'] ?? ''),
            ]);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $deduplicated[] = $rule;
        }

        return $deduplicated;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     */
    private function writeRules(array $rules): void
    {
        $path = $this->ruleFilePath();
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode($rules, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function ruleFilePath(): string
    {
        return Environment::getVarPath() . '/' . self::RULE_FILE;
    }
}
