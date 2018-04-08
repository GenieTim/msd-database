<?php

namespace App\Repository;

use App\Entity\Substance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;
use App\Service\SigmaAldrichSubstanceLoader;

/**
 * @method Substance|null find($id, $lockMode = null, $lockVersion = null)
 * @method Substance|null findOneBy(array $criteria, array $orderBy = null)
 * @method Substance[]    findAll()
 * @method Substance[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SubstanceRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, Substance::class);
    }
    
    public function findByAny($search) //: ?Statement 
        {
        return $this->createQueryBuilder('s')
            ->orWhere('s.cas_number = :val')
                ->orWhere('s.formula = :val')
                ->orWhere('s.name LIKE :val')
                ->orWhere('s.pubchem_id = :val')
            ->setParameter('val', trim($search, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS))
            ->orderBy('s.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
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
