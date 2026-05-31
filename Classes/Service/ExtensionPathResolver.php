<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\ScanScope;
use TYPO3\CMS\Core\Core\Environment;

final class ExtensionPathResolver
{
    private const LOCAL_ROOTS = ['packages', 'extensions', 'local', 'typo3conf/ext'];

    public function __construct(
        private readonly EnvironmentGuard $environmentGuard,
        private readonly ExtensionMetadataResolver $extensionMetadataResolver
    ) {}

    public function resolve(string $inputPath): ScanScope
    {
        $inputPath = trim($inputPath) !== '' ? trim($inputPath) : 'typo3conf/ext';
        $normalizedInput = $this->normalizePath($inputPath);
        $projectRoot = $this->getProjectRoot();
        $candidatePath = $this->isAbsolutePath($normalizedInput)
            ? $normalizedInput
            : $projectRoot . '/' . ltrim($normalizedInput, '/');
        $realPath = realpath($candidatePath);

        if ($realPath === false || !is_dir($realPath)) {
            throw new \InvalidArgumentException('The selected scan path does not exist.');
        }

        $absolutePath = $this->normalizePath($realPath);
        if (!$this->isInsidePath($absolutePath, $projectRoot)) {
            throw new \InvalidArgumentException('The selected scan path must stay inside the TYPO3 project root.');
        }

        $relativePath = $this->makeRelativePath($absolutePath, $projectRoot);
        $this->assertAllowedRelativePath($relativePath);

        $vendor = str_starts_with($relativePath . '/', 'vendor/');
        $readOnly = $vendor;
        $writeAllowed = $this->environmentGuard->canWrite($readOnly);

        return new ScanScope(
            $inputPath,
            $absolutePath,
            $relativePath,
            $this->detectExtensionKey($absolutePath),
            $readOnly,
            $vendor,
            $writeAllowed,
            $this->environmentGuard->getWriteBlockReason($readOnly)
        );
    }

    public function getProjectRoot(): string
    {
        return rtrim($this->normalizePath(Environment::getProjectPath()), '/');
    }

    public function getVarPath(): string
    {
        return rtrim($this->normalizePath(Environment::getVarPath()), '/');
    }

    public function getDefaultPath(): string
    {
        foreach (self::LOCAL_ROOTS as $root) {
            $path = $this->getProjectRoot() . '/' . $root;
            if (is_dir($path)) {
                return $root;
            }
        }

        return 'typo3conf/ext';
    }

    /**
     * @return array<int, array{path: string, label: string, group: string, exists: bool, readOnly: bool}>
     */
    public function getScopeOptions(): array
    {
        $projectRoot = $this->getProjectRoot();
        $options = [];

        foreach (self::LOCAL_ROOTS as $root) {
            $options[] = [
                'path' => $root,
                'label' => $root . ' (local root)',
                'group' => 'Local roots',
                'exists' => is_dir($projectRoot . '/' . $root),
                'readOnly' => false,
            ];

            foreach ($this->findLocalExtensionPaths($projectRoot . '/' . $root, $root) as $extensionPath) {
                $absoluteExtensionPath = $projectRoot . '/' . $extensionPath;
                $options[] = [
                    'path' => $extensionPath,
                    'label' => $this->extensionOptionLabel($absoluteExtensionPath, false),
                    'group' => 'Local extensions',
                    'exists' => true,
                    'readOnly' => false,
                ];
            }
        }

        $vendorOptions = [];
        foreach ($this->findVendorPackagePaths($projectRoot . '/vendor') as $vendorPackagePath) {
            $absoluteVendorPackagePath = $projectRoot . '/' . $vendorPackagePath;
            $vendorOptions[] = [
                'path' => $vendorPackagePath,
                'label' => $this->extensionOptionLabel($absoluteVendorPackagePath, true),
                'group' => 'Vendor packages',
                'exists' => true,
                'readOnly' => true,
            ];
        }

        if ($vendorOptions === []) {
            $vendorOptions[] = [
                'path' => 'vendor/<vendor>/<package>',
                'label' => 'vendor/<vendor>/<package> (not found)',
                'group' => 'Vendor packages',
                'exists' => false,
                'readOnly' => true,
            ];
        }

        return array_merge($options, $vendorOptions);
    }

