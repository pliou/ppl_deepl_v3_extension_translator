<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Service;

use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationBatchRequest;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationBatchResult;
use Ppl\PplDeeplV3ExtensionTranslator\Domain\Dto\TranslationCapabilities;

interface TranslationProviderInterface
{
    public function translateBatch(TranslationBatchRequest $request): TranslationBatchResult;

    public function getCapabilities(): TranslationCapabilities;
}
