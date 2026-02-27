<?php

namespace App\Repository;

use App\Entity\Organisation;
use App\Entity\Prompt;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Prompt>
 */
class PromptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Prompt::class);
    }

    /**
     * Find prompts for the API endpoint
     * @return Prompt[]
     */
    public function findForApi(
        User $user,
        Organisation $organisation,
        ?string $scope = null,
        ?string $category = null,
        ?string $uuid = null,
        ?string $skill = null,
        ?string $platform = null
    ): array {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.organisation = :organisation')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('organisation', $organisation);

        // If specific UUID requested, return just that prompt
        if ($uuid !== null) {
            $qb->andWhere('p.uuid = :uuid')
                ->setParameter('uuid', $uuid);

            // For personal prompts, ensure user owns it
            $qb->andWhere('(p.scope = :orgScope OR (p.scope = :personalScope AND p.owner = :user))')
                ->setParameter('orgScope', Prompt::SCOPE_ORGANISATION)
                ->setParameter('personalScope', Prompt::SCOPE_PERSONAL)
                ->setParameter('user', $user);

            return $qb->getQuery()->getResult();
        }

        // Filter by scope
        if ($scope === Prompt::SCOPE_PERSONAL) {
            $qb->andWhere('p.scope = :scope')
                ->andWhere('p.owner = :user')
                ->setParameter('scope', Prompt::SCOPE_PERSONAL)
                ->setParameter('user', $user);
        } elseif ($scope === Prompt::SCOPE_ORGANISATION) {
            $qb->andWhere('p.scope = :scope')
                ->setParameter('scope', Prompt::SCOPE_ORGANISATION);
        } else {
            // Both scopes: personal (own) + organisation
            $qb->andWhere('(p.scope = :orgScope OR (p.scope = :personalScope AND p.owner = :user))')
                ->setParameter('orgScope', Prompt::SCOPE_ORGANISATION)
                ->setParameter('personalScope', Prompt::SCOPE_PERSONAL)
                ->setParameter('user', $user);
        }

        // Filter by category
        if ($category !== null) {
            $qb->andWhere('p.category = :category')
                ->setParameter('category', $category);
        }

        // Filter by skill
        if ($skill !== null) {
            $qb->andWhere('p.skill = :skill')
                ->setParameter('skill', $skill);
        }

        // Filter by platform
        if ($platform !== null) {
            $qb->andWhere('p.platform = :platform')
                ->setParameter('platform', $platform);
        }

        return $qb->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find personal prompts for a user within an organisation
     * @return Prompt[]
     */
    public function findPersonalPrompts(
        User $user,
        Organisation $organisation,
        ?string $category = null,
        string $sort = 'newest',
        ?string $skill = null,
        ?string $platform = null
    ): array {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.organisation = :organisation')
            ->andWhere('p.owner = :user')
            ->andWhere('p.scope = :scope')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('organisation', $organisation)
            ->setParameter('user', $user)
            ->setParameter('scope', Prompt::SCOPE_PERSONAL);

        if ($category !== null) {
            $qb->andWhere('p.category = :category')
                ->setParameter('category', $category);
        }

        if ($skill !== null) {
            $qb->andWhere('p.skill = :skill')
                ->setParameter('skill', $skill);
        }

        if ($platform !== null) {
            $qb->andWhere('p.platform = :platform')
                ->setParameter('platform', $platform);
        }

        $this->applySorting($qb, $sort);

        return $qb->getQuery()->getResult();
    }

    /**
     * Find organisation prompts
     * @return Prompt[]
     */
    public function findOrganisationPrompts(
        Organisation $organisation,
        ?string $category = null,
        string $sort = 'newest',
        ?string $skill = null,
        ?string $platform = null
    ): array {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.organisation = :organisation')
            ->andWhere('p.scope = :scope')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('organisation', $organisation)
            ->setParameter('scope', Prompt::SCOPE_ORGANISATION);

        if ($category !== null) {
            $qb->andWhere('p.category = :category')
                ->setParameter('category', $category);
        }

        if ($skill !== null) {
            $qb->andWhere('p.skill = :skill')
                ->setParameter('skill', $skill);
        }

        if ($platform !== null) {
            $qb->andWhere('p.platform = :platform')
                ->setParameter('platform', $platform);
        }

        $this->applySorting($qb, $sort);

        return $qb->getQuery()->getResult();
    }

    /**
     * Find a prompt by UUID (non-deleted)
     */
    public function findByUuid(string $uuid): ?Prompt
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.uuid = :uuid')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find a prompt by UUID including deleted (for admin restore)
     */
    public function findByUuidIncludingDeleted(string $uuid): ?Prompt
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.uuid = :uuid')
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find deleted prompts for admin view
     * @return Prompt[]
     */
    public function findDeleted(Organisation $organisation): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.organisation = :organisation')
            ->andWhere('p.deletedAt IS NOT NULL')
            ->setParameter('organisation', $organisation)
            ->orderBy('p.deletedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count prompts by scope for an organisation
     * @return array<string, int>
     */
    public function countByScope(Organisation $organisation, User $user): array
    {
        $personal = (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.organisation = :organisation')
            ->andWhere('p.owner = :user')
            ->andWhere('p.scope = :scope')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('organisation', $organisation)
            ->setParameter('user', $user)
            ->setParameter('scope', Prompt::SCOPE_PERSONAL)
            ->getQuery()
            ->getSingleScalarResult();

        $org = (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.organisation = :organisation')
            ->andWhere('p.scope = :scope')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('organisation', $organisation)
            ->setParameter('scope', Prompt::SCOPE_ORGANISATION)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'personal' => $personal,
            'organisation' => $org,
        ];
    }

    /**
     * Find a prompt by importKey within an organisation (for upsert logic)
     */
    public function findByImportKeyAndOrganisation(string $importKey, Organisation $organisation): ?Prompt
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.importKey = :importKey')
            ->andWhere('p.organisation = :organisation')
            ->setParameter('importKey', $importKey)
            ->setParameter('organisation', $organisation)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Available sort options
     * @return array<string, string>
     */
    public static function getSortOptions(): array
    {
        return [
            'newest' => 'prompt.sort.newest',
            'oldest' => 'prompt.sort.oldest',
            'popular' => 'prompt.sort.popular',
            'alpha' => 'prompt.sort.alpha',
            'category' => 'prompt.sort.category',
        ];
    }

    /**
     * Apply sorting to query builder
     */
    private function applySorting(\Doctrine\ORM\QueryBuilder $qb, string $sort): void
    {
        if ($sort === 'popular') {
            // Count upvotes via subquery for sorting
            $qb->leftJoin('p.upvotes', 'u')
                ->addSelect('COUNT(u.id) AS HIDDEN upvoteCount')
                ->groupBy('p.id')
                ->orderBy('upvoteCount', 'DESC')
                ->addOrderBy('p.createdAt', 'DESC');
        } else {
            match ($sort) {
                'oldest' => $qb->orderBy('p.createdAt', 'ASC'),
                'alpha' => $qb->orderBy('p.title', 'ASC'),
                'category' => $qb->orderBy('p.category', 'ASC')->addOrderBy('p.title', 'ASC'),
                default => $qb->orderBy('p.createdAt', 'DESC'),
            };
        }
    }

    public function save(Prompt $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Prompt $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
