<?php

/*
 * (c) Tim Bernhard
 */

namespace App\Command;

use Fancy\Command\AbstractCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\HttpKernel\KernelInterface;
use Doctrine\ORM\EntityManagerInterface;

class DatabaseDumpCommand extends ContainerAwareCommand {

    /** @var OutputInterface */
    private $output;

    /** @var InputInterface */
    private $input;
    private $database;
    private $username;
    private $password;
    private $path;

    /** filesystem utility */
    private $fs;

    public function __construct(EntityManagerInterface $em, KernelInterface $kernel) {
        parent::__construct();
        $con = $em->getConnection();
        $this->database = $con->getDatabase();
        $this->username = $con->getUsername();
        $this->password = $con->getPassword();
        $this->path = $kernel->getRootDir() . "/../data/database-dump.sql";
    }

    protected function configure() {
        $this->setName('app:database:dump')
                ->setDescription('Dump database.')
                ->addArgument('file', InputArgument::OPTIONAL, 'Absolute path for the file you need to dump database to.', FALSE);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output) {
        $this->output = $output;
        if (($path = $input->getArgument('file'))) {
            $this->path = $path;
        }
        $this->fs = new Filesystem();
        $this->output->writeln(sprintf('<comment>Dumping <fg=green>%s</fg=green> to <fg=green>%s</fg=green> </comment>', $this->database, $this->path));
        $this->createDirectoryIfRequired();
        $this->dumpDatabase();
        $output->writeln('<comment>All done.</comment>');
    }

    private function createDirectoryIfRequired() {
        if (!$this->fs->exists($this->path)) {
            $this->fs->mkdir(dirname($this->path));
        }
    }

    private function dumpDatabase() {
        $cmd = sprintf('mysqldump -B %s -u %s --password=%s' // > %s'
                , $this->database
                , $this->username
                , $this->password
        );

        $result = $this->runCommand($cmd);

        if ($result['exit_status'] > 0) {
            throw new \Exception('Could not dump database: ' . var_export($result['output'], true));
        }

        $this->fs->dumpFile($this->path, $result);
    }

    /**
     * Runs a system command, returns the output, what more do you NEED?
     *
     * @param $command
     * @param $streamOutput
     * @param $outputInterface mixed
     * @return array
     */
    protected function runCommand($command) {
        $command .= " >&1";
        exec($command, $output, $exit_status);
        return array(
            "output" => $output
            , "exit_status" => $exit_status
        );
    }

}
