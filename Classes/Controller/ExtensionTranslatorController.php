<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Controller;

use Ppl\PplDeeplV3ExtensionTranslator\Service\ExtensionTranslatorWorkflowService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

#[AsController]
final class ExtensionTranslatorController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly PageRenderer $pageRenderer,
        private readonly ExtensionTranslatorWorkflowService $workflowService
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $input = is_array($body) && $body !== [] ? $body : $request->getQueryParams();
        $viewData = $this->workflowService->handle(is_array($input) ? $input : []);

        if (strtoupper($request->getMethod()) === 'POST' && !empty($viewData['redirectAfterWrite'])) {
            $redirectParameters = [
                'module_action' => 'scan',
                'scan_path' => $viewData['formData']['scanPath'],
                'active_tab' => $viewData['activeTab'],
                'ppl_et_notice' => $viewData['redirectNotice'],
                'ppl_et_written_rows' => (string)($viewData['redirectWrittenRows'] ?? 0),
                'ppl_et_affected_files' => (string)($viewData['redirectAffectedFiles'] ?? 0),
            ];
            if (($viewData['selectedLanguageFiles'] ?? []) !== []) {
                $redirectParameters['selected_language_files'] = $viewData['selectedLanguageFiles'];
            }

            return new RedirectResponse(
                (string)$this->uriBuilder->buildUriFromRoute('ppl_deepl_v3_extension_translator', $redirectParameters),
                303
            );
        }

        $this->pageRenderer->addCssFile('EXT:ppl_deepl_v3_extension_translator/Resources/Public/Css/extension-translator.css');
        $this->pageRenderer->addJsFile('EXT:ppl_deepl_v3_extension_translator/Resources/Public/Javascript/extension-translator.js', 'module', true, false, '', true);

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setModuleClass('ppl-extension-translator-module');
        $moduleTemplate->setTitle($this->translate('module.extensionTranslator.title'));
        $moduleTemplate->assignMultiple(array_merge($viewData, [
            'route' => (string)$this->uriBuilder->buildUriFromRoute('ppl_deepl_v3_extension_translator'),
        ]));

        return $moduleTemplate->renderResponse('ExtensionTranslator/Index');
    }

    private function translate(string $key): string
    {
        return LocalizationUtility::translate($key, 'PplDeeplV3ExtensionTranslator') ?? $key;
    }
}