    /**
     * @return string[]
     */
    private function findVendorPackagePaths(string $vendorRoot): array
    {
        if (!is_dir($vendorRoot)) {
            return [];
        }

        $paths = [];
        $vendorEntries = scandir($vendorRoot) ?: [];
        foreach ($vendorEntries as $vendorName) {
            if ($vendorName === '.' || $vendorName === '..' || $vendorName === 'bin') {
                continue;
            }

            $absoluteVendorPath = $vendorRoot . '/' . $vendorName;
            if (!is_dir($absoluteVendorPath)) {
                continue;
            }

            $packageEntries = scandir($absoluteVendorPath) ?: [];
            foreach ($packageEntries as $packageName) {
                if ($packageName === '.' || $packageName === '..') {
                    continue;
                }

                $absolutePackagePath = $absoluteVendorPath . '/' . $packageName;
                if (is_dir($absolutePackagePath) && is_file($absolutePackagePath . '/composer.json')) {
                    $paths[] = 'vendor/' . $vendorName . '/' . $packageName;
                }
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * @return string[]
     */
    private function findLocalExtensionPaths(string $absoluteRoot, string $relativeRoot): array
    {
        if (!is_dir($absoluteRoot)) {
            return [];
        }

        $paths = [];
        $entries = scandir($absoluteRoot) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $absolutePath = $absoluteRoot . '/' . $entry;
            if (!is_dir($absolutePath)) {
                continue;
            }

            if (is_file($absolutePath . '/composer.json') || is_dir($absolutePath . '/Resources/Private/Language')) {
                $paths[] = $relativeRoot . '/' . $entry;
            }
        }

        sort($paths);

        return $paths;
    }

    private function assertAllowedRelativePath(string $relativePath): void
    {
        $relativePath = trim($relativePath, '/');

        if ($relativePath === 'vendor' || preg_match('#^vendor/[^/]+$#', $relativePath) === 1) {
            throw new \InvalidArgumentException('Vendor scans must target one concrete vendor package.');
        }

        if (preg_match('#^vendor/[^/]+/[^/]+(?:/.*)?$#', $relativePath) === 1) {
            return;
        }

        foreach (self::LOCAL_ROOTS as $root) {
            if ($relativePath === $root || str_starts_with($relativePath . '/', $root . '/')) {
                return;
            }
        }

        if (!str_contains($relativePath, '/') && $this->looksLikeRootLevelExtension($relativePath)) {
            return;
        }

        throw new \InvalidArgumentException('Only local extension roots or one concrete vendor package can be scanned.');
    }

    private function looksLikeRootLevelExtension(string $relativePath): bool
    {
        $absolutePath = $this->getProjectRoot() . '/' . trim($relativePath, '/');

        return is_dir($absolutePath)
            && (is_file($absolutePath . '/composer.json') || is_dir($absolutePath . '/Resources/Private/Language'));
    }

    private function detectExtensionKey(string $absolutePath): string
    {
        return $this->extensionMetadataResolver->forRoot($absolutePath, basename($absolutePath))['extensionKey'];
    }

    private function extensionOptionLabel(string $absolutePath, bool $readOnly): string
    {
        $metadata = $this->extensionMetadataResolver->forRoot($absolutePath, basename($absolutePath));
        $label = (string)$metadata['displayName'];
        $extensionKey = (string)$metadata['extensionKey'];
        if ($extensionKey !== '') {
            $label .= ' (' . $extensionKey . ')';
        }
        if ($readOnly) {
            $label .= ' (vendor, read-only)';
        }

        return $label;
    }

    private function makeRelativePath(string $absolutePath, string $projectRoot): string
    {
        $relativePath = ltrim(substr($absolutePath, strlen($projectRoot)), '/');

        return $relativePath !== '' ? $relativePath : '.';
    }

    private function isInsidePath(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path . '/', $root . '/');
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path) === 1;
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', rtrim($path, '/\\'));
    }
}
