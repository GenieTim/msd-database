<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SubstanceLoaderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[\Symfony\Component\Console\Attribute\AsCommand(name: 'app:search-substance', description: 'Search in the database. Mostly useable for debugging.')]
class SearchSubstanceCommand extends Command
{
    public function __construct(protected SubstanceLoaderInterface $substanceLoader)
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

        return Command::SUCCESS;
    }
}
