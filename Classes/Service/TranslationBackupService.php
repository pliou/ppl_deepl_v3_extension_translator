<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\WriteOperation;

final class TranslationBackupService
{
    private const BACKUP_DIRECTORY = 'ppl_deepl_v3_extension_translator/backups';

    public function __construct(
        private readonly ExtensionPathResolver $pathResolver
    ) {}

    public function buildPreviewRoot(): string
    {
        return $this->pathResolver->getVarPath() . '/' . self::BACKUP_DIRECTORY . '/' . date('Ymd-His');
    }

    /**
     * @param WriteOperation[] $operations
     */
    public function createBackups(array $operations): string
    {
        $backupRoot = $this->buildPreviewRoot();
        $projectRoot = $this->pathResolver->getProjectRoot();
        $backedUpFiles = [];

        foreach ($operations as $operation) {
            if (isset($backedUpFiles[$operation->absoluteLanguageFile])) {
                continue;
            }

            if ($operation->operationType === 'create_file' && !is_file($operation->absoluteLanguageFile)) {
                $backedUpFiles[$operation->absoluteLanguageFile] = true;
                continue;
            }

            if (!is_file($operation->absoluteLanguageFile)) {
                throw new \RuntimeException('Cannot create backup for missing file: ' . $operation->languageFile);
            }

            $relativePath = $this->makeRelativePath($operation->absoluteLanguageFile, $projectRoot);
            $targetPath = $backupRoot . '/' . $relativePath;
            $targetDirectory = dirname($targetPath);
            if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
                throw new \RuntimeException('Could not create backup directory: ' . $targetDirectory);
            }

            if (!copy($operation->absoluteLanguageFile, $targetPath)) {
                throw new \RuntimeException('Could not create backup for: ' . $operation->languageFile);
            }

            $backedUpFiles[$operation->absoluteLanguageFile] = true;
        }

        return $backupRoot;
    }

    private function makeRelativePath(string $absolutePath, string $projectRoot): string
    {
        $absolutePath = str_replace('\\', '/', $absolutePath);
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');

        if ($absolutePath === $projectRoot || !str_starts_with($absolutePath . '/', $projectRoot . '/')) {
            return ltrim(str_replace(':', '', $absolutePath), '/');
        }

        return ltrim(substr($absolutePath, strlen($projectRoot)), '/');
    }
}
