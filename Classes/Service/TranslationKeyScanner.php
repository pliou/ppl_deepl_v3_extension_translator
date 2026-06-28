<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\ScanScope;

final class TranslationKeyScanner
{
    private const CODE_EXTENSIONS = ['php', 'html', 'js', 'ts', 'yaml', 'yml', 'typoscript', 'tsconfig', 'xml'];
    private const EXCLUDED_DIRECTORIES = ['.git', 'var', 'node_modules', 'Tests', 'Fixtures', 'Resources/Private/Language'];
    private const EXCLUDED_PATH_PARTS = ['/Tests/', '/Fixtures/', '/Classes/Service/Smoke/', '/Resources/Private/Language/'];
    private const ROOT_SCAN_FIXTURE_PACKAGES = ['ppl_et_issue_fixture', 'ppl_et_smoke_test'];
    private const CONVENTIONAL_LOCALLANG_MOD_KEYS = [
        'mlang_tabs_tab',
        'mlang_labels_tablabel',
        'mlang_labels_tabdescr',
    ];
    private const TRANSLATION_KEY_PREFIXES = [
        'action.',
        'backend.',
        'board.',
        'button.',
        'column.',
        'config.',
        'constants.',
        'error.',
        'extconf.',
        'field.',
        'file.',
        'filter.',
        'group.',
        'issue.',
        'label.',
        'language.',
        'message.',
        'metric.',
        'mode.',
        'module.',
        'option.',
        'placeholder.',
        'preview.',
        'profile.',
        'project.',
        'report.',
        'section.',
        'state.',
        'status.',
        'summary.',
        'tab.',
        'tabs.',
        'task_context.',
        'task_list.',
        'task_overview.',
        'text.',
        'wizard.',
        'workflow.',
    ];
    private const UNDERSCORE_TRANSLATION_KEY_PREFIXES = [
        'configured_values_policy_',
        'current_state_',
        'delegation_',
        'existing_values_',
        'health_',
        'identity_account_source_',
        'identity_operative_rights_',
        'identity_status_',
        'permission_path_',
        'readiness_',
        'requirement_',
        'setup_form_',
        'step_',
        'summary_',
    ];

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

            if (!$this->isScannableCodeFile($file)) {
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
     * @return array<int, array{key: string, sourceFile: string, absoluteSourceFile: string, line: int, languageFile: string, defaultValue: string, originalNeedle: string, replacementNeedle: string}>
     */
    public function scanHardcodedConfigLabels(ScanScope $scope): array
    {
        $labels = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($scope->absolutePath, \FilesystemIterator::SKIP_DOTS),
                fn(\SplFileInfo $file): bool => $this->shouldIncludeFileInfo($file, $scope->absolutePath)
            )
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || !$this->isConfigLabelFile($file)) {
                continue;
            }

