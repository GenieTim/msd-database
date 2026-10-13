<?php

declare(strict_types=1);

namespace App\Entity;

use App\Util\StringCleaner;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\SymbolRepository::class)]
#[ORM\Table(name: 'symbol')]
#[ORM\UniqueConstraint(name: 'symbol_name_unique', columns: ['name'])]
class Symbol
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::STRING, length: 64, unique: true)]
    private ?string $name = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::STRING, length: 1024, nullable: true)]
    private ?string $description = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = StringCleaner::clean($name);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }
}

