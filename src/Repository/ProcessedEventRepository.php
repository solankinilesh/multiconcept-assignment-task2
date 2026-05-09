<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProcessedEvent;
use App\Enum\ProcessedEventStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ProcessedEvent>
 */
final class ProcessedEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcessedEvent::class);
    }

    public function findOneById(Uuid $id): ?ProcessedEvent
    {
        return $this->find($id);
    }

    /**
     * Filter for the operator-facing list command.
     *
     * @return list<ProcessedEvent>
     */
    public function findFiltered(
        ?ProcessedEventStatus $status = null,
        ?string $provider = null,
        ?\DateTimeImmutable $since = null,
        int $limit = 20,
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->orderBy('e.receivedAt', 'DESC')
            ->setMaxResults($limit);

        if (null !== $status) {
            $qb->andWhere('e.status = :status')->setParameter('status', $status);
        }
        if (null !== $provider) {
            $qb->andWhere('e.provider = :provider')->setParameter('provider', $provider);
        }
        if (null !== $since) {
            $qb->andWhere('e.receivedAt >= :since')->setParameter('since', $since);
        }

        /* @var list<ProcessedEvent> */
        return $qb->getQuery()->getResult();
    }
}
