<?php

declare(strict_types=1);

/*
 * (c) Tim Bernhard
 */

namespace App\Repository;

use App\Entity\Substance;
use App\Util\StringCleaner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Substance>
 */
class SubstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Substance::class);
    }

    public function findByAny(string $search): ?Substance
    {
        $val = StringCleaner::clean($search);
        if ('' === $val) {
            return null;
        }

        $qb = $this->createQueryBuilder('s')
            ->select('s.id')
            ->orWhere('s.cas_number = :val')
            ->orWhere('s.formula = :val')
            ->orWhere('s.name = :val')
            ->orWhere('s.name LIKE :valPattern')
            ->setParameter('val', $val)
            ->setParameter('valPattern', addcslashes($val, '%_').'%');

        if (ctype_digit($val)) {
            $qb->orWhere('s.pubchem_id = :pubchemId')
                ->setParameter('pubchemId', (int) $val);
        }

        /** @var array{id: int}|null $row */
        $row = $qb->orderBy('s.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (\is_array($row)) {
            /** @var Substance|null $hydrated */
            $hydrated = $this->createQueryBuilder('s')
                ->leftJoin('s.symbols', 'sym')
                ->addSelect('sym')
                ->leftJoin('s.statements', 'stmt')
                ->addSelect('stmt')
                ->where('s.id = :id')
                ->setParameter('id', $row['id'])
                ->getQuery()
                ->getOneOrNullResult();

            return $hydrated;
        }

        return null;
    }
}
