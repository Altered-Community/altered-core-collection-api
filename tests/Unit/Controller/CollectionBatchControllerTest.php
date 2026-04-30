<?php

namespace App\Tests\Unit\Controller;

use App\Client\AlteredCoreClient;
use App\Controller\CollectionBatchController;
use App\Entity\CollectionCard;
use App\Entity\CollectionCardView;
use App\Entity\User;
use App\Repository\CollectionCardRepository;
use App\Repository\CollectionCardViewRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;

class CollectionBatchControllerTest extends TestCase
{
    private EntityManagerInterface       $em;
    private Security                     $security;
    private AlteredCoreClient            $alteredCoreClient;
    private CollectionCardRepository     $cardRepository;
    private CollectionCardViewRepository $viewRepository;
    private SerializerInterface          $serializer;
    private CollectionBatchController    $controller;
    private User                         $user;

    protected function setUp(): void
    {
        $this->em                = $this->createMock(EntityManagerInterface::class);
        $this->security          = $this->createMock(Security::class);
        $this->alteredCoreClient = $this->createMock(AlteredCoreClient::class);
        $this->cardRepository    = $this->createMock(CollectionCardRepository::class);
        $this->viewRepository    = $this->createMock(CollectionCardViewRepository::class);
        $this->serializer        = $this->createMock(SerializerInterface::class);

        $this->user = $this->createMock(User::class);
        $this->user->method('getId')->willReturn(null);
        $this->security->method('getUser')->willReturn($this->user);
        $this->em->method('getReference')->willReturn($this->user);

        $this->controller = new CollectionBatchController(
            $this->em,
            $this->security,
            $this->alteredCoreClient,
            $this->cardRepository,
            $this->viewRepository,
            $this->serializer,
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function postRequest(array $body, string $locale = 'fr'): Request
    {
        return new Request(['locale' => $locale], [], [], [], [], [], json_encode($body));
    }

    private function patchRequest(array $body): Request
    {
        return new Request([], [], [], [], [], [], json_encode($body));
    }

    private function deleteRequest(array $body): Request
    {
        return new Request([], [], [], [], [], [], json_encode($body));
    }

    // ── POST /api/collection/batch ────────────────────────────────────────────

    public function testImportCreatesNewCards(): void
    {
        $ref  = 'ALT_CORE_B_AX_01_C';
        $body = ['cards' => [['cardReference' => $ref, 'quantity' => 2, 'isFoil' => false]]];

        $this->cardRepository->method('findByReferencesAndUser')->willReturn([]);
        $this->alteredCoreClient->method('getCardsByReferences')->willReturn([
            $ref => ['set' => ['reference' => 'COREKS'], 'faction' => ['code' => 'AX'], 'cardRarity' => ['reference' => 'COMMON']],
        ]);
        $this->em->expects($this->exactly(2))->method('flush');
        $this->serializer->method('serialize')->willReturn('{"created":[],"skipped":[]}');

        $response = $this->controller->import($this->postRequest($body));

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    }

    public function testImportSkipsExistingCards(): void
    {
        $ref          = 'ALT_CORE_B_AX_01_C';
        $existingCard = new CollectionCard();
        $existingCard->setCardReference($ref);

        $this->cardRepository->method('findByReferencesAndUser')->willReturn([$existingCard]);
        $this->alteredCoreClient->expects($this->never())->method('getCardsByReferences');
        $this->em->expects($this->never())->method('flush');

        $response = $this->controller->import($this->postRequest([
            'cards' => [['cardReference' => $ref, 'quantity' => 1, 'isFoil' => false]],
        ]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame([], $data['created']);
        $this->assertContains($ref, $data['skipped']);
    }

    public function testImportPartiallySkipsDuplicates(): void
    {
        $existingRef = 'ALT_CORE_B_AX_01_C';
        $newRef      = 'ALT_CORE_B_OR_02_R';

        $existingCard = new CollectionCard();
        $existingCard->setCardReference($existingRef);

        $this->cardRepository->method('findByReferencesAndUser')->willReturn([$existingCard]);
        $this->alteredCoreClient->expects($this->once())
            ->method('getCardsByReferences')
            ->with([$newRef], 'fr')
            ->willReturn([]);
        $this->em->expects($this->exactly(2))->method('flush');
        $this->serializer->method('serialize')->willReturn('{"created":[],"skipped":[]}');

        $response = $this->controller->import($this->postRequest([
            'cards' => [
                ['cardReference' => $existingRef, 'quantity' => 1, 'isFoil' => false],
                ['cardReference' => $newRef,      'quantity' => 1, 'isFoil' => false],
            ],
        ]));

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
    }

    public function testImportReturns400ForMissingCardsKey(): void
    {
        $response = $this->controller->import($this->postRequest(['other' => []]));
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testImportReturns400ForEmptyCards(): void
    {
        $response = $this->controller->import($this->postRequest(['cards' => []]));
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testImportReturns400ForTooManyCards(): void
    {
        $cards = array_fill(0, 101, ['cardReference' => 'ALT_CORE_B_AX_01_C', 'quantity' => 1, 'isFoil' => false]);

        $response = $this->controller->import($this->postRequest(['cards' => $cards]));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertStringContainsString('100', $response->getContent());
    }

    public function testImportReturns422ForInvalidCardReference(): void
    {
        $response = $this->controller->import($this->postRequest([
            'cards' => [['cardReference' => 'NOT_A_VALID_REF', 'quantity' => 1, 'isFoil' => false]],
        ]));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testImportReturns422ForInvalidQuantity(): void
    {
        $response = $this->controller->import($this->postRequest([
            'cards' => [['cardReference' => 'ALT_CORE_B_AX_01_C', 'quantity' => 200, 'isFoil' => false]],
        ]));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testImportReturns422ForMissingCardReference(): void
    {
        $response = $this->controller->import($this->postRequest([
            'cards' => [['quantity' => 1, 'isFoil' => false]],
        ]));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testImportReturns400ForInvalidJson(): void
    {
        $request = new Request([], [], [], [], [], [], 'not-json');
        $response = $this->controller->import($request);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    // ── PATCH /api/collection/batch ───────────────────────────────────────────

    public function testBulkUpdateModifiesQuantityAndIsFoil(): void
    {
        $card = new CollectionCard();
        $view = $this->createMock(CollectionCardView::class);
        $view->method('getId')->willReturn(1);
        $view->method('getCollectionCard')->willReturn($card);

        $this->viewRepository->method('findByIdsAndUser')->willReturn([$view]);
        $this->em->expects($this->once())->method('flush');

        $response = $this->controller->bulkUpdate($this->patchRequest([
            'updates' => [['id' => 1, 'quantity' => 5, 'isFoil' => true]],
        ]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(1, $data['updated']);
        $this->assertSame(5, $card->getQuantity());
        $this->assertTrue($card->isFoil());
    }

    public function testBulkUpdateSilentlySkipsUnknownIds(): void
    {
        $this->viewRepository->method('findByIdsAndUser')->willReturn([]);
        $this->em->expects($this->never())->method('flush');

        $response = $this->controller->bulkUpdate($this->patchRequest([
            'updates' => [['id' => 999, 'quantity' => 5]],
        ]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(0, $data['updated']);
    }

    public function testBulkUpdateReturns400ForMissingUpdatesKey(): void
    {
        $response = $this->controller->bulkUpdate($this->patchRequest(['other' => []]));
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testBulkUpdateReturns422ForInvalidQuantity(): void
    {
        $response = $this->controller->bulkUpdate($this->patchRequest([
            'updates' => [['id' => 1, 'quantity' => 150]],
        ]));
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    // ── DELETE /api/collection/batch ──────────────────────────────────────────

    public function testBulkDeleteRemovesViewsAndCards(): void
    {
        $card1 = new CollectionCard();
        $card2 = new CollectionCard();

        $view1 = $this->createMock(CollectionCardView::class);
        $view1->method('getCollectionCard')->willReturn($card1);
        $view2 = $this->createMock(CollectionCardView::class);
        $view2->method('getCollectionCard')->willReturn($card2);

        $this->viewRepository->method('findByIdsAndUser')->willReturn([$view1, $view2]);
        $this->em->expects($this->exactly(4))->method('remove');
        $this->em->expects($this->once())->method('flush');

        $response = $this->controller->bulkDelete($this->deleteRequest(['ids' => [1, 2]]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(2, $data['deleted']);
    }

    public function testBulkDeleteSilentlyIgnoresUnknownIds(): void
    {
        $this->viewRepository->method('findByIdsAndUser')->willReturn([]);
        $this->em->expects($this->never())->method('flush');

        $response = $this->controller->bulkDelete($this->deleteRequest(['ids' => [999]]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(0, $data['deleted']);
    }

    public function testBulkDeleteReturns400ForMissingIdsKey(): void
    {
        $response = $this->controller->bulkDelete($this->deleteRequest(['other' => []]));
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testBulkDeleteReturns400ForEmptyIds(): void
    {
        $response = $this->controller->bulkDelete($this->deleteRequest(['ids' => []]));
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testBulkDeleteReturns400ForTooManyIds(): void
    {
        $ids = range(1, 101);
        $response = $this->controller->bulkDelete($this->deleteRequest(['ids' => $ids]));
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testBulkDeleteDeduplicatesIds(): void
    {
        $card = new CollectionCard();
        $view = $this->createMock(CollectionCardView::class);
        $view->method('getCollectionCard')->willReturn($card);

        $this->viewRepository->method('findByIdsAndUser')->willReturn([$view]);
        $this->em->expects($this->once())->method('flush');

        $response = $this->controller->bulkDelete($this->deleteRequest(['ids' => [1, 1, 1]]));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }
}
