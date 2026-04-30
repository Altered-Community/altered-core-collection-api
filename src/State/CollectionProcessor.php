<?php

namespace App\State;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Client\AlteredCoreClient;
use App\Entity\CollectionCard;
use App\Entity\CollectionCardView;
use App\Entity\User;
use App\Repository\CollectionCardRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

class CollectionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface    $em,
        private readonly Security                  $security,
        private readonly AlteredCoreClient         $alteredCoreClient,
        private readonly RequestStack              $requestStack,
        private readonly CollectionCardRepository  $cardRepository,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CollectionCardView|null
    {
        /** @var CollectionCardView $data */

        if ($operation instanceof Delete) {
            $collectionCard = $data->getCollectionCard();
            $this->em->remove($data);
            $this->em->remove($collectionCard);
            $this->em->flush();
            return null;
        }

        /** @var User $user */
        $user   = $this->security->getUser();
        $locale = $this->requestStack->getCurrentRequest()?->query->get('locale', 'fr') ?? 'fr';
        $isNew  = $data->getId() === null;

        if ($isNew) {
            $this->guardDuplicate($data->getCardReference(), $user);

            // Create write model
            $collectionCard = new CollectionCard();
            $collectionCard->setUser($this->em->getReference(User::class, $user->getId()));
            $collectionCard->setCardReference($data->getCardReference());
            $collectionCard->setQuantity($data->getQuantity());
            $collectionCard->setIsFoil($data->isFoil());
            $this->em->persist($collectionCard);
            $this->em->flush(); // get auto-generated ID

            // Fetch card metadata from altered-core and populate the view
            $cardData = $this->alteredCoreClient->getCardsByReferences([$data->getCardReference()], $locale);
            $data->fillFromApiData($cardData[$data->getCardReference()] ?? [], $locale);
            $data->setCollectionCard($collectionCard);
            $data->setUser($this->em->getReference(User::class, $user->getId()));
        } else {
            // PATCH — sync quantity / isFoil to write model
            $collectionCard = $data->getCollectionCard();
            $collectionCard->setQuantity($data->getQuantity());
            $collectionCard->setIsFoil($data->isFoil());
            $collectionCard->setUpdatedAt(new \DateTimeImmutable());
            $data->setUpdatedAt(new \DateTimeImmutable());
        }

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }

    private function guardDuplicate(string $cardReference, User $user): void
    {
        if ($this->cardRepository->findOneByReferenceAndUser($cardReference, $user)) {
            $violations = new ConstraintViolationList();
            $violations->add(new ConstraintViolation(
                'This card is already in your collection.',
                'This card is already in your collection.',
                [], null, 'cardReference', $cardReference,
            ));
            throw new ValidationException($violations);
        }
    }
}
