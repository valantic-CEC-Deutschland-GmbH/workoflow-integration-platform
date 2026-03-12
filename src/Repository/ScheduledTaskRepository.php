<?php

namespace App\Repository;

use App\Entity\Organisation;
use App\Entity\ScheduledTask;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledTask>
 */
class ScheduledTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledTask::class);
    }

    /**
     * @return ScheduledTask[]
     */
    public function findByOrganisationAndUser(Organisation $organisation, User $user): array
    {
        return $this->createQueryBuilder('st')
            ->andWhere('st.organisation = :organisation')
            ->andWhere('st.user = :user')
            ->setParameter('organisation', $organisation)
            ->setParameter('user', $user)
            ->orderBy('st.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return ScheduledTask[]
     */
    public function findDueForExecution(\DateTimeInterface $now): array
    {
        return $this->createQueryBuilder('st')
            ->andWhere('st.active = :active')
            ->andWhere('st.frequency != :manual')
            ->andWhere('st.nextExecutionAt <= :now')
            ->setParameter('active', true)
            ->setParameter('manual', 'manual')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    public function findByUuidAndOrganisation(string $uuid, Organisation $organisation): ?ScheduledTask
    {
        return $this->createQueryBuilder('st')
            ->andWhere('st.uuid = :uuid')
            ->andWhere('st.organisation = :organisation')
            ->setParameter('uuid', $uuid)
            ->setParameter('organisation', $organisation)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
