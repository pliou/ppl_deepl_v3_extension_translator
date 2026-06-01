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
                $key = $this->translationKeyFromAttributes($attributes);
                if ($key === '' || str_starts_with($key, 'LLL:')) {
                    continue;
                }
                $this->addKey($keys, $key, $relativeFile, '', (string)($attributes['default'] ?? ''));
            }
        }

        if (preg_match_all('#\{f:translate\((.*?)\)\}#is', $contents, $inlineMatches)) {
            foreach ($inlineMatches[1] as $arguments) {
                $key = $this->argumentValue($arguments, 'key');
                if ($key === '') {
                    $key = $this->argumentValue($arguments, 'id');
                }
                $key = $this->translationKeyFromValue($key);
                if ($key === '' || str_starts_with($key, 'LLL:')) {
                    continue;
                }
                $this->addKey($keys, $key, $relativeFile, '', $this->argumentValue($arguments, 'default'));
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
        $this->collectSetupGuideGeneratedKeys($contents, $relativeFile, $keys);
        $this->collectWorkflowSeededTranslationKeys($contents, $relativeFile, $keys);
        $this->collectFrontendProfileGeneratedKeys($contents, $relativeFile, $keys);
        $this->collectFrontendProjectGeneratedKeys($contents, $relativeFile, $keys);
        $this->collectFrontendShellGeneratedKeys($contents, $relativeFile, $keys);
        $this->collectFrontendTaskListGeneratedKeys($contents, $relativeFile, $keys);
        $this->collectIdentityAdminGeneratedKeys($contents, $relativeFile, $keys);
        $this->collectDelegationScopeGeneratedKeys($contents, $relativeFile, $keys);
        $this->collectPlanGeneratedKeys($contents, $relativeFile, $keys);
        $this->collectKnownUnderscoreTranslationKeyLiterals($contents, $relativeFile, $keys);
        $this->collectKeyLikeLiterals($contents, $relativeFile, $keys);
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
        if ($key === '' || str_ends_with($key, '.') || str_ends_with($key, ':')) {
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
        if (!preg_match_all('#(?:->translate|translate[A-Z][A-Za-z0-9_]*|translateBy[A-Za-z0-9_]*|->label|\bt[A-Z][A-Za-z0-9_]*|(?:->|\.)t)\s*\(([^;{}]{0,1000})\)#s', $contents, $calls)) {
            return;
        }

        foreach ($calls[1] as $arguments) {
            if (!preg_match_all('#(["\'])([A-Za-z][A-Za-z0-9_.:-]+)\1#', (string)$arguments, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                $this->addKey($keys, $this->cleanKey((string)$match[2]), $relativeFile, '');
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
            'description',
            'help',
            'label',
            'placeholder',
        ];

        $pattern = '#["\'](?:' . implode('|', array_map(static fn(string $fieldName): string => preg_quote($fieldName, '#'), $fieldNames)) . ')["\']\s*=>\s*(["\'])([A-Za-z0-9_.:-]+)\1#';
        if (!preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $match) {
            $this->addKey($keys, $this->cleanKey((string)$match[2]), $relativeFile, '');
        }
    }

    /**
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $keys
     */
    private function collectSetupGuideGeneratedKeys(string $contents, string $relativeFile, array &$keys): void
    {
        if (!str_ends_with($relativeFile, 'Classes/Service/SetupGuideService.php')) {
            return;
        }
        if (preg_match('#private const STEPS\s*=\s*\[(.*?)^\s*\];#ms', $contents, $match) !== 1) {
            return;
        }

        $stepKeys = [];
        if (preg_match_all("#^\s{8}'([a-z][a-z0-9_]*)'\s*=>\s*\[#m", (string)$match[1], $stepMatches)) {
            $stepKeys = array_values(array_unique($stepMatches[1]));
        }

        $stepSuffixes = [
            'title',
            'description',
            'detail',
            'default_values',
            'recommendation',
            'action_label',
            'action_help',
            'setup_action_label',
            'setup_action_help',
        ];

        foreach ($stepKeys as $stepKey) {
            foreach ($stepSuffixes as $suffix) {
                $this->addKey($keys, 'step_' . $stepKey . '_' . $suffix, $relativeFile, '');
            }
            $this->addKey($keys, 'requirement_' . $stepKey, $relativeFile, '');
            $this->addKey($keys, 'existing_values_' . $stepKey, $relativeFile, '');
            $this->addKey($keys, 'summary_' . $stepKey . '_ready', $relativeFile, '');
            $this->addKey($keys, 'summary_' . $stepKey . '_open', $relativeFile, '');
        }

        $this->addKey($keys, 'summary_finish_ready', $relativeFile, '');
        $this->addKey($keys, 'summary_finish_open', $relativeFile, '');
    }

    /**
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $keys
     */
    private function collectWorkflowSeededTranslationKeys(string $contents, string $relativeFile, array &$keys): void
    {
        if (!str_contains($relativeFile, '/Updates/')) {
            return;
        }

        if (preg_match("#WORKFLOW_KEY\s*=\s*'([A-Za-z0-9_:-]+)'#", $contents, $match) === 1) {
            $this->addKey($keys, 'template_' . $this->cleanKey((string)$match[1]) . '_label', $relativeFile, '');
        }

        if (preg_match('#TEMPLATE_DEFINITIONS\s*=\s*\[(.*?)^\s*\];#ms', $contents, $match) === 1) {
            if (preg_match_all("#^\s{8}'([A-Za-z0-9_:-]+)'\s*=>\s*\[#m", (string)$match[1], $templateMatches)) {
                foreach (array_unique($templateMatches[1]) as $workflowKey) {
                    $this->addKey($keys, 'template_' . $this->cleanKey((string)$workflowKey) . '_label', $relativeFile, '');
                }
            }
        }

        foreach (['PHASES', 'DEVELOPMENT_PHASES'] as $constantName) {
            foreach ($this->stringKeysFromArrayConstant($contents, $constantName) as $phaseKey) {
                $this->addKey($keys, 'phase_' . $phaseKey . '_label', $relativeFile, '');
            }
        }

        foreach (['TRANSITIONS', 'DEVELOPMENT_TRANSITIONS'] as $constantName) {
            foreach ($this->stringKeysFromArrayConstant($contents, $constantName) as $transitionKey) {
                $this->addKey($keys, 'transition_' . $transitionKey . '_label', $relativeFile, '');
            }
        }
    }

    /**
     * @return string[]
     */
    private function stringKeysFromArrayConstant(string $contents, string $constantName): array
    {
        if (preg_match('#private const ' . preg_quote($constantName, '#') . '\s*=\s*\[(.*?)^\s*\];#ms', $contents, $match) !== 1) {
            return [];
        }
        if (!preg_match_all("#'key'\s*=>\s*'([A-Za-z0-9_:-]+)'#", (string)$match[1], $keyMatches)) {
            return [];
        }

        return array_values(array_unique(array_map([$this, 'cleanKey'], $keyMatches[1])));
    }

    /**
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $keys
     */
    private function collectIdentityAdminGeneratedKeys(string $contents, string $relativeFile, array &$keys): void
    {
        if (!str_ends_with($relativeFile, 'Classes/Service/IdentityAdminReadModelService.php')) {
            return;
        }

        foreach (['invited', 'active', 'disabled', 'left', 'anonymized', 'unknown'] as $statusKey) {
            $this->addKey($keys, 'identity_status_' . $statusKey, $relativeFile, '');
        }

        foreach (['be_user', 'fe_user', 'external', 'unknown'] as $sourceType) {
            $this->addKey($keys, 'identity_account_source_' . $sourceType, $relativeFile, '');
        }
    }

    /**
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $keys
     */
    private function collectFrontendProfileGeneratedKeys(string $contents, string $relativeFile, array &$keys): void
    {
        if (!str_ends_with($relativeFile, 'Classes/Service/FrontendProfileReadModelService.php')) {
            return;
        }

        foreach (['system', 'light', 'dark'] as $themeMode) {
            $this->addKey($keys, 'profile.theme.' . $themeMode, $relativeFile, '');
        }
    }

    /**
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $keys
     */
    private function collectFrontendProjectGeneratedKeys(string $contents, string $relativeFile, array &$keys): void
    {
        if (!str_ends_with($relativeFile, 'Classes/Service/FrontendProjectDetailPageReadService.php')) {
            return;
        }

        foreach (['draft', 'active', 'paused', 'completed', 'archived'] as $status) {
            $this->addKey($keys, 'project.status.' . $status, $relativeFile, '');
        }
    }

    /**
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $keys
     */
    private function collectFrontendShellGeneratedKeys(string $contents, string $relativeFile, array &$keys): void
    {
        if (!str_ends_with($relativeFile, 'Classes/Service/FrontendShellReadModelService.php')) {
            return;
        }

        foreach (['state.unmapped', 'state.permission', 'my_tasks.unmapped', 'my_tasks.permission', 'task_list.unmapped', 'task_list.permission'] as $prefix) {
            $this->addKey($keys, $prefix . '.title', $relativeFile, '');
            $this->addKey($keys, $prefix . '.message', $relativeFile, '');
        }
    }

    /**
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $keys
     */
    private function collectFrontendTaskListGeneratedKeys(string $contents, string $relativeFile, array &$keys): void
    {
        if (!str_ends_with($relativeFile, 'Classes/Service/FrontendTaskListService.php')) {
            return;
        }

        foreach (['all', 'project_backlog', 'board_backlog', 'board_work', 'invalid'] as $workspaceFilter) {
            $this->addKey($keys, 'task_list.filter.workspace.' . $workspaceFilter, $relativeFile, '');
        }
    }

    /**
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $keys
     */
    private function collectDelegationScopeGeneratedKeys(string $contents, string $relativeFile, array &$keys): void
    {
        if (!str_ends_with($relativeFile, 'Classes/Service/DelegationAdminReadModelService.php')) {
            return;
        }

        foreach (['global', 'organization', 'project', 'board', 'group', 'task'] as $scopeType) {
            $this->addKey($keys, 'delegation_scope_' . $scopeType, $relativeFile, '');
            $this->addKey($keys, 'delegation_scope_' . $scopeType . '_hint', $relativeFile, '');
        }
    }

    /**
     * @param array<string, array{key: string, sourceFiles: string[], languageFiles: string[], defaultValue: string, defaultValues: string[]}> $keys
     */
    private function collectPlanGeneratedKeys(string $contents, string $relativeFile, array &$keys): void
    {
        if (!str_ends_with($relativeFile, 'Classes/Mapping/PlanMapping.php')) {
            return;
        }

        foreach ([
            'organization_quota_user_identities_active',
            'organization_quota_projects_active',
            'organization_quota_boards_active',
            'organization_quota_tasks_active',
            'organization_quota_storage_mb',
            'organization_quota_status_ok',
            'organization_quota_status_warning',
            'organization_quota_status_blocked',
            'organization_quota_status_exceeded',
        ] as $key) {
            $this->addKey($keys, $key, $relativeFile, '');
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
        if ($key === '' || str_contains($key, '{') || str_contains($key, '}')) {
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

    private function argumentValue(string $arguments, string $name): string
    {
        if (preg_match('#\b' . preg_quote($name, '#') . '\s*:\s*(["\'])(.*?)\1#s', $arguments, $match) === 1) {
            return html_entity_decode((string)$match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }
}
