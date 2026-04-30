<?php

namespace App\Controller;

use App\Client\AlteredCoreClient;
use App\Entity\CollectionCard;
use App\Entity\CollectionCardView;
use App\Entity\User;
use App\Repository\CollectionCardRepository;
use App\Repository\CollectionCardViewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api/collection/batch')]
class CollectionBatchController extends AbstractController
{
    private const MAX_BATCH = 100;
    private const CARD_REF_PATTERN = '/^ALT_[A-Z0-9]+_[A-Z0-9]+_[A-Z]+_\d+_[A-Z0-9]+(_\d+)?$/';

    public function __construct(
        private readonly EntityManagerInterface       $em,
        private readonly Security                     $security,
        private readonly AlteredCoreClient            $alteredCoreClient,
        private readonly CollectionCardRepository     $cardRepository,
        private readonly CollectionCardViewRepository $viewRepository,
        private readonly SerializerInterface          $serializer,
    ) {}

    /**
     * POST /api/collection/batch
     *
     * Body: {"cards": [{"cardReference": "ALT_...", "quantity": 1, "isFoil": false}, ...]}
     * Query: ?locale=fr (optional)
     *
     * Returns {"created": [...views...], "skipped": ["ALT_..."]}
     * Cards already in the collection are silently skipped.
     * Metadata is fetched from the Altered API in a single batch call.
     */
    #[Route('', methods: ['POST'])]
    public function import(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);

        if (!is_array($body)) {
            return $this->error('Invalid JSON body.');
        }

        $cards  = $body['cards'] ?? null;
        $locale = $request->query->get('locale', 'fr');

        if (!is_array($cards) || empty($cards)) {
            return $this->error('"cards" must be a non-empty array.');
        }

        if (count($cards) > self::MAX_BATCH) {
            return $this->error(sprintf('Maximum %d cards per batch.', self::MAX_BATCH));
        }

