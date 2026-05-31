<?php

declare(strict_types=1);

namespace Ppl\PplDeeplV3ExtensionTranslator\Command;

use Ppl\PplDeeplV3ExtensionTranslator\Service\Smoke\SmokeContext;
use Ppl\PplDeeplV3ExtensionTranslator\Service\Smoke\SmokeFixtureService;
use Ppl\PplDeeplV3ExtensionTranslator\Service\Smoke\SmokeMatrixRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Core\Environment;

#[AsCommand(
    name: 'ppl:extension-translator:smoke',
    description: 'Run the Extension Translator issue taxonomy smoke matrix with Fake DeepL.'
)]
final class SmokeCommand extends Command
{
    public function __construct(
        private readonly SmokeContext $context,
        private readonly SmokeFixtureService $fixtureService,
        private readonly SmokeMatrixRunner $matrixRunner
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('reset-fixture', null, InputOption::VALUE_NONE, 'Restore fixture language files before running.')
            ->addOption('run-matrix', null, InputOption::VALUE_NONE, 'Run the smoke matrix.')
            ->addOption('case', null, InputOption::VALUE_REQUIRED, 'Run only one case id, for example SMK-001.')
            ->addOption('artifact-root', null, InputOption::VALUE_REQUIRED, 'Artifact root. Defaults to var/smoke/extension-translator-taxonomy/<timestamp>.')
            ->addOption('keep-fake', null, InputOption::VALUE_NONE, 'Keep the Fake DeepL smoke context active after the command exits.')
            ->addOption('deactivate-fake', null, InputOption::VALUE_NONE, 'Deactivate the persistent Fake DeepL smoke context and exit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ((bool)$input->getOption('deactivate-fake')) {
            $this->context->deactivate();
            $output->writeln('<info>Extension Translator Fake DeepL context deactivated.</info>');

            return Command::SUCCESS;
        }

        $artifactRoot = trim((string)($input->getOption('artifact-root') ?? ''));
        if ($artifactRoot === '') {
            $artifactRoot = Environment::getVarPath() . '/smoke/extension-translator-taxonomy/' . date('Ymd-His');
        }
        $normalizedArtifactRoot = str_replace('\\', '/', $artifactRoot);
        if (!str_starts_with($normalizedArtifactRoot, '/') && preg_match('#^[A-Za-z]:/#', $normalizedArtifactRoot) !== 1) {
            $artifactRoot = Environment::getProjectPath() . '/' . $artifactRoot;
        }
        if (!is_dir($artifactRoot)) {
            mkdir($artifactRoot, 0775, true);
        }

        $fixturePath = $this->fixtureService->resolveFixturePath();
        if ((bool)$input->getOption('reset-fixture')) {
            $this->fixtureService->restoreFixture($fixturePath, $artifactRoot);
            $output->writeln('<info>Fixture restored.</info>');
        }

        $keepFake = (bool)$input->getOption('keep-fake');
        $runMatrix = (bool)$input->getOption('run-matrix');
        if ($runMatrix || $keepFake) {
            $this->context->activate($artifactRoot);
            $output->writeln('<info>Fake DeepL smoke context active.</info>');
        }
        $output->writeln('Fixture: ' . $fixturePath);
        $output->writeln('Artifact root: ' . $artifactRoot);

        try {
            if ($runMatrix) {
                $summary = $this->matrixRunner->runMatrix($fixturePath, $artifactRoot, $input->getOption('case') ? (string)$input->getOption('case') : null);
                $failed = array_filter($summary['cases'], static fn(array $case): bool => $case['status'] !== 'PASS');
                $output->writeln(sprintf(
                    '<info>Smoke matrix finished: %d passed, %d failed.</info>',
                    count($summary['cases']) - count($failed),
                    count($failed)
                ));
                $output->writeln('Summary: ' . $artifactRoot . '/summary.md');

                return $failed === [] ? Command::SUCCESS : Command::FAILURE;
            }

            $output->writeln('<comment>Pass --run-matrix to execute smoke cases.</comment>');
            if ($keepFake) {
                $output->writeln('<comment>Fake DeepL smoke context remains active because --keep-fake was used.</comment>');
            }

            return Command::SUCCESS;
        } finally {
            if ($runMatrix && !$keepFake) {
                $this->context->deactivate();
            }
        }
    }
}
