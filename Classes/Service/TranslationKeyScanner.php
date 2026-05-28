<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\ScanScope;

final class TranslationKeyScanner
{
    private const CODE_EXTENSIONS = ['php', 'html', 'js', 'ts', 'yaml', 'yml', 'typoscript', 'tsconfig', 'xml'];
    private const EXCLUDED_DIRECTORIES = ['.git', 'var', 'node_modules', 'Resources/Private/Language'];

    /**
     * @return array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}>
     */
    public function scan(ScanScope $scope): array
    {
        $keys = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($scope->absolutePath, \FilesystemIterator::SKIP_DOTS),
                fn(\SplFileInfo $file): bool => $this->shouldIncludeFileInfo($file, $scope->absolutePath)
            )
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, self::CODE_EXTENSIONS, true)) {
                continue;
            }

            $absoluteFile = str_replace('\\', '/', $file->getPathname());
            $relativeFile = ltrim(substr($absoluteFile, strlen($scope->absolutePath)), '/');
            $contents = (string)file_get_contents($absoluteFile);
            $this->collectKeysFromFile($contents, $relativeFile, $scope->extensionKey, $keys);
        }

        foreach ($keys as $key => $data) {
            $keys[$key]['sourceFiles'] = array_values(array_unique($data['sourceFiles']));
            $keys[$key]['languageFiles'] = array_values(array_unique($data['languageFiles']));
            $keys[$key]['defaultValues'] = array_values(array_unique(array_filter($data['defaultValues'] ?? [], static fn(string $value): bool => trim($value) !== '')));
            $keys[$key]['defaultValue'] = (string)($keys[$key]['defaultValues'][0] ?? '');
        }

        ksort($keys);

        return $keys;
    }

    /**
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $keys
     */
    private function collectKeysFromFile(string $contents, string $relativeFile, string $extensionKey, array &$keys): void
    {
        if (preg_match_all('#LLL:EXT:([A-Za-z0-9_-]+)/([^:\s"\']+\.xlf):([A-Za-z0-9_.:-]+)#', $contents, $matches, PREG_SET_ORDER)) {
            $filterByExtensionKey = !in_array($extensionKey, ['packages', 'extensions', 'local', 'ext'], true);

            foreach ($matches as $match) {
                $referencedExtensionKey = str_replace('-', '_', $match[1]);
                if ($filterByExtensionKey && $extensionKey !== '' && $referencedExtensionKey !== $extensionKey) {
                    continue;
                }

                $languageFileHint = $this->normalizeLanguageFileHint($match[2]);
                if (!$filterByExtensionKey) {
                    $packageSegment = $this->firstPathSegment($relativeFile);
                    if ($packageSegment !== '') {
                        $this->addKey($keys, $this->cleanKey($match[3]), $relativeFile, $packageSegment . '/' . $languageFileHint);
                    }

                    if ($packageSegment !== $match[1]) {
                        $this->addKey($keys, $this->cleanKey($match[3]), $relativeFile, $match[1] . '/' . $languageFileHint);
                    }

                    continue;
                }

                $this->addKey($keys, $this->cleanKey($match[3]), $relativeFile, $languageFileHint);
            }
        }

        if (preg_match_all('#<f:translate\b[^>]*>#i', $contents, $tagMatches)) {
            foreach ($tagMatches[0] as $tag) {
                $attributes = $this->attributesFromTag($tag);
                $key = $this->cleanKey((string)($attributes['key'] ?? ''));
                if ($key === '' || str_starts_with($key, 'LLL:')) {
                    continue;
                }
                $this->addKey($keys, $key, $relativeFile, '', (string)($attributes['default'] ?? ''));
            }
        }

        if (preg_match_all('#\{f:translate\((.*?)\)\}#is', $contents, $inlineMatches)) {
            foreach ($inlineMatches[1] as $arguments) {
                $key = $this->argumentValue($arguments, 'key');
                if ($key === '' || str_starts_with($key, 'LLL:')) {
                    continue;
                }
                $this->addKey($keys, $this->cleanKey($key), $relativeFile, '', $this->argumentValue($arguments, 'default'));
            }
        }

        $patterns = [
            '#LocalizationUtility::translate\(\s*(["\'])(.*?)\1#',
            '#->translate\(\s*(["\'])(.*?)\1#',
            '#->sL\(\s*(["\'])(?:LLL:EXT:[^:]+/[^:]+:)?(.*?)\1#',
            '#data-translate-key=(["\'])(.*?)\1#i',
            '#(?:translate|translateFormat|\$t)\(\s*(["\'])(.*?)\1#',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                $key = $this->cleanKey((string)$match[2]);
                if ($key === '' || str_starts_with($key, 'LLL:')) {
                    continue;
                }

                $this->addKey($keys, $key, $relativeFile, '');
            }
        }
    }

    /**
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $keys
     */
    private function addKey(array &$keys, string $key, string $sourceFile, string $languageFile, string $defaultValue = ''): void
    {
        if ($key === '') {
            return;
        }

        $keys[$key] ??= [
            'key' => $key,
            'sourceFiles' => [],
            'languageFiles' => [],
            'defaultValue' => '',
            'defaultValues' => [],
        ];
        $keys[$key]['sourceFiles'][] = $sourceFile;

        if ($languageFile !== '') {
            $keys[$key]['languageFiles'][] = $languageFile;
        }

        $defaultValue = trim($defaultValue);
        if ($defaultValue !== '') {
            $keys[$key]['defaultValues'][] = $defaultValue;
        }
    }

    private function shouldIncludeFileInfo(\SplFileInfo $file, string $scopePath): bool
    {
        $path = str_replace('\\', '/', $file->getPathname());
        $relative = trim(substr($path, strlen(str_replace('\\', '/', $scopePath))), '/');

        foreach (self::EXCLUDED_DIRECTORIES as $excludedDirectory) {
            if ($relative === $excludedDirectory || str_starts_with($relative . '/', $excludedDirectory . '/')) {
                return false;
            }
        }

        if (str_contains('/' . $relative . '/', '/Resources/Private/Language/')) {
            return false;
        }

        return true;
    }

    private function firstPathSegment(string $relativeFile): string
    {
        $parts = explode('/', trim(str_replace('\\', '/', $relativeFile), '/'));

        return (string)($parts[0] ?? '');
    }

    private function normalizeLanguageFileHint(string $hint): string
    {
        $hint = str_replace('\\', '/', $hint);
        $position = strpos($hint, 'Resources/Private/Language/');

        return $position === false ? 'Resources/Private/Language/' . basename($hint) : substr($hint, $position);
    }

    private function cleanKey(string $key): string
    {
        $key = trim($key, " \t\n\r\0\x0B'\"),;}");

        if (str_contains($key, '{') || str_contains($key, '}')) {
            return '';
        }

        return $key;
    }

    /**
     * @return array<string, string>
     */
    private function attributesFromTag(string $tag): array
    {
        $attributes = [];
        if (preg_match_all('#([A-Za-z0-9_:-]+)\s*=\s*(["\'])(.*?)\2#s', $tag, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attributes[strtolower($match[1])] = html_entity_decode((string)$match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return $attributes;
    }

    private function argumentValue(string $arguments, string $name): string
    {
        if (preg_match('#\b' . preg_quote($name, '#') . '\s*:\s*(["\'])(.*?)\1#s', $arguments, $match) === 1) {
            return html_entity_decode((string)$match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }
}
