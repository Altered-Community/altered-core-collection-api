<?php

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Entity\CollectionCardView;
use App\Entity\User;
use App\Repository\CollectionCardViewRepository;
use App\State\CollectionProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CollectionProviderTest extends TestCase
{
    private CollectionCardViewRepository $repository;
    private Security $security;
    private CollectionProvider $provider;
    private User $user;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CollectionCardViewRepository::class);
        $this->security   = $this->createMock(Security::class);
        $this->provider   = new CollectionProvider($this->repository, $this->security);

        $this->user = new User();
        $this->security->method('getUser')->willReturn($this->user);
    }

    public function testProvideGetReturnsViewWhenFound(): void
    {
        $view = new CollectionCardView();
        $this->repository->method('findOneByIdAndUser')->with(42, $this->user)->willReturn($view);

        $result = $this->provider->provide(new Get(), ['id' => 42]);

        $this->assertSame($view, $result);
    }

    public function testProvideGetThrowsNotFoundWhenMissing(): void
    {
        $this->repository->method('findOneByIdAndUser')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);

        $this->provider->provide(new Get(), ['id' => 99]);
    }

    public function testProvidePatchReturnsViewWhenFound(): void
    {
        $view = new CollectionCardView();
        $this->repository->method('findOneByIdAndUser')->willReturn($view);

        $result = $this->provider->provide(new Patch(), ['id' => 1]);

        $this->assertSame($view, $result);
    }

    public function testProvidePatchThrowsNotFoundWhenMissing(): void
    {
        $this->repository->method('findOneByIdAndUser')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);

        $this->provider->provide(new Patch(), ['id' => 99]);
    }

    public function testProvideDeleteReturnsView(): void
    {
        $view = new CollectionCardView();
        $this->repository->method('findOneByIdAndUser')->willReturn($view);

        $result = $this->provider->provide(new Delete(), ['id' => 1]);

        $this->assertSame($view, $result);
    }

    public function testProvideGetCollectionPassesFiltersToRepository(): void
    {
        $views   = [new CollectionCardView(), new CollectionCardView()];
        $filters = ['cardSet' => 'COREKS', 'faction' => 'AX'];

        $this->repository
            ->method('findByUserWithFilters')
            ->with($this->user, $filters)
            ->willReturn($views);

        $result = $this->provider->provide(new GetCollection(), [], ['filters' => $filters]);

        $this->assertSame($views, $result);
    }

    public function testProvideGetCollectionWithNoFilters(): void
    {
        $this->repository
            ->method('findByUserWithFilters')
            ->with($this->user, [])
            ->willReturn([]);

        $result = $this->provider->provide(new GetCollection(), []);

        $this->assertSame([], $result);
    }
}
