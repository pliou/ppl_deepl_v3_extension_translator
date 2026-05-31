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
            'Resources/Private/Language/de.locallang_db.xlf',
        ];
    }

    /**
     * @return string[]
     */
    public function fixtureFiles(): array
    {
        return [
            ...$this->languageFiles(),
            'Configuration/TCA/Overrides/pages.php',
            'Configuration/TypoScript/setup.typoscript',
            'Classes/Controller/SmokeController.php',
            'Classes/Service/SmokeLabelService.php',
            'Resources/Private/Templates/Smoke/Index.html',
            'Resources/Private/Partials/Smoke/Labels.html',
            'Resources/Public/JavaScript/smoke-labels.js',
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
        $seedRoot = $this->seedRoot($fixturePath);
        if ($seedRoot !== null) {
            $seedBaselineRoot = $artifactRoot . '/fixture-seed-baseline';
            if (!is_dir($seedBaselineRoot)) {
                $this->snapshot($seedRoot, $seedBaselineRoot);
            } else {
                $this->copyFiles($seedBaselineRoot, $seedRoot);
            }
            $this->copyFiles($seedRoot, $fixturePath);
            if (!is_dir($baselineRoot)) {
                $this->snapshot($fixturePath, $baselineRoot);
            }
            return;
        }

        if (!is_dir($baselineRoot)) {
            $this->snapshot($fixturePath, $baselineRoot);
            return;
        }

        $this->copyFiles($baselineRoot, $fixturePath);
    }

    private function copyFiles(string $sourceRoot, string $targetRoot): void
    {
        foreach ($this->fixtureFiles() as $relativeFile) {
            $source = rtrim($sourceRoot, '/') . '/' . $relativeFile;
            $target = rtrim($targetRoot, '/') . '/' . $relativeFile;
            if (!is_file($source)) {
                continue;
            }
            $targetDirectory = dirname($target);
            if (!is_dir($targetDirectory)) {
                mkdir($targetDirectory, 0775, true);
            }
            copy($source, $target);
        }
    }

    public function snapshot(string $fixturePath, string $targetRoot): void
    {
        foreach ($this->fixtureFiles() as $relativeFile) {
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

    private function seedRoot(string $fixturePath): ?string
    {
        $seedRoot = rtrim($fixturePath, '/') . '/Tests/Fixtures/seed';
        if (is_dir($seedRoot)) {
            return $seedRoot;
        }

        return null;
    }
}
