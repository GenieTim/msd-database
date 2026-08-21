<?php

declare(strict_types=1);

/*
 * (c) Tim Bernhard
 */

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:database:dump',
    description: 'Dump database into SQL file'
)]
class DatabaseDumpCommand extends Command
{
    private readonly string $database;
    private readonly string $username;
    private readonly string $password;
    private string $path;
    private readonly Filesystem $fs;

    public function __construct(EntityManagerInterface $em, KernelInterface $kernel)
    {
        parent::__construct();
        $con = $em->getConnection();
        $params = $con->getParams();
        $this->database = (string) ($params['dbname'] ?? '');
        $this->username = (string) ($params['user'] ?? '');
        $this->password = (string) ($params['password'] ?? '');
        $this->path = rtrim($kernel->getProjectDir(), '/') . '/data/database-dump.sql';
        $this->fs = new Filesystem();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'Absolute path for the file you need to dump database to.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filePath = $input->getArgument('file');
        if ($filePath) {
            $this->path = (string) $filePath;
        }

        $output->writeln(sprintf('<comment>Dumping <fg=green>%s</fg=green> to <fg=green>%s</fg=green> </comment>', $this->database, $this->path));
        $this->createDirectoryIfRequired();
        $this->dumpDatabase();
        $output->writeln('<comment>All done.</comment>');

        return Command::SUCCESS;
    }

    private function createDirectoryIfRequired(): void
    {
        if (!$this->fs->exists($this->path)) {
            $this->fs->mkdir(dirname($this->path));
        }
    }

    private function dumpDatabase(): void
    {
        $passwordArg = $this->password !== '' ? sprintf('--password=%s', escapeshellarg($this->password)) : '';
        $cmd = sprintf(
            'mysqldump -B %s -u %s %s',
            escapeshellarg($this->database),
            escapeshellarg($this->username),
            $passwordArg
        );

        $result = $this->runCommand($cmd);

        if ($result['exit_status'] > 0) {
            throw new \RuntimeException('Could not dump database: ' . var_export($result['output'], true));
        }

        $this->fs->dumpFile($this->path, implode("\n", $result['output']));
    }

    /**
     * @return array{output: array<string>, exit_status: int}
     */
    protected function runCommand(string $command): array
    {
        $command .= ' 2>&1';
        exec($command, $output, $exitStatus);
        return [
            'output' => $output,
            'exit_status' => $exitStatus,
        ];
    }
}

