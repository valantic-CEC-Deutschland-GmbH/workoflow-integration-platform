<?php

namespace App\Repository;

use App\Entity\Organisation;
use App\Entity\ScheduledTaskExecution;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledTaskExecution>
 */
class ScheduledTaskExecutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledTaskExecution::class);
    }

    /**
     * @return ScheduledTaskExecution[]
     */
    public function findByOrganisation(Organisation $organisation, int $limit = 50): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.scheduledTask', 'st')
            ->andWhere('st.organisation = :organisation')
            ->andWhere('e.deletedAt IS NULL')
            ->setParameter('organisation', $organisation)
            ->orderBy('e.executedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return ScheduledTaskExecution[]
     */
    public function findByOrganisationAndUser(Organisation $organisation, User $user, int $limit = 50): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.scheduledTask', 'st')
            ->andWhere('st.organisation = :organisation')
            ->andWhere('st.user = :user')
            ->andWhere('e.deletedAt IS NULL')
            ->setParameter('organisation', $organisation)
            ->setParameter('user', $user)
            ->orderBy('e.executedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
