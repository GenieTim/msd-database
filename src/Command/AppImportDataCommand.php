<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Statement;
use App\Repository\StatementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Import H & P statements from JSON files.
 */
#[AsCommand(
    name: 'app:import-data',
    description: 'Import precautionary & hazard statements from data directory'
)]
class AppImportDataCommand extends Command
{
    protected string $importDir = '';

    public function __construct(
        protected EntityManagerInterface $em,
        protected StatementRepository $statementRepo,
        KernelInterface $kernel,
    ) {
        parent::__construct();
        $this->importDir = rtrim($kernel->getProjectDir(), '/').'/data/';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->writeln('Importing H statements');
        $this->importStatements($this->statementRepo, 'hazard-statements.json');

        $io->writeln('Importing P statements');
        $this->importStatements($this->statementRepo, 'precautionary-statements.json');

        $io->success('Data has successfully been imported.');

        return Command::SUCCESS;
    }

    /**
     * Business logic: import the statements from json file.
     */
    protected function importStatements(StatementRepository $statementRepo, string $statementFile): void
    {
        $filePath = $this->importDir.$statementFile;
        if (!file_exists($filePath)) {
            return;
        }

        $content = file_get_contents($filePath);
        if (false === $content) {
            return;
        }

        $statements = json_decode($content, true);
        if (!\is_array($statements)) {
            return;
        }

        foreach ($statements as $key => $statement) {
            $key = (string) $key;
            if ('' === $key) {
                continue;
            }
            $type = match (strtolower(substr($key, 0, 1))) {
                'p' => Statement::TYPE_P,
                'h' => Statement::TYPE_H,
                default => Statement::TYPE_UNKNOWN,
            };
            $stmt = $statementRepo->findOrCreate($key, (string) $statement, $type);
            if ($stmt->getDescription() !== (string) $statement) {
                $stmt->setDescription((string) $statement);
            }
        }

        $this->em->flush();
    }
}
