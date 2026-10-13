<?php

declare(strict_types=1);

/*
 * (c) Tim Bernhard
 */

namespace App\Repository;

use App\Entity\Statement;
use App\Util\StringCleaner;
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

    public function findOrCreate(string $name, ?string $description = null, int $type = Statement::TYPE_UNKNOWN): Statement
    {
        $cleanName = StringCleaner::clean($name);
        $statement = $this->findOneBy(['name' => $cleanName]);
        if (!$statement instanceof Statement) {
            $statement = new Statement();
            $statement->setName($cleanName);
            $statement->setType($type);
            if ($description !== null && $description !== '') {
                $statement->setDescription($description);
            }
            $this->getEntityManager()->persist($statement);
        } elseif ($description !== null && $description !== '' && empty($statement->getDescription())) {
            $statement->setDescription($description);
        }

        return $statement;
    }
}
