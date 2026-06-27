<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

final class TranslationCodeKeyReplacer
{
    /**
     * @return array{contents: string, replacements: int}
     */
    public function replace(string $contents, string $oldKey, string $newKey): array
    {
        $oldKey = trim($oldKey);
        $newKey = trim($newKey);
        if ($oldKey === '' || $newKey === '' || $oldKey === $newKey) {
            return ['contents' => $contents, 'replacements' => 0];
        }

        $replacements = 0;
        $quotedOldKey = preg_quote($oldKey, '#');
        $keyBoundary = '(?=[^A-Za-z0-9_.:-]|$)';

        $contents = $this->replaceWithCounter(
            '#(LLL:EXT:[A-Za-z0-9_-]+/[^:\s"\']+\.xlf:)' . $quotedOldKey . $keyBoundary . '#',
            $contents,
            static fn(array $match): string => $match[1] . $newKey,
            $replacements
        );

        $contents = $this->replaceWithCounter(
            '#(<f:translate\b[^>]*\b(?:key|id)\s*=\s*)(["\'])' . $quotedOldKey . '\2#is',
            $contents,
            static fn(array $match): string => $match[1] . $match[2] . $newKey . $match[2],
            $replacements
        );

        $contents = $this->replaceWithCounter(
            '#(\{f:translate\([^}]*\b(?:key|id)\s*:\s*)(["\'])' . $quotedOldKey . '\2#is',
            $contents,
            static fn(array $match): string => $match[1] . $match[2] . $newKey . $match[2],
            $replacements
        );

        $contents = $this->replaceWithCounter(
            '#(\bdata-translate-key\s*=\s*)(["\'])' . $quotedOldKey . '\2#i',
            $contents,
            static fn(array $match): string => $match[1] . $match[2] . $newKey . $match[2],
            $replacements
        );

        $contents = $this->replaceWithCounter(
            '#((?:LocalizationUtility::translate|->translate|->sL|(?:translate|translateFormat|\$t))\(\s*)(["\'])(LLL:EXT:[^:]+/[^:]+:)?' . $quotedOldKey . '\2#',
            $contents,
            static fn(array $match): string => $match[1] . $match[2] . (string)($match[3] ?? '') . $newKey . $match[2],
            $replacements
        );

        return ['contents' => $contents, 'replacements' => $replacements];
    }

    /**
     * @param callable(array<int, string>): string $replacement
     */
    private function replaceWithCounter(string $pattern, string $contents, callable $replacement, int &$counter): string
    {
        return (string)preg_replace_callback(
            $pattern,
            static function (array $match) use ($replacement, &$counter): string {
                $counter++;

                return $replacement($match);
            },
            $contents
        );
    }
}
