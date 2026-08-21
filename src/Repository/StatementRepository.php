<?php

namespace App\Repository;

use App\Entity\Statement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Statement>
 */
class StatementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Statement::class);
    }

    /**
     * @param string|array<string> $search
     * @return Statement[]
     */
    public function getMatching(string|array $search): array
    {
        if (is_array($search)) {
            $codes = $search;
        } else {
            preg_match_all('/([HP]\d{3}[a-zA-Z\+]*(?:\+[HP]\d{3}[a-zA-Z\+]*)*)/i', $search, $matches);
            $codes = $matches[0];
        }

        if ($codes === []) {
            return [];
        }

        return $this->createQueryBuilder('s')
            ->where('s.name IN (:codes)')
            ->setParameter('codes', array_values(array_unique($codes)))
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return Statement[] Returns an array of Statement objects
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
    public function findOneBySomeField($value): ?Statement
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
