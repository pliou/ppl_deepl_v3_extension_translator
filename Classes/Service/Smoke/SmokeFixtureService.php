<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service\Smoke;

use TYPO3\CMS\Core\Core\Environment;

final class SmokeFixtureService
{
    /**
     * @return string[]
     */
    public function languageFiles(): array
    {
        return [
            'Resources/Private/Language/locallang.xlf',
            'Resources/Private/Language/de.locallang.xlf',
            'Resources/Private/Language/locallang_db.xlf',
        ];
    }

    public function resolveFixturePath(): string
    {
        $projectRoot = rtrim(str_replace('\\', '/', Environment::getProjectPath()), '/');
        $candidates = [
            $projectRoot . '/typo3conf/ext/ppl_et_issue_fixture',
            $projectRoot . '/packages/ppl_et_issue_fixture',
            $projectRoot . '/extensions/ppl_et_issue_fixture',
            dirname($this->extensionRoot()) . '/ppl_et_issue_fixture',
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate . '/Resources/Private/Language')) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Fixture extension ppl_et_issue_fixture was not found.');
    }

    public function restoreFixture(string $fixturePath, string $artifactRoot): void
    {
        $baselineRoot = $artifactRoot . '/fixture-baseline';
        if (!is_dir($baselineRoot)) {
            $this->snapshot($fixturePath, $baselineRoot);
            return;
        }

        foreach ($this->languageFiles() as $relativeFile) {
            $source = $baselineRoot . '/' . $relativeFile;
            $target = rtrim($fixturePath, '/') . '/' . $relativeFile;
            if (!is_file($source)) {
                continue;
            }
            $targetDirectory = dirname($target);
            if (!is_dir($targetDirectory)) {
                mkdir($targetDirectory, 0775, true);
            }
            copy($source, $target);
        }

        $dbTarget = rtrim($fixturePath, '/') . '/Resources/Private/Language/de.locallang_db.xlf';
        if (is_file($dbTarget)) {
            unlink($dbTarget);
        }
    }

    public function snapshot(string $fixturePath, string $targetRoot): void
    {
        foreach ($this->languageFiles() as $relativeFile) {
            $source = rtrim($fixturePath, '/') . '/' . $relativeFile;
            if (!is_file($source)) {
                continue;
            }
            $target = rtrim($targetRoot, '/') . '/' . $relativeFile;
            $directory = dirname($target);
            if (!is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
            copy($source, $target);
        }
    }

    private function extensionRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
