<?php

declare(strict_types=1);

use Ppl\PplDeeplV3ExtensionTranslator\Controller\ExtensionTranslatorController;

return [
    'ppl_deepl_v3_extension_translator' => [
        'parent' => 'ppl_deepl_v3',
        'position' => ['after' => '*'],
        'access' => 'user',
        'path' => '/module/ppl-deepl-v3/extension-translator',
        'iconIdentifier' => 'module-ppl-deepl-v3-extension-translator',
        'labels' => [
            'title' => 'LLL:EXT:ppl_deepl_v3_extension_translator/Resources/Private/Language/locallang.xlf:module.extensionTranslator.title',
            'shortDescription' => 'LLL:EXT:ppl_deepl_v3_extension_translator/Resources/Private/Language/locallang.xlf:module.extensionTranslator.description',
        ],
        'routes' => [
            '_default' => [
                'target' => ExtensionTranslatorController::class . '::handleRequest',
            ],
        ],
    ],
];
