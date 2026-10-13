<?php

declare(strict_types=1);

/*
 * (c) Tim Bernhard
 */

namespace App\Repository;

use App\Entity\Symbol;
use App\Util\StringCleaner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Symbol>
 */
class SymbolRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Symbol::class);
    }

    public function findOrCreate(string $name, ?string $description = null): Symbol
    {
        $cleanName = StringCleaner::clean($name);
        $symbol = $this->findOneBy(['name' => $cleanName]);
        if (!$symbol instanceof Symbol) {
            $symbol = new Symbol();
            $symbol->setName($cleanName);
            if ($description !== null && $description !== '') {
                $symbol->setDescription($description);
            }
            $this->getEntityManager()->persist($symbol);
        } elseif ($description !== null && $description !== '' && empty($symbol->getDescription())) {
            $symbol->setDescription($description);
        }

        return $symbol;
    }
}
