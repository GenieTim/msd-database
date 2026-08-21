<?php

declare(strict_types=1);

/*
 * (c) Tim Bernhard
 */

namespace App\Command;

use App\Entity\Substance;
use App\Repository\SubstanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:import:wgk',
    description: 'Import German Water Hazard Classes (WGK) from UBA CSV data'
)]
class AppImportWgkCommand extends Command
{
    private readonly string $dataDir;

    public function __construct(
        private readonly EntityManagerInterface $em,
        KernelInterface $kernel
    ) {
        parent::__construct();
        $this->dataDir = rtrim($kernel->getProjectDir(), '/') . '/data/';
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'Path to WGK CSV file (relative to data/ or absolute)', 'wgk-data.csv');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fileArg = (string) $input->getArgument('file');
        $filePath = str_starts_with($fileArg, '/') ? $fileArg : $this->dataDir . $fileArg;

        if (!file_exists($filePath)) {
            $io->note(sprintf('WGK file not found at %s. You can place a UBA Rigoletto CSV export there.', $filePath));
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Importing WGK data from %s', $filePath));
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            $io->error('Unable to open WGK CSV file.');
            return Command::FAILURE;
        }

        $substanceRepo = $this->em->getRepository(Substance::class);
        $updatedCount = 0;
        $rowCount = 0;

        while (($row = fgetcsv($handle, 1000, ';')) !== false) {
            $rowCount++;
            if ($rowCount === 1) {
                continue; // header
            }

            // Expected columns: CAS number, Name, WGK
            $cas = trim($row[0] ?? '');
            $wgkStr = trim($row[2] ?? ($row[1] ?? ''));

            if (!empty($cas) && preg_match('/\b\d{2,7}-\d{2}-\d\b/', $cas)) {
                $substance = $substanceRepo->findOneBy(['cas_number' => $cas]);
                if ($substance instanceof Substance && preg_match('/([1-3])/', $wgkStr, $matches)) {
                    $substance->setWgkGermany((int) $matches[1]);
                    $this->em->persist($substance);
                    $updatedCount++;
                }
            }

            if ($updatedCount > 0 && $updatedCount % 50 === 0) {
                $this->em->flush();
            }
        }

        fclose($handle);
        $this->em->flush();

        $io->success(sprintf('Import complete: updated %d substances with WGK data.', $updatedCount));
        return Command::SUCCESS;
    }
}
