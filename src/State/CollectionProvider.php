<?php

namespace App\State;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProviderInterface;
use App\Entity\CollectionCardView;
use App\Entity\User;
use App\Repository\CollectionCardViewRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly CollectionCardViewRepository $repository,
        private readonly Security                     $security,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        /** @var User $user */
        $user = $this->security->getUser();

        if ($operation instanceof Get || $operation instanceof Patch || $operation instanceof Delete) {
            $view = $this->repository->findOneByIdAndUser((int) ($uriVariables['id'] ?? 0), $user);
            if (!$view) {
                throw new NotFoundHttpException('Collection entry not found.');
            }
            return $view;
        }

        // GetCollection
        return $this->repository->findByUserWithFilters($user, $context['filters'] ?? []);
    }
}
