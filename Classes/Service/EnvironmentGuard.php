<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;

final class EnvironmentGuard
{
    private const EXTENSION_KEY = 'ppl_deepl_v3_extension_translator';

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration
    ) {}

    public function isProduction(): bool
    {
        return Environment::getContext()->isProduction();
    }

    public function canWrite(bool $readOnly): bool
    {
        if ($readOnly) {
            return false;
        }

        if (!$this->isProduction()) {
            return true;
        }

        return $this->productionWritesAllowed();
    }

    public function getWriteBlockReason(bool $readOnly): string
    {
        if ($readOnly) {
            return 'Read-only scope';
        }

        if ($this->isProduction() && !$this->productionWritesAllowed()) {
            return 'Production writes are disabled';
        }

        return '';
    }

    public function getSafetyState(): array
    {
        return [
            'environment' => (string)Environment::getContext(),
            'production' => $this->isProduction(),
            'productionWritesAllowed' => $this->productionWritesAllowed(),
        ];
    }

    private function productionWritesAllowed(): bool
    {
        try {
            $settings = $this->extensionConfiguration->get(self::EXTENSION_KEY);
        } catch (\Throwable) {
            return false;
        }

        if (!is_array($settings)) {
            return false;
        }

        return filter_var($settings['allowProductionWrites'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
