<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Entity\Statement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Import H & P statements from 
 */
class AppImportDataCommand extends ContainerAwareCommand {

    protected static $defaultName = 'app:import-data';
    protected $em;
    protected $importDir = "";

    public function __construct(EntityManagerInterface $em, KernelInterface $kernel) {
        parent::__construct();
        $this->em = $em;
        $this->importDir = $kernel->getRootDir();
        if (substr($this->importDir, -1) !== "/") {
            $this->importDir .= "/";
        }
        $this->importDir .= "../data/";
    }

    protected function configure() {
        $this->setDescription('Import precautionary & hazard statiements');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $io = new SymfonyStyle($input, $output);
        $statementRepo = $this->em->getRepository(Statement::class);
        $io->writeln('Importing H statements');
        $this->importStatements($statementRepo, 'hazard-statements.json');

        $io->writeln('Importing P statements');
        $this->importStatements($statementRepo, 'precautionary-statements.json');
        $io->success('Data has successfully been imported.');
    }

    /**
     * Business logic: import the statements from json file
     * 
     * @param \Doctrine\ORM\EntityRepository $statementRepo
     * @param string $statementFile
     */
    protected function importStatements(\Doctrine\ORM\EntityRepository $statementRepo, $statementFile) {
        $statements = json_decode(file_get_contents($this->importDir . $statementFile), true);

        foreach ($statements as $key => $statement) {
            $old_statement = $statementRepo->findOneBy(array('name' => $key));
            if (!$old_statement && $key) {
                $new_statement = new Statement();
                $new_statement->setName($key);
                $new_statement->setDescription($statement);
                switch (strtolower(substr($key, 0, 1))) {
                    case 'p':
                        $new_statement->setType(Statement::TYPE_P);
                        break;
                    case 'h':
                        $new_statement->setType(Statement::TYPE_H);
                        break;
                    default:
                        $new_statement->setType(Statement::TYPE_UNKNOWN);
                }

                $this->em->persist($new_statement);
            } else if ($old_statement && $old_statement->getDescription() !== $statement) {
                $old_statement->setDescription($statement);
                $this->em->persist($old_statement);
            }
        }
        
        $this->em->flush();
    }

}
