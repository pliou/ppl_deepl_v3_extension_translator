<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use TYPO3\CMS\Core\Core\Environment;

final class EnvironmentGuard
{
    private const EXTENSION_KEY = 'ppl_deepl_v3_extension_translator';

    public function isProduction(): bool
    {
        return Environment::getContext()->isProduction();
    }

    public function allowProductionWrites(): bool
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'][self::EXTENSION_KEY] ?? [];

        return is_array($configuration) && (bool)($configuration['allowProductionWrites'] ?? false);
    }

    public function canWrite(bool $readOnly): bool
    {
        if ($readOnly) {
            return false;
        }

        return !$this->isProduction() || $this->allowProductionWrites();
    }

    public function getWriteBlockReason(bool $readOnly): string
    {
        if ($readOnly) {
            return 'Read-only scope';
        }

        if ($this->isProduction() && !$this->allowProductionWrites()) {
            return 'Production writes are disabled';
        }

        return '';
    }

    public function getSafetyState(): array
    {
        return [
            'environment' => (string)Environment::getContext(),
            'production' => $this->isProduction(),
            'productionWritesAllowed' => $this->allowProductionWrites(),
        ];
    }
}
