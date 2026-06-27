<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationFinding;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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
        // Lock the whole read-modify-write so two concurrent rule additions cannot each read the old
        // set and overwrite one another (lost update).
        $lock = $this->acquireWriteLock();
        try {
            $rules = $this->readRules();
            $rule = [
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
            $rule['id'] = $this->ruleId($rule);
            $rules[] = $rule;

            $this->writeRules($this->deduplicateRules($rules));
        } finally {
            $this->releaseWriteLock($lock);
        }
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
     * @return array<int, array<string, mixed>>
     */
    public function readRulesForView(): array
    {
        return array_map(
            function (array $rule): array {
                $id = $this->ruleId($rule);
                $issueType = (string)($rule['issueType'] ?? '');
                $extensionKey = (string)($rule['extensionKey'] ?? '');
                $languageFile = (string)($rule['languageFile'] ?? '');
                $locale = (string)($rule['locale'] ?? '');
                $key = (string)($rule['key'] ?? '');
                $sourceValueHash = (string)($rule['sourceValueHash'] ?? '');

                return array_merge($rule, [
                    'id' => $id,
                    'issueType' => $issueType,
                    'extensionKey' => $extensionKey,
                    'languageFile' => $languageFile,
                    'locale' => $locale,
                    'key' => $key,
                    'sourceValueHash' => $sourceValueHash,
                    'sourceValueHashShort' => $sourceValueHash !== '' ? substr($sourceValueHash, 0, 10) : '',
                    'createdAt' => (string)($rule['createdAt'] ?? ''),
                    'searchText' => mb_strtolower(implode(' ', [
                        $issueType,
                        $extensionKey,
                        $languageFile,
                        $locale,
                        $key,
                        $sourceValueHash,
                    ])),
                ]);
            },
            $this->readRules()
        );
    }

    /**
     * @param string[] $ids
     */
    public function deleteRulesByIds(array $ids): int
    {
        $lookup = array_fill_keys(array_map('strval', $ids), true);
        if ($lookup === []) {
            return 0;
        }

        $lock = $this->acquireWriteLock();
        try {
            $rules = $this->readRules();
            $remaining = [];
            $deleted = 0;
            foreach ($rules as $rule) {
                if (isset($lookup[$this->ruleId($rule)])) {
                    $deleted++;
                    continue;
                }
                $remaining[] = $rule;
            }

            if ($deleted > 0) {
                $this->writeRules($remaining);
            }

            return $deleted;
        } finally {
            $this->releaseWriteLock($lock);
        }
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
            $rule['id'] = $this->ruleId($rule);
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
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the ignore-rules directory.');
        }

        // Atomic write: a temp file + rename so a crash/concurrent read never sees a truncated JSON.
        $json = json_encode($rules, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temp, $json) === false) {
            throw new \RuntimeException('Could not write the ignore-rules temporary file.');
        }
        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Could not move the ignore-rules file into place.');
        }
    }

    private function acquireWriteLock(): ?LockingStrategyInterface
    {
        try {
            $lock = GeneralUtility::makeInstance(LockFactory::class)->createLocker('ppl_et_ignore_rules');
            $lock->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);

            return $lock;
        } catch (\Throwable) {
            return null;
        }
    }

    private function releaseWriteLock(?LockingStrategyInterface $lock): void
    {
        if (!$lock instanceof LockingStrategyInterface) {
            return;
        }
        try {
            $lock->release();
        } catch (\Throwable) {
            // Best effort; the lock is also released when the process ends.
        }
    }

    private function ruleFilePath(): string
    {
        return Environment::getVarPath() . '/' . self::RULE_FILE;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function ruleId(array $rule): string
    {
        return sha1(implode('|', [
            (string)($rule['ruleType'] ?? ''),
            (string)($rule['issueType'] ?? ''),
            (string)($rule['effectiveIssueType'] ?? ''),
            (string)($rule['extensionKey'] ?? ''),
            (string)($rule['languageFile'] ?? ''),
            (string)($rule['locale'] ?? ''),
            (string)($rule['key'] ?? ''),
            (string)($rule['sourceValueHash'] ?? ''),
        ]));
    }
}
