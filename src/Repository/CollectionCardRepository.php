<?php

namespace App\Repository;

use App\Entity\CollectionCard;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CollectionCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CollectionCard::class);
    }

    public function findOneByReferenceAndUser(string $cardReference, User $user): ?CollectionCard
    {
        return $this->findOneBy(['cardReference' => $cardReference, 'user' => $user]);
    }

    /** @return CollectionCard[] */
    public function findByReferencesAndUser(array $references, User $user): array
    {
        if (empty($references)) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->where('c.cardReference IN (:refs)')
            ->andWhere('c.user = :user')
            ->setParameter('refs', $references)
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }
}