        foreach ($cards as $i => $card) {
            if (empty($card['cardReference']) || !is_string($card['cardReference'])) {
                return $this->error('"cardReference" (string) is required.', $i, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if (!preg_match(self::CARD_REF_PATTERN, $card['cardReference'])) {
                return $this->error(
                    'Invalid cardReference format. Expected: ALT_CORE_B_AX_01_C',
                    $i, Response::HTTP_UNPROCESSABLE_ENTITY, $card['cardReference'],
                );
            }
            if (isset($card['quantity']) && (!is_int($card['quantity']) || $card['quantity'] < 0 || $card['quantity'] > 99)) {
                return $this->error('"quantity" must be an integer between 0 and 99.', $i, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        /** @var User $user */
        $user = $this->security->getUser();

        $inputRefs    = array_column($cards, 'cardReference');
        $existing     = $this->cardRepository->findByReferencesAndUser($inputRefs, $user);
        $existingRefs = array_map(fn(CollectionCard $c) => $c->getCardReference(), $existing);

        $toCreate = array_values(array_filter($cards, fn($c) => !in_array($c['cardReference'], $existingRefs, true)));

        if (empty($toCreate)) {
            return new JsonResponse(['created' => [], 'skipped' => $existingRefs]);
        }

        // Fetch all metadata in one API call
        $newRefs = array_column($toCreate, 'cardReference');
        $apiData = $this->alteredCoreClient->getCardsByReferences($newRefs, $locale);

        // Persist write models first to get auto-increment IDs
        $collectionCards = [];
        foreach ($toCreate as $input) {
            $card = new CollectionCard();
            $card->setUser($this->em->getReference(User::class, $user->getId()));
            $card->setCardReference($input['cardReference']);
            $card->setQuantity($input['quantity'] ?? 1);
            $card->setIsFoil($input['isFoil'] ?? false);
            $this->em->persist($card);
            $collectionCards[$input['cardReference']] = $card;
        }
        $this->em->flush();

        // Persist read models
        $views = [];
        foreach ($toCreate as $input) {
            $ref  = $input['cardReference'];
            $view = new CollectionCardView();
            $view->setCardReference($ref);
            $view->setQuantity($input['quantity'] ?? 1);
            $view->setIsFoil($input['isFoil'] ?? false);
            $view->fillFromApiData($apiData[$ref] ?? [], $locale);
            $view->setCollectionCard($collectionCards[$ref]);
            $view->setUser($this->em->getReference(User::class, $user->getId()));
            $this->em->persist($view);
            $views[] = $view;
        }
        $this->em->flush();

        $json = $this->serializer->serialize(
            ['created' => $views, 'skipped' => $existingRefs],
            'json',
            ['groups' => ['collection:read']],
        );

        return new JsonResponse($json, Response::HTTP_CREATED, [], true);
    }

    /**
     * PATCH /api/collection/batch
     *
     * Body: {"updates": [{"id": 1, "quantity": 5, "isFoil": true}, ...]}
     *
     * Returns {"updated": N}
     * IDs that don't exist or belong to another user are silently skipped.
     */
    #[Route('', methods: ['PATCH'])]
    public function bulkUpdate(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);

        if (!is_array($body)) {
            return $this->error('Invalid JSON body.');
        }

        $updates = $body['updates'] ?? null;

        if (!is_array($updates) || empty($updates)) {
            return $this->error('"updates" must be a non-empty array.');
        }

        if (count($updates) > self::MAX_BATCH) {
            return $this->error(sprintf('Maximum %d updates per batch.', self::MAX_BATCH));
        }

        foreach ($updates as $i => $update) {
            if (!isset($update['id']) || !is_int($update['id'])) {
                return $this->error('"id" (integer) is required.', $i, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if (isset($update['quantity']) && (!is_int($update['quantity']) || $update['quantity'] < 0 || $update['quantity'] > 99)) {
                return $this->error('"quantity" must be an integer between 0 and 99.', $i, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        /** @var User $user */
        $user = $this->security->getUser();

        $ids     = array_column($updates, 'id');
        $views   = $this->viewRepository->findByIdsAndUser($ids, $user);
        $viewMap = [];
        foreach ($views as $view) {
            $viewMap[$view->getId()] = $view;
        }

        $now          = new \DateTimeImmutable();
        $updatedCount = 0;

        foreach ($updates as $update) {
            $view = $viewMap[$update['id']] ?? null;
            if ($view === null) {
                continue;
            }

            $card = $view->getCollectionCard();

            if (isset($update['quantity'])) {
                $view->setQuantity($update['quantity']);
                $card->setQuantity($update['quantity']);
            }
            if (isset($update['isFoil'])) {
                $view->setIsFoil((bool) $update['isFoil']);
                $card->setIsFoil((bool) $update['isFoil']);
            }

            $view->setUpdatedAt($now);
            $card->setUpdatedAt($now);
            $updatedCount++;
        }

        if ($updatedCount > 0) {
            $this->em->flush();
        }

        return new JsonResponse(['updated' => $updatedCount]);
    }

    /**
     * DELETE /api/collection/batch
     *
     * Body: {"ids": [1, 2, 3]}
     *
     * Returns {"deleted": N}
     * IDs that don't exist or belong to another user are silently ignored.
     */
    #[Route('', methods: ['DELETE'])]
    public function bulkDelete(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);

        if (!is_array($body)) {
            return $this->error('Invalid JSON body.');
        }

        $ids = $body['ids'] ?? null;

        if (!is_array($ids) || empty($ids)) {
            return $this->error('"ids" must be a non-empty array.');
        }

        if (count($ids) > self::MAX_BATCH) {
            return $this->error(sprintf('Maximum %d ids per batch.', self::MAX_BATCH));
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        /** @var User $user */
        $user  = $this->security->getUser();
        $views = $this->viewRepository->findByIdsAndUser($ids, $user);

        foreach ($views as $view) {
            $this->em->remove($view);
            $this->em->remove($view->getCollectionCard());
        }

        if (!empty($views)) {
            $this->em->flush();
        }

        return new JsonResponse(['deleted' => count($views)]);
    }

    private function error(string $message, ?int $index = null, int $status = Response::HTTP_BAD_REQUEST, ?string $value = null): JsonResponse
    {
        $body = ['error' => $message];
        if ($index !== null) {
            $body['index'] = $index;
        }
        if ($value !== null) {
            $body['value'] = $value;
        }
        return new JsonResponse($body, $status);
    }
}
