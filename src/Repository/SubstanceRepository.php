<?php

namespace App\Repository;

use App\Entity\Substance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use App\Service\SigmaAldrichSubstanceLoader;
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
        $val = trim($search, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS);

        return $this->createQueryBuilder('s')
            ->orWhere('s.cas_number = :val')
            ->orWhere('s.formula = :val')
            ->orWhere('s.name LIKE :val')
            ->orWhere('s.pubchem_id = :val')
            ->setParameter('val', $val)
            ->orderBy('s.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
//    /**
//     * @return Substance[] Returns an array of Substance objects
//     */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('s.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Substance
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
