<?php declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'source')]
class Source
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    private string $name;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $nextOffset = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastQueried = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getNextOffset(): int
    {
        return $this->nextOffset;
    }

    public function setNextOffset(int $nextOffset): self
    {
        $this->nextOffset = $nextOffset;

        return $this;
    }

    public function getLastQueried(): ?\DateTimeImmutable
    {
        return $this->lastQueried;
    }

    public function setLastQueried(?\DateTimeImmutable $lastQueried): self
    {
        $this->lastQueried = $lastQueried;

        return $this;
    }
}