            $absoluteFile = str_replace('\\', '/', $file->getPathname());
            $relativeFile = ltrim(substr($absoluteFile, strlen($scope->absolutePath)), '/');
            $contents = (string)file_get_contents($absoluteFile);
            $sourceExtensionKey = $this->extensionKeyForFile($absoluteFile, $scope->absolutePath, $scope->extensionKey);
            foreach ($this->collectHardcodedConfigLabelsFromFile($contents, $relativeFile, $absoluteFile, $sourceExtensionKey) as $label) {
                $labels[] = $label;
            }
        }

        return $labels;
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

        if (preg_match_all('#LLL:EXT:([A-Za-z0-9_-]+)/([^:\s"\']*locallang_mod\.xlf)(?!:)#', $contents, $matches, PREG_SET_ORDER)) {
            $filterByExtensionKey = !in_array($extensionKey, ['packages', 'extensions', 'local', 'ext'], true);

            foreach ($matches as $match) {
                $referencedExtensionKey = str_replace('-', '_', $match[1]);
                if ($filterByExtensionKey && $extensionKey !== '' && $referencedExtensionKey !== $extensionKey) {
                    continue;
                }

                $languageFileHint = $this->normalizeLanguageFileHint($match[2]);
                foreach (self::CONVENTIONAL_LOCALLANG_MOD_KEYS as $key) {
                    if (!$filterByExtensionKey) {
                        $packageSegment = $this->firstPathSegment($relativeFile);
                        if ($packageSegment !== '') {
                            $this->addKey($keys, $key, $relativeFile, $packageSegment . '/' . $languageFileHint);
                        }

                        if ($packageSegment !== $match[1]) {
                            $this->addKey($keys, $key, $relativeFile, $match[1] . '/' . $languageFileHint);
                        }

                        continue;
                    }

                    $this->addKey($keys, $key, $relativeFile, $languageFileHint);
                }
            }
        }

        if (preg_match_all('#<f:translate\b[^>]*>#i', $contents, $tagMatches)) {
            foreach ($tagMatches[0] as $tag) {
                $attributes = $this->attributesFromTag($tag);
                $rawKey = (string)($attributes['key'] ?? $attributes['id'] ?? '');
                $key = $this->translationKeyFromAttributes($attributes);
                if ($key === '' || str_starts_with($key, 'LLL:')) {
                    continue;
                }
                $this->addKey($keys, $key, $relativeFile, $this->fluidLanguageFileHint($rawKey, $relativeFile), (string)($attributes['default'] ?? ''));
            }
        }

        if (preg_match_all('#\{f:translate\((.*?)\)\}#is', $contents, $inlineMatches)) {
            foreach ($inlineMatches[1] as $arguments) {
                $rawKey = $this->argumentValue($arguments, 'key');
                if ($rawKey === '') {
                    $rawKey = $this->argumentValue($arguments, 'id');
                }
                $key = $this->translationKeyFromValue($rawKey);
                if ($key === '' || str_starts_with($key, 'LLL:')) {
                    continue;
                }
                $this->addKey($keys, $key, $relativeFile, $this->fluidLanguageFileHint($rawKey, $relativeFile), $this->argumentValue($arguments, 'default'));
            }
        }

        $patterns = [
            '#LocalizationUtility::translate\(\s*(["\'])(.*?)\1#',
            '#translate[A-Z][A-Za-z0-9_]*\(\s*(["\'])(.*?)\1#',
            '#translateBy[A-Za-z0-9_]*\(\s*(["\'])(.*?)\1#',
            '#\bt[A-Z][A-Za-z0-9_]*\(\s*(["\'])(.*?)\1#',
            '#::L10N\s*\.\s*(["\'])(.*?)\1#',
            '#->translate\(\s*(["\'])(.*?)\1#',
            '#->sL\(\s*(["\'])(?:LLL:EXT:[^:]+/[^:]+:)?(.*?)\1#',
            '#data-translate-key=(["\'])(.*?)\1#i',
            '#(?:translate|translateFormat|\$t)\(\s*(["\'])(.*?)\1#',
            '#(?:->|\.)t\(\s*(["\'])(.*?)\1#',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                $key = $this->translationKeyFromValue((string)$match[2]);
                if ($key === '' || str_starts_with($key, 'LLL:')) {
                    continue;
                }

                $this->addKey($keys, $key, $relativeFile, '');
            }
        }

        $this->collectDomainConcatenatedKeys($contents, $relativeFile, $keys);
        $this->collectTranslationWrapperArgumentKeys($contents, $relativeFile, $keys);
        $this->collectTranslationFieldKeys($contents, $relativeFile, $keys);
        $this->collectConfigurationMappingGeneratedKeys($contents, $relativeFile, $keys);
        if ($this->shouldCollectLooseTranslationLiterals($relativeFile)) {
            $this->collectKnownUnderscoreTranslationKeyLiterals($contents, $relativeFile, $keys);
            $this->collectKeyLikeLiterals($contents, $relativeFile, $keys);
        }
    }

    private function shouldCollectLooseTranslationLiterals(string $relativeFile): bool
    {
        if (str_ends_with($relativeFile, 'Classes/Service/TranslationKeyScanner.php')) {
            return false;
        }

        foreach ([
            '/Classes/Command/',
            '/Classes/Repository/',
            '/Classes/Updates/',
            '/Configuration/TCA/',
        ] as $pathPart) {
            if (str_contains('/' . $relativeFile, $pathPart)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, array{key: string, sourceFile: string, absoluteSourceFile: string, line: int, languageFile: string, defaultValue: string, originalNeedle: string, replacementNeedle: string}>
     */
    private function collectHardcodedConfigLabelsFromFile(string $contents, string $relativeFile, string $absoluteFile, string $extensionKey): array
    {
        $labels = [];
        $lines = preg_split('/\R/u', $contents) ?: [];
        $keyPrefix = $this->configLabelKeyPrefix($relativeFile);
        $languageFile = $this->configLabelLanguageFileHint($relativeFile);
        $extensionKey = $extensionKey !== '' ? $extensionKey : $this->firstPathSegment($relativeFile);

        foreach ($lines as $index => $line) {
            if (preg_match('/(\blabel\s*=\s*)([^\r\n]*)/u', $line, $match) !== 1) {
                continue;
            }

            $labelValue = trim((string)$match[2]);
            if ($labelValue === '' || str_starts_with($labelValue, 'LLL:') || preg_match('/[\p{L}A-Za-z]/u', $labelValue) !== 1) {
                continue;
            }

            $settingName = $this->findRelatedSettingName($lines, $index);
            $key = $this->uniqueConfigLabelKey($labels, $keyPrefix . '.' . ($settingName !== '' ? $settingName : 'label' . ($index + 1)));
            $lllReference = 'LLL:EXT:' . $extensionKey . '/' . $languageFile . ':' . $key;

            $labels[] = [
                'key' => $key,
                'sourceFile' => $relativeFile,
                'absoluteSourceFile' => $absoluteFile,
                'line' => $index + 1,
                'languageFile' => $languageFile,
                'defaultValue' => $labelValue,
                'originalNeedle' => (string)$match[1] . (string)$match[2],
                'replacementNeedle' => (string)$match[1] . $lllReference,
            ];
        }

        return $labels;
    }

    /**
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $keys
     */
    private function addKey(array &$keys, string $key, string $sourceFile, string $languageFile, string $defaultValue = ''): void
    {
        if ($key === '' || str_ends_with($key, '.') || str_ends_with($key, ':') || str_ends_with($key, '_')) {
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

        if ($this->isFixturePackageInRootScan($relative, $scopePath)) {
            return false;
        }

        foreach (self::EXCLUDED_DIRECTORIES as $excludedDirectory) {
            if ($relative === $excludedDirectory || str_starts_with($relative . '/', $excludedDirectory . '/')) {
                return false;
            }
        }

        $relativeWithSlashes = '/' . $relative . '/';
        foreach (self::EXCLUDED_PATH_PARTS as $excludedPathPart) {
            if (str_contains($relativeWithSlashes, $excludedPathPart)) {
                return false;
            }
        }

        return true;
    }

    private function isFixturePackageInRootScan(string $relative, string $scopePath): bool
    {
        if (!in_array(basename(str_replace('\\', '/', $scopePath)), ['packages', 'extensions', 'local', 'ext'], true)) {
            return false;
        }

        return in_array($this->firstPathSegment($relative), self::ROOT_SCAN_FIXTURE_PACKAGES, true);
    }

    private function isScannableCodeFile(\SplFileInfo $file): bool
    {
        return in_array(strtolower($file->getExtension()), self::CODE_EXTENSIONS, true)
            || $file->getFilename() === 'ext_conf_template.txt';
    }

    private function isConfigLabelFile(\SplFileInfo $file): bool
    {
        return $file->getFilename() === 'ext_conf_template.txt'
            || in_array(strtolower($file->getExtension()), ['typoscript', 'tsconfig'], true);
    }

    private function firstPathSegment(string $relativeFile): string
    {
        $parts = explode('/', trim(str_replace('\\', '/', $relativeFile), '/'));

        return (string)($parts[0] ?? '');
    }

    /**
     * @param string[] $lines
     */
    private function findRelatedSettingName(array $lines, int $labelLineIndex): string
    {
        for ($offset = 0; $offset <= 6; $offset++) {
            $line = (string)($lines[$labelLineIndex + $offset] ?? '');
            if (preg_match('/^\s*#/u', $line) === 1) {
                continue;
            }
            if (preg_match('/^\s*([A-Za-z][A-Za-z0-9_.]*)\s*=/u', $line, $match) === 1) {
                $parts = explode('.', (string)$match[1]);

                return $this->cleanKey((string)end($parts));
            }
        }

        return '';
    }

    private function configLabelKeyPrefix(string $relativeFile): string
    {
        $fileName = basename(str_replace('\\', '/', $relativeFile));
        if ($fileName === 'ext_conf_template.txt') {
            return 'extconf';
        }
        if (str_ends_with($fileName, '.typoscript')) {
            return 'constants';
        }
        if (str_ends_with($fileName, '.tsconfig')) {
            return 'tsconfig';
        }

        return 'label';
    }

    private function configLabelLanguageFileHint(string $relativeFile): string
    {
        $relativeFile = trim(str_replace('\\', '/', $relativeFile), '/');
        $parts = explode('/', $relativeFile);
        $first = (string)($parts[0] ?? '');
        $second = (string)($parts[1] ?? '');
        $extensionLocalRoots = ['Build', 'Classes', 'Configuration', 'Documentation', 'Resources', 'Tests'];

        if ($first !== '' && $second !== '' && !in_array($first, $extensionLocalRoots, true)) {
            return $first . '/Resources/Private/Language/locallang.xlf';
        }

        return 'Resources/Private/Language/locallang.xlf';
    }

    private function extensionKeyForFile(string $absoluteFile, string $scopePath, string $fallbackExtensionKey): string
    {
        $scopePath = rtrim(str_replace('\\', '/', $scopePath), '/');
        $directory = dirname(str_replace('\\', '/', $absoluteFile));

        while ($directory !== '' && str_starts_with($directory . '/', $scopePath . '/')) {
            $composerPath = $directory . '/composer.json';
            if (is_file($composerPath)) {
                $decoded = json_decode((string)file_get_contents($composerPath), true);
                if (is_array($decoded)) {
                    $extensionKey = (string)($decoded['extra']['typo3/cms']['extension-key'] ?? '');
                    if ($extensionKey !== '') {
                        return str_replace('-', '_', $extensionKey);
                    }
                }
            }

            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }

        return str_replace('-', '_', $fallbackExtensionKey);
    }

    /**
     * @param array<int, array{key: string}> $labels
     */
    private function uniqueConfigLabelKey(array $labels, string $key): string
    {
        $existing = [];
        foreach ($labels as $label) {
            $existing[(string)$label['key']] = true;
        }
        if (!isset($existing[$key])) {
            return $key;
        }

        $suffix = 2;
        while (isset($existing[$key . '.' . $suffix])) {
            $suffix++;
        }

        return $key . '.' . $suffix;
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

    private function translationKeyFromValue(string $key): string
    {
        $cleanKey = $this->cleanKey($key);
        if ($cleanKey !== '') {
            return $cleanKey;
        }

        if (preg_match('#^\{[A-Za-z][A-Za-z0-9_]*\}([A-Za-z0-9_.:-]+)$#', trim($key), $match) === 1) {
            return $this->cleanKey((string)$match[1]);
        }

        return '';
    }

    /**
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $keys
     */
    private function collectDomainConcatenatedKeys(string $contents, string $relativeFile, array &$keys): void
    {
        if (!preg_match_all('#TRANSLATION_DOMAIN\s*\[\s*["\'][A-Za-z0-9_-]+["\']\s*\]\s*\.\s*(["\'])([A-Za-z0-9_.:-]+)\1#', $contents, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $match) {
            $this->addKey($keys, $this->cleanKey((string)$match[2]), $relativeFile, '');
        }
    }

    /**
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $keys
     */
    private function collectTranslationWrapperArgumentKeys(string $contents, string $relativeFile, array &$keys): void
    {
        if (!preg_match_all('#(?:->translate|translate[A-Z][A-Za-z0-9_]*|translateBy[A-Za-z0-9_]*|->label|\bt[A-Z][A-Za-z0-9_]*|(?:->|\.)t)\s*\(([^;{}]{0,1000}?)\)#s', $contents, $calls)) {
            return;
        }

        foreach ($calls[1] as $arguments) {
            $firstArgument = $this->firstCallArgument((string)$arguments);
            if (!preg_match_all('#(["\'])([A-Za-z][A-Za-z0-9_.:-]+)\1#', $firstArgument, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches as $match) {
                $literalStart = (int)$match[0][1];
                $literalEnd = $literalStart + strlen((string)$match[0][0]);
                $before = substr(rtrim(substr($firstArgument, 0, $literalStart)), -1);
                $after = substr(ltrim(substr($firstArgument, $literalEnd)), 0, 1);
                if ($before === '[' || $after === ']') {
                    continue;
                }

                $this->addKey($keys, $this->cleanKey((string)$match[2][0]), $relativeFile, '');
            }
        }
    }

    /**
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $keys
     */
    private function collectTranslationFieldKeys(string $contents, string $relativeFile, array &$keys): void
    {
        $fieldNames = [
            'ariaLabelKey',
            'descriptionKey',
            'existingValuesKey',
            'emptyMessageKey',
            'emptyTitleKey',
            'frontendContextNavigationAriaLabelKey',
            'frontendPrimaryNavigationAriaLabelKey',
            'frontendSidebarAriaLabelKey',
            'hintKey',
            'labelKey',
            'maxScopeLabelKey',
            'messageKey',
            'operativeRightsLabelKey',
            'readinessLabelKey',
            'requirementKey',
            'returnLabelKey',
            'sourceLabelKey',
            'statusLabelKey',
            'summaryKey',
            'titleKey',
            'valueLabelKey',
        ];

        $pattern = '#["\'](?:' . implode('|', array_map(static fn(string $fieldName): string => preg_quote($fieldName, '#'), $fieldNames)) . ')["\']\s*=>\s*(["\'])([A-Za-z0-9_.:-]+)\1#';
        if (!preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $match) {
            $this->addKey($keys, $this->cleanKey((string)$match[2]), $relativeFile, '');
        }
    }

    private function firstCallArgument(string $arguments): string
    {
        $arguments = trim($arguments);
        $length = strlen($arguments);
        $depth = 0;
        $quote = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $arguments[$i];
            if ($quote !== '') {
                if ($char === '\\') {
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    $quote = '';
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }

            if ($char === '(' || $char === '[') {
                $depth++;
                continue;
            }

            if (($char === ')' || $char === ']') && $depth > 0) {
                $depth--;
                continue;
            }

            if ($char === ',' && $depth === 0) {
                return trim(substr($arguments, 0, $i));
            }
        }

        return $arguments;
    }

    /**
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $keys
     */
    private function collectConfigurationMappingGeneratedKeys(string $contents, string $relativeFile, array &$keys): void
    {
        if (!str_ends_with($relativeFile, 'Classes/Mapping/ConfigurationMapping.php')) {
            return;
        }

        $languageFile = 'Resources/Private/Language/Config/config.xlf';
        if (!preg_match_all("#['\"](?:label|description|placeholder)['\"]\s*=>\s*['\"]([A-Za-z0-9_.:-]+)['\"]#", $contents, $matches)) {
            return;
        }

        foreach (array_unique($matches[1]) as $key) {
            $this->addKey($keys, $this->cleanKey((string)$key), $relativeFile, $languageFile);
        }
    }

    /**
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $keys
     */
    private function collectKnownUnderscoreTranslationKeyLiterals(string $contents, string $relativeFile, array &$keys): void
    {
        $matchCount = preg_match_all('#(["\'])([A-Za-z][A-Za-z0-9_]*(?:_[A-Za-z0-9]+)+)\1#', $contents, $matches, PREG_SET_ORDER);
        if ($matchCount === false || $matchCount === 0) {
            return;
        }

        foreach ($matches as $match) {
            $key = $this->cleanKey((string)$match[2]);
            if (!$this->looksLikeKnownUnderscoreTranslationKey($key)) {
                continue;
            }

            $this->addKey($keys, $key, $relativeFile, '');
        }
    }

    /**
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $keys
     */
    private function collectKeyLikeLiterals(string $contents, string $relativeFile, array &$keys): void
    {
        $matchCount = preg_match_all('#(["\'])([A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z0-9_:-]+)+)\1#', $contents, $matches, PREG_SET_ORDER);
        if ($matchCount === false || $matchCount === 0) {
            return;
        }

        foreach ($matches as $match) {
            $key = $this->cleanKey((string)$match[2]);
            if (!$this->looksLikeTranslationKey($key)) {
                continue;
            }

            $this->addKey($keys, $key, $relativeFile, '');
        }
    }

    private function looksLikeTranslationKey(string $key): bool
    {
        if ($key === '' || str_starts_with($key, 'LLL:') || str_contains($key, '{') || str_contains($key, '}')) {
            return false;
        }

        foreach (self::TRANSLATION_KEY_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeKnownUnderscoreTranslationKey(string $key): bool
    {
        if ($key === '' || str_ends_with($key, '_') || str_contains($key, '{') || str_contains($key, '}')) {
            return false;
        }

        foreach (self::UNDERSCORE_TRANSLATION_KEY_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
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

    /**
     * @param array<string, string> $attributes
     */
    private function translationKeyFromAttributes(array $attributes): string
    {
        $key = $this->translationKeyFromValue((string)($attributes['key'] ?? ''));
        if ($key !== '') {
            return $key;
        }

        return $this->translationKeyFromValue((string)($attributes['id'] ?? ''));
    }

    private function fluidLanguageFileHint(string $rawKey, string $relativeFile): string
    {
        if (preg_match('#^\{([A-Za-z][A-Za-z0-9_]*)\}#', trim($rawKey), $match) !== 1) {
            return '';
        }

        return match ((string)$match[1]) {
            'tdTabs', 'td_tabs' => 'Resources/Private/Language/Tabs/tabs.xlf',
            'td_setup' => 'Resources/Private/Language/Setup/setup.xlf',
            'td_fe' => 'Resources/Private/Language/Frontend/frontend.xlf',
            'td_form' => 'Resources/Private/Language/Form/form.xlf',
            'td' => $this->defaultFluidLanguageFileHint($relativeFile),
            default => '',
        };
    }

    private function defaultFluidLanguageFileHint(string $relativeFile): string
    {
        $relativeFile = '/' . trim(str_replace('\\', '/', $relativeFile), '/');

        foreach ([
            '/Resources/Private/Templates/Config/' => 'Resources/Private/Language/Config/config.xlf',
            '/Resources/Private/Partials/Config/' => 'Resources/Private/Language/Config/config.xlf',
            '/Resources/Private/Templates/Task/' => 'Resources/Private/Language/Task/task.xlf',
            '/Resources/Private/Partials/Task/' => 'Resources/Private/Language/Task/task.xlf',
            '/Resources/Private/Templates/Board/' => 'Resources/Private/Language/Task/task.xlf',
            '/Resources/Private/Partials/Board/' => 'Resources/Private/Language/Task/task.xlf',
            '/Resources/Private/Templates/Frontend/' => 'Resources/Private/Language/Frontend/frontend.xlf',
            '/Resources/Private/Partials/Frontend/' => 'Resources/Private/Language/Frontend/frontend.xlf',
            '/Resources/Private/Templates/Setup/' => 'Resources/Private/Language/Setup/setup.xlf',
            '/Resources/Private/Partials/Setup/' => 'Resources/Private/Language/Setup/setup.xlf',
        ] as $pathPart => $languageFile) {
            if (str_contains($relativeFile, $pathPart)) {
                return $languageFile;
            }
        }

        return '';
    }

    private function argumentValue(string $arguments, string $name): string
    {
        if (preg_match('#\b' . preg_quote($name, '#') . '\s*:\s*(["\'])(.*?)\1#s', $arguments, $match) === 1) {
            return html_entity_decode((string)$match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }
}
