<?php

namespace App\Repository;

use App\Entity\IntegrationConfig;
use App\Entity\Organisation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IntegrationConfig>
 */
class IntegrationConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IntegrationConfig::class);
    }

    /**
     * Find all integration configs for an organisation
     * @return IntegrationConfig[]
     */
    public function findByOrganisation(Organisation $organisation): array
    {
        return $this->createQueryBuilder('ic')
            ->andWhere('ic.organisation = :organisation')
            ->setParameter('organisation', $organisation)
            ->orderBy('ic.integrationType', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find integration configs for an organisation and workflow user
     * @return IntegrationConfig[]
     */
    public function findByOrganisationAndWorkflowUser(Organisation $organisation, ?string $workflowUserId): array
    {
        $qb = $this->createQueryBuilder('ic')
            ->leftJoin('ic.user', 'u')
            ->leftJoin('u.userOrganisations', 'uo')
            ->andWhere('ic.organisation = :organisation')
            ->setParameter('organisation', $organisation);

        if ($workflowUserId !== null) {
            // Filter by workflow_user_id from UserOrganisation (all configs are user-specific)
            $qb->andWhere('uo.workflowUserId = :workflowUserId AND uo.organisation = :organisation')
                ->setParameter('workflowUserId', $workflowUserId);
        }

        return $qb->orderBy('ic.lastAccessedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find a specific integration config
     */
    public function findOneByOrganisationAndType(
        Organisation $organisation,
        string $integrationType,
        ?string $workflowUserId = null,
        ?string $name = null
    ): ?IntegrationConfig {
        $qb = $this->createQueryBuilder('ic')
            ->leftJoin('ic.user', 'u')
            ->leftJoin('u.userOrganisations', 'uo')
            ->andWhere('ic.organisation = :organisation')
            ->andWhere('ic.integrationType = :type')
            ->setParameter('organisation', $organisation)
            ->setParameter('type', $integrationType);

        if ($name !== null) {
            $qb->andWhere('ic.name = :name')
                ->setParameter('name', $name);
        }

        if ($workflowUserId !== null) {
            $qb->andWhere('uo.workflowUserId = :workflowUserId AND uo.organisation = :organisation')
                ->setParameter('workflowUserId', $workflowUserId);
        }
        // Note: If workflowUserId is null, no additional user filter is applied

        return $qb->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get or create integration config
     */
    public function getOrCreate(
        Organisation $organisation,
        string $integrationType,
        ?string $workflowUserId = null,
        ?string $name = null,
        ?\App\Entity\User $user = null
    ): IntegrationConfig {
        $config = $this->findOneByOrganisationAndType($organisation, $integrationType, $workflowUserId, $name);

        if (!$config) {
            $config = new IntegrationConfig();
            $config->setOrganisation($organisation);
            $config->setIntegrationType($integrationType);
            $config->setUser($user);

            // Generate default name if not provided
            if ($name === null) {
                $existingCount = $this->countByOrganisationAndType($organisation, $integrationType);
                $name = ucfirst($integrationType) . ($existingCount > 0 ? ' ' . ($existingCount + 1) : '');
            }
            $config->setName($name);
        }

        return $config;
    }

    /**
     * Update last accessed timestamp
     */
    public function updateLastAccessed(IntegrationConfig $config): void
    {
        $config->setLastAccessedAt(new \DateTime());
        $this->getEntityManager()->persist($config);
        $this->getEntityManager()->flush();
    }

    /**
     * Find active integration configs with credentials
     * @return IntegrationConfig[]
     */
    public function findActiveWithCredentials(Organisation $organisation): array
    {
        return $this->createQueryBuilder('ic')
            ->andWhere('ic.organisation = :organisation')
            ->andWhere('ic.active = true')
            ->andWhere('ic.encryptedCredentials IS NOT NULL')
            ->setParameter('organisation', $organisation)
            ->orderBy('ic.integrationType', 'ASC')
            ->addOrderBy('ic.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all configs by organisation and type
     * @return IntegrationConfig[]
     */
    public function findByOrganisationAndType(
        Organisation $organisation,
        string $integrationType
    ): array {
        return $this->createQueryBuilder('ic')
            ->andWhere('ic.organisation = :organisation')
            ->andWhere('ic.integrationType = :type')
            ->setParameter('organisation', $organisation)
            ->setParameter('type', $integrationType)
            ->orderBy('ic.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find configs by organisation, type and workflow user
     * @return IntegrationConfig[]
     */
    public function findByOrganisationTypeAndWorkflowUser(
        Organisation $organisation,
        string $integrationType,
        ?string $workflowUserId
    ): array {
        $qb = $this->createQueryBuilder('ic')
            ->leftJoin('ic.user', 'u')
            ->leftJoin('u.userOrganisations', 'uo')
            ->andWhere('ic.organisation = :organisation')
            ->andWhere('ic.integrationType = :type')
            ->setParameter('organisation', $organisation)
            ->setParameter('type', $integrationType);

        if ($workflowUserId !== null) {
            // Filter by workflow_user_id from UserOrganisation (all configs are user-specific)
            $qb->andWhere('uo.workflowUserId = :workflowUserId AND uo.organisation = :organisation')
                ->setParameter('workflowUserId', $workflowUserId);
        } else {
            $qb->andWhere('ic.user IS NULL');
        }

        return $qb->orderBy('ic.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find user by workflow_user_id within an organisation
     */
    public function findUserByWorkflowUserId(Organisation $organisation, string $workflowUserId): ?User
    {
        $result = $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->innerJoin('u.userOrganisations', 'uo')
            ->where('uo.organisation = :organisation')
            ->andWhere('uo.workflowUserId = :workflowUserId')
            ->setParameter('organisation', $organisation)
            ->setParameter('workflowUserId', $workflowUserId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Count configs by organisation and type
     */
    private function countByOrganisationAndType(
        Organisation $organisation,
        string $integrationType
    ): int {
        return (int) $this->createQueryBuilder('ic')
            ->select('COUNT(ic.id)')
            ->andWhere('ic.organisation = :organisation')
            ->andWhere('ic.integrationType = :type')
            ->setParameter('organisation', $organisation)
            ->setParameter('type', $integrationType)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
