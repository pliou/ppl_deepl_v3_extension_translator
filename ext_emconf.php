<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'PPL DeepL V3 Extension Translator',
    'description' => 'Backend audit and selected-write helper for TYPO3 extension XLF files using ppl_deepl_v3_requests for DeepL calls.',
    'category' => 'module',
    'author' => 'Pawel Pliousnin',
    'author_email' => 'pliousnin@ppl-ds.com',
    'state' => 'stable',
    'clearCacheOnLoad' => true,
    'version' => '14.3.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.99.99',
            'ppl_deepl_v3_requests' => '14.0.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
