<?php

namespace App\Tests\Unit\Entity;

use App\Entity\CollectionCardView;
use PHPUnit\Framework\TestCase;

class CollectionCardViewTest extends TestCase
{
    private CollectionCardView $view;

    protected function setUp(): void
    {
        $this->view = new CollectionCardView();
    }

    public function testFillFromApiDataSetsAllFields(): void
    {
        $this->view->fillFromApiData([
            'set'        => ['reference' => 'COREKS'],
            'faction'    => ['code' => 'AX'],
            'cardRarity' => ['reference' => 'COMMON'],
            'name'       => 'Test Card',
            'imagePath'  => '/images/test.jpg',
            'mainCost'   => 3,
            'recallCost' => 2,
            'cardType'   => ['reference' => 'CHARACTER'],
        ]);

        $this->assertSame('COREKS', $this->view->getCardSet());
        $this->assertSame('AX', $this->view->getFaction());
        $this->assertSame('COMMON', $this->view->getRarity());
        $this->assertSame('Test Card', $this->view->getName());
        $this->assertSame('/images/test.jpg', $this->view->getImagePath());
        $this->assertSame(3, $this->view->getMainCost());
        $this->assertSame(2, $this->view->getRecallCost());
        $this->assertSame('CHARACTER', $this->view->getCardType());
    }

    public function testFillFromApiDataWithLocalizedNameUsesRequestedLocale(): void
    {
        $this->view->fillFromApiData([
            'name' => ['fr' => 'Carte Test', 'en' => 'Test Card'],
        ], 'en');

        $this->assertSame('Test Card', $this->view->getName());
    }

    public function testFillFromApiDataWithLocalizedNameFallsBackToFrench(): void
    {
        $this->view->fillFromApiData([
            'name' => ['fr' => 'Carte Test'],
        ], 'de');

        $this->assertSame('Carte Test', $this->view->getName());
    }

    public function testFillFromApiDataWithLocalizedImagePath(): void
    {
        $this->view->fillFromApiData([
            'imagePath' => ['fr' => '/images/fr.jpg', 'en' => '/images/en.jpg'],
        ], 'en');

        $this->assertSame('/images/en.jpg', $this->view->getImagePath());
    }

    public function testFillFromApiDataWithEmptyDataUsesDefaults(): void
    {
        $this->view->fillFromApiData([]);

        $this->assertSame('', $this->view->getCardSet());
        $this->assertSame('', $this->view->getFaction());
        $this->assertSame('', $this->view->getRarity());
        $this->assertNull($this->view->getName());
        $this->assertNull($this->view->getImagePath());
        $this->assertNull($this->view->getMainCost());
        $this->assertNull($this->view->getRecallCost());
        $this->assertNull($this->view->getCardType());
    }

    public function testFillFromApiDataWithStringCardType(): void
    {
        $this->view->fillFromApiData([
            'cardType' => 'SPELL',
        ]);

        $this->assertSame('SPELL', $this->view->getCardType());
    }

    public function testFillFromApiDataCastsStringCostsToInt(): void
    {
        $this->view->fillFromApiData([
            'mainCost'   => '5',
            'recallCost' => '0',
        ]);

        $this->assertSame(5, $this->view->getMainCost());
        $this->assertSame(0, $this->view->getRecallCost());
    }

    public function testDefaultValues(): void
    {
        $this->assertSame(1, $this->view->getQuantity());
        $this->assertFalse($this->view->isFoil());
        $this->assertNull($this->view->getId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $this->view->getCreatedAt());
        $this->assertNull($this->view->getUpdatedAt());
    }
}
