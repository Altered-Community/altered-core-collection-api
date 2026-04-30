<?php

namespace App\Entity;

use App\Repository\CollectionCardRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CollectionCardRepository::class)]
#[ORM\Table(name: 'collection_card')]
#[ORM\UniqueConstraint(name: 'uq_user_card', fields: ['user', 'cardReference'])]
#[ORM\Index(name: 'idx_collection_user', fields: ['user'])]
class CollectionCard
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'collectionCards')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Regex(
        pattern: '/^ALT_[A-Z0-9]+_[A-Z0-9]+_[A-Z]+_\d+_[A-Z0-9]+(_\d+)?$/',
        message: 'Invalid card reference format. Expected: ALT_CORE_B_AX_01_C',
    )]
    private string $cardReference;

    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    #[Assert\Range(min: 0, max: 99)]
    private int $quantity = 1;

    #[ORM\Column(options: ['default' => false])]
    private bool $isFoil = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this; }

    public function getCardReference(): string { return $this->cardReference; }
    public function setCardReference(string $cardReference): self { $this->cardReference = $cardReference; return $this; }

    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $quantity): self { $this->quantity = $quantity; return $this; }

    public function isFoil(): bool { return $this->isFoil; }
    public function setIsFoil(bool $isFoil): self { $this->isFoil = $isFoil; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }
}
