<?php

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Client\AlteredCoreClient;
use App\Entity\CollectionCard;
use App\Entity\CollectionCardView;
use App\Entity\User;
use App\Repository\CollectionCardRepository;
use App\State\CollectionProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class CollectionProcessorTest extends TestCase
{
    private EntityManagerInterface $em;
    private Security $security;
    private AlteredCoreClient $alteredCoreClient;
    private RequestStack $requestStack;
    private CollectionCardRepository $cardRepository;
    private CollectionProcessor $processor;

    protected function setUp(): void
    {
        $this->em                = $this->createMock(EntityManagerInterface::class);
        $this->security          = $this->createMock(Security::class);
        $this->alteredCoreClient = $this->createMock(AlteredCoreClient::class);
        $this->requestStack      = $this->createMock(RequestStack::class);
        $this->cardRepository    = $this->createMock(CollectionCardRepository::class);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(null);
        $this->security->method('getUser')->willReturn($user);
        $this->em->method('getReference')->willReturn($user);

        $this->requestStack->method('getCurrentRequest')
            ->willReturn(new Request(['locale' => 'fr']));

        $this->processor = new CollectionProcessor(
            $this->em,
            $this->security,
            $this->alteredCoreClient,
            $this->requestStack,
            $this->cardRepository,
        );
    }

    public function testProcessDeleteRemovesBothModelsAndReturnsNull(): void
    {
        $collectionCard = new CollectionCard();
        $view           = $this->createMock(CollectionCardView::class);
        $view->method('getCollectionCard')->willReturn($collectionCard);

        $this->em->expects($this->exactly(2))->method('remove');
        $this->em->expects($this->once())->method('flush');

        $result = $this->processor->process($view, new Delete());

        $this->assertNull($result);
    }

    public function testProcessPostCreatesWriteModelFetchesMetadataAndReturnsView(): void
    {
        $ref  = 'ALT_CORE_B_AX_01_C';
        $view = new CollectionCardView();
        $view->setCardReference($ref);
        $view->setQuantity(2);
        $view->setIsFoil(true);

        $this->cardRepository->method('findOneByReferenceAndUser')->willReturn(null);

        $apiData = [
            $ref => [
                'set'       => ['reference' => 'COREKS'],
                'cardGroup' => [
                    'faction' => ['code' => 'AX'],
                    'rarity'  => ['reference' => 'COMMON'],
                    'name'    => 'Yzmir Stargazer',
                ],
            ],
        ];
        $this->alteredCoreClient->expects($this->once())
            ->method('getCardsByReferences')
            ->with([$ref], 'fr')
            ->willReturn($apiData);

        $persistedEntities = [];
        $this->em->method('persist')
            ->willReturnCallback(function (object $entity) use (&$persistedEntities): void {
                $persistedEntities[] = $entity;
            });
        $this->em->expects($this->exactly(2))->method('flush');

        $result = $this->processor->process($view, new Post());

        $this->assertSame($view, $result);
        $this->assertSame('COREKS', $result->getCardSet());
        $this->assertSame('AX', $result->getFaction());
        $this->assertSame('COMMON', $result->getRarity());
        $this->assertSame('Yzmir Stargazer', $result->getName());

        $cards = array_filter($persistedEntities, fn($e) => $e instanceof CollectionCard);
        $this->assertCount(1, $cards);
        $card = array_values($cards)[0];
        $this->assertSame($ref, $card->getCardReference());
        $this->assertSame(2, $card->getQuantity());
        $this->assertTrue($card->isFoil());
    }

    public function testProcessPostThrowsValidationExceptionWhenCardAlreadyInCollection(): void
    {
        $ref  = 'ALT_CORE_B_AX_01_C';
        $view = new CollectionCardView();
        $view->setCardReference($ref);

        $this->cardRepository->method('findOneByReferenceAndUser')->willReturn(new CollectionCard());

        $this->expectException(ValidationException::class);

        $this->processor->process($view, new Post());
    }

    public function testProcessPatchSyncsQuantityAndIsFoilToWriteModel(): void
    {
        $collectionCard = new CollectionCard();

        $view = $this->createMock(CollectionCardView::class);
        $view->method('getId')->willReturn(42);
        $view->method('getCollectionCard')->willReturn($collectionCard);
        $view->method('getQuantity')->willReturn(5);
        $view->method('isFoil')->willReturn(true);

        $this->em->expects($this->once())->method('persist')->with($view);
        $this->em->expects($this->once())->method('flush');

        $result = $this->processor->process($view, new Patch());

        $this->assertSame($view, $result);
        $this->assertSame(5, $collectionCard->getQuantity());
        $this->assertTrue($collectionCard->isFoil());
        $this->assertNotNull($collectionCard->getUpdatedAt());
    }

    public function testProcessPostWithUnknownCardReferenceStillPersists(): void
    {
        $ref  = 'ALT_CORE_B_AX_01_C';
        $view = new CollectionCardView();
        $view->setCardReference($ref);

        $this->cardRepository->method('findOneByReferenceAndUser')->willReturn(null);

        // API returns no data for this reference
        $this->alteredCoreClient->method('getCardsByReferences')->willReturn([]);

        $this->em->expects($this->exactly(2))->method('flush');

        $result = $this->processor->process($view, new Post());

        $this->assertSame($view, $result);
        $this->assertSame('', $result->getCardSet());
        $this->assertNull($result->getName());
    }
}
