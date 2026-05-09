<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ReceivedWebhook;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ReceivedWebhook>
 */
final class ReceivedWebhookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReceivedWebhook::class);
    }

    /**
     * Find the audit row whose processed_event_id FK matches. Querying directly via the
     * FK column (rather than `findOneBy(['processedEvent' => $uuid])`) avoids relying on
     * Doctrine's coercion of a Uuid value into an association lookup, which is fragile
     * across ORM versions.
     */
    public function findOneByProcessedEventId(Uuid $processedEventId): ?ReceivedWebhook
    {
        return $this->createQueryBuilder('w')
            ->andWhere('IDENTITY(w.processedEvent) = :id')
            ->setParameter('id', $processedEventId, 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
