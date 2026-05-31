<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

final class ExtensionMetadataResolver
{
    /**
     * @var array<string, array{extensionKey: string, title: string, displayName: string, extensionRoot: string}>
     */
    private array $metadataByRoot = [];

    /**
     * @return array{extensionKey: string, title: string, displayName: string, extensionRoot: string}
     */
    public function forLanguageFile(string $scopePath, string $relativeLanguageFile, string $fallbackExtensionKey = ''): array
    {
        $scopePath = $this->normalizePath($scopePath);
        $relativeLanguageFile = $this->normalizeRelativePath($relativeLanguageFile);
        $fallbackExtensionKey = $this->fallbackExtensionKeyForLanguageFile($relativeLanguageFile, $fallbackExtensionKey);
        $absoluteLanguageFile = $scopePath . ($relativeLanguageFile !== '' ? '/' . $relativeLanguageFile : '');
        $extensionRoot = $this->findExtensionRoot($absoluteLanguageFile, $scopePath);

        if ($extensionRoot === '') {
            return $this->metadataFromFallback($fallbackExtensionKey);
        }

        return $this->forRoot($extensionRoot, $fallbackExtensionKey);
    }

    /**
     * @return array{extensionKey: string, title: string, displayName: string, extensionRoot: string}
     */
    public function forRoot(string $extensionRoot, string $fallbackExtensionKey = ''): array
    {
        $extensionRoot = $this->normalizePath($extensionRoot);
        if (isset($this->metadataByRoot[$extensionRoot])) {
            return $this->metadataByRoot[$extensionRoot];
        }

        $extensionKey = $this->normalizeExtensionKey($fallbackExtensionKey);
        $title = '';
        $composerPath = $extensionRoot . '/composer.json';
        if (is_file($composerPath)) {
            $decoded = json_decode((string)file_get_contents($composerPath), true);
            if (is_array($decoded)) {
                $composerExtensionKey = (string)($decoded['extra']['typo3/cms']['extension-key'] ?? '');
                if ($composerExtensionKey !== '') {
                    $extensionKey = $this->normalizeExtensionKey($composerExtensionKey);
                } elseif ($extensionKey === '') {
                    $extensionKey = $this->extensionKeyFromComposerName((string)($decoded['name'] ?? ''));
                }
            }
        }

        $title = $this->readExtEmconfTitle($extensionRoot);
        if ($extensionKey === '') {
            $extensionKey = $this->normalizeExtensionKey(basename($extensionRoot));
        }

        $metadata = [
            'extensionKey' => $extensionKey,
            'title' => $title,
            'displayName' => $title !== '' ? $title : $this->humanReadableExtensionName($extensionKey),
            'extensionRoot' => $extensionRoot,
        ];
        $this->metadataByRoot[$extensionRoot] = $metadata;

        return $metadata;
    }

    public function displayNameForKey(string $extensionKey): string
    {
        return $this->humanReadableExtensionName($this->normalizeExtensionKey($extensionKey));
    }

    private function findExtensionRoot(string $absoluteLanguageFile, string $scopePath): string
    {
        $scopePath = $this->normalizePath($scopePath);
        $directory = is_dir($absoluteLanguageFile) ? $absoluteLanguageFile : dirname($this->normalizePath($absoluteLanguageFile));

        while ($directory !== '' && ($directory === $scopePath || str_starts_with($directory . '/', $scopePath . '/'))) {
            if (is_file($directory . '/composer.json') || is_file($directory . '/ext_emconf.php')) {
                return $directory;
            }

            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }

        return '';
    }

    private function fallbackExtensionKeyForLanguageFile(string $relativeLanguageFile, string $fallbackExtensionKey): string
    {
        $relativeLanguageFile = $this->normalizeRelativePath($relativeLanguageFile);
        if (str_starts_with($relativeLanguageFile, 'Resources/Private/Language/')) {
            return $this->normalizeExtensionKey($fallbackExtensionKey);
        }

        $parts = explode('/', $relativeLanguageFile);
        $firstSegment = (string)($parts[0] ?? '');
        if ($firstSegment !== '') {
            return $this->normalizeExtensionKey($firstSegment);
        }

        return $this->normalizeExtensionKey($fallbackExtensionKey);
    }

    private function metadataFromFallback(string $extensionKey): array
    {
        $extensionKey = $this->normalizeExtensionKey($extensionKey);

        return [
            'extensionKey' => $extensionKey,
            'title' => '',
            'displayName' => $this->humanReadableExtensionName($extensionKey),
            'extensionRoot' => '',
        ];
    }

    private function extensionKeyFromComposerName(string $composerName): string
    {
        $composerName = trim($composerName);
        if ($composerName === '') {
            return '';
        }

        $parts = explode('/', $composerName);

        return $this->normalizeExtensionKey((string)end($parts));
    }

    private function readExtEmconfTitle(string $extensionRoot): string
    {
        $path = $extensionRoot . '/ext_emconf.php';
        if (!is_file($path)) {
            return '';
        }

        $contents = (string)file_get_contents($path);
        if (preg_match('/[\'"]title[\'"]\s*=>\s*([\'"])(.*?)\1/s', $contents, $matches) !== 1) {
            return '';
        }

        return trim(stripcslashes((string)$matches[2]));
    }

    private function normalizeExtensionKey(string $extensionKey): string
    {
        $extensionKey = str_replace('-', '_', trim($extensionKey));
        $extensionKey = preg_replace('/_main$/', '', $extensionKey) ?: $extensionKey;

        return $extensionKey;
    }

    private function humanReadableExtensionName(string $extensionKey): string
    {
        $parts = preg_split('/[_-]+/', trim($extensionKey)) ?: [];
        $specialWords = [
            'api' => 'API',
            'deepl' => 'DeepL',
            'et' => 'ET',
            'ppl' => 'PPL',
            'typo3' => 'TYPO3',
            'v2' => 'V2',
            'v3' => 'V3',
            'xlf' => 'XLF',
        ];

        $words = array_map(
            static function (string $part) use ($specialWords): string {
                $part = strtolower($part);

                return $specialWords[$part] ?? ucfirst($part);
            },
            array_filter($parts, static fn(string $part): bool => $part !== '')
        );

        return $words !== [] ? implode(' ', $words) : $extensionKey;
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', rtrim($path, '/\\'));
    }

    private function normalizeRelativePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}
