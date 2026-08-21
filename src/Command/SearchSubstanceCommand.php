<?php

namespace App\Command;

use App\Service\SubstanceLoaderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[\Symfony\Component\Console\Attribute\AsCommand(name: 'app:search-substance', description: 'Search in the database. Mostly useable for debugging.')]
class SearchSubstanceCommand extends Command
{
    public function __construct(protected \App\Service\SubstanceLoaderInterface $substanceLoader)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('search', InputArgument::REQUIRED, 'The string to search for')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        new SymfonyStyle($input, $output);
        $search = $input->getArgument('search');

        $res = $this->substanceLoader->loadSubstance($search);
        dump($res);
        return \Symfony\Component\Console\Command\Command::SUCCESS;
    }
}
