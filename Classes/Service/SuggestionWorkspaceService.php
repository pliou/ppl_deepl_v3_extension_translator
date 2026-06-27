<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

final class SuggestionWorkspaceService
{
    private const SESSION_KEY = 'ppl_deepl_v3_extension_translator_suggestions';

    /**
     * @param string[] $selectedLanguageFiles
     * @param array<string, string> $values
     */
    public function storeSuggestion(string $scanPath, array $selectedLanguageFiles, string $strategy, array $values): void
    {
        $workspace = $this->workspace();
        $scopeKey = $this->scopeKey($scanPath, $selectedLanguageFiles);
        $workspace[$scopeKey] = [
            'strategy' => $strategy,
            'values' => $values,
            'createdAt' => time(),
        ];
        $this->save($workspace);
    }

    /**
     * @param string[] $selectedLanguageFiles
     * @return array<string, string>
     */
    public function getSuggestionsForSelection(string $scanPath, array $selectedLanguageFiles): array
    {
        $entry = $this->workspace()[$this->scopeKey($scanPath, $selectedLanguageFiles)] ?? null;
        if (!is_array($entry) || !is_array($entry['values'] ?? null)) {
            return [];
        }

        $values = [];
        foreach ($entry['values'] as $id => $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $values[(string)$id] = $value;
            }
        }

        return $values;
    }

    /**
     * @param string[] $selectedLanguageFiles
     */
    public function discardSuggestions(string $scanPath, array $selectedLanguageFiles): void
    {
        $workspace = $this->workspace();
        unset($workspace[$this->scopeKey($scanPath, $selectedLanguageFiles)]);
        $this->save($workspace);
    }

    public function clearForScopeChange(): void
    {
        $this->save([]);
    }

    /**
     * @param string[] $selectedLanguageFiles
     */
    public function clearAfterWrite(string $scanPath, array $selectedLanguageFiles): void
    {
        $this->discardSuggestions($scanPath, $selectedLanguageFiles);
    }

    /**
     * @param string[] $selectedLanguageFiles
     */
    private function scopeKey(string $scanPath, array $selectedLanguageFiles): string
    {
        sort($selectedLanguageFiles);

        return sha1(trim($scanPath) . '|' . implode('|', $selectedLanguageFiles));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function workspace(): array
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $data = is_object($backendUser) && method_exists($backendUser, 'getSessionData')
            ? $backendUser->getSessionData(self::SESSION_KEY)
            : [];

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, array<string, mixed>> $workspace
     */
    private function save(array $workspace): void
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (is_object($backendUser) && method_exists($backendUser, 'setAndSaveSessionData')) {
            $backendUser->setAndSaveSessionData(self::SESSION_KEY, $workspace);
        }
    }
}
