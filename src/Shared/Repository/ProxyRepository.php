<?php

declare(strict_types=1);

namespace App\Shared\Repository;

use App\Shared\Entity\Proxy;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Proxy>
 */
class ProxyRepository extends ServiceEntityRepository
{
    private ManagerRegistry $managerRegistry;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Proxy::class);
        $this->managerRegistry = $registry;
    }

    /** @return Proxy[] */
    public function findAllEnabled(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.isEnabled = true')
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Proxy[] */
    public function findAll(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByAddress(string $address): ?Proxy
    {
        return $this->findOneBy(['address' => $address]);
    }

    public function save(Proxy $proxy): void
    {
        $em = $this->getEntityManager();

        if (!$em->isOpen()) {
            $this->managerRegistry->resetManager();
            $em = $this->managerRegistry->getManagerForClass(Proxy::class);
        }

        $em->persist($proxy);
        $em->flush();
    }

    public function remove(Proxy $proxy): void
    {
        $em = $this->getEntityManager();

        if (!$em->isOpen()) {
            $this->managerRegistry->resetManager();
            $em = $this->managerRegistry->getManagerForClass(Proxy::class);
        }

        $em->remove($proxy);
        $em->flush();
    }
}