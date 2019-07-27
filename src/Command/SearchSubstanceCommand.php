<?php

namespace App\Command;

use App\Service\SubstanceLoaderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SearchSubstanceCommand extends Command
{
    protected static $defaultName = 'app:search-substance';

    protected $substanceLoader;

    public function __construct(SubstanceLoaderInterface $substanceLoader)
    {
        $this->substanceLoader = $substanceLoader;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Search in the database. Mostly useable for debugging.')
            ->addArgument('search', InputArgument::REQUIRED, 'The string to search for')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $search = $input->getArgument('search');

        $res = $this->substanceLoader->loadSubstance($search);
        dump($res);
    }
}
