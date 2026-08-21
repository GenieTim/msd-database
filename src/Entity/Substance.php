<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\SigmaAldrichSubstanceLoader;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\SubstanceRepository::class)]
#[ORM\Table]
#[ORM\Index(name: 'substance_name_idx', columns: ['name'])]
#[ORM\Index(name: 'substance_formula_idx', columns: ['formula'])]
#[ORM\Index(name: 'substance_cas_idx', columns: ['cas_number'])]
#[ORM\Index(name: 'substance_pubchem_idx', columns: ['pubchem_id'])]
class Substance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::STRING, length: 1024, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::STRING, length: 1024, nullable: true)]
    private ?string $formula = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::INTEGER, nullable: true)]
    private ?int $pubchem_id = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::STRING, nullable: true)]
    private ?string $cas_number = null;

    /**
     * @var Collection<int, Symbol>
     */
    #[ORM\ManyToMany(targetEntity: Symbol::class)]
    private Collection $symbols;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::STRING, length: 255, nullable: true)]
    private ?string $signal_word = null;

    /**
     * @var Collection<int, Statement>
     */
    #[ORM\ManyToMany(targetEntity: Statement::class)]
    private Collection $statements;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::STRING, length: 255, nullable: true)]
    private ?string $ridadr = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::INTEGER, nullable: true)]
    private ?int $wgk_germany = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::STRING, length: 255, nullable: true)]
    private ?string $rtecs = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::STRING, length: 255, nullable: true)]
    private ?string $source = null;

    public function __construct()
    {
        $this->symbols = new ArrayCollection();
        $this->statements = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name !== null ? trim($name, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS) : null;

        return $this;
    }

    public function getFormula(): ?string
    {
        return $this->formula;
    }

    public function setFormula(?string $formula): self
    {
        $this->formula = $formula !== null ? trim($formula, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS) : null;

        return $this;
    }

    public function getPubchemId(): ?int
    {
        return $this->pubchem_id;
    }

    public function setPubchemId(?int $pubchem_id): self
    {
        $this->pubchem_id = $pubchem_id;

        return $this;
    }

    public function getCASNumber(): ?string
    {
        return $this->cas_number;
    }

    public function setCASNumber(?string $number): static
    {
        $this->cas_number = $number;
        return $this;
    }

    /**
     * @return Collection<int, Symbol>
     */
    public function getSymbols(): Collection
    {
        return $this->symbols;
    }

    /**
     * @param array<Symbol> $symbols
     */
    public function setSymbols(array $symbols): self
    {
        $this->symbols = new ArrayCollection();
        foreach ($symbols as $symbol) {
            $this->addSymbol($symbol);
        }

        return $this;
    }

    public function addSymbol(Symbol $symboly): self
    {
        if (!$this->symbols->contains($symboly)) {
            $this->symbols->add($symboly);
        }

        return $this;
    }

    public function removeSymbol(Symbol $symboly): self
    {
        $this->symbols->removeElement($symboly);

        return $this;
    }

    public function getSignalWord(): ?string
    {
        return $this->signal_word;
    }

    public function setSignalWord(?string $signal_word): self
    {
        $this->signal_word = $signal_word;

        return $this;
    }

    /**
     * @return Collection<int, Statement>
     */
    public function getStatements(): Collection
    {
        return $this->statements;
    }

    /**
     * @param array<Statement> $statements
     */
    public function setStatements(array $statements): self
    {
        $this->statements = new ArrayCollection();
        foreach ($statements as $statement) {
            $this->addStatement($statement);
        }

        return $this;
    }

    public function addStatement(Statement $statement): self
    {
        if (!$this->statements->contains($statement)) {
            $this->statements->add($statement);
        }

        return $this;
    }

    public function removeStatement(Statement $statement): self
    {
        $this->statements->removeElement($statement);

        return $this;
    }

    public function getRidadr(): ?string
    {
        return $this->ridadr;
    }

    public function setRidadr(?string $ridadr): self
    {
        $this->ridadr = $ridadr !== null ? trim($ridadr, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS) : null;

        return $this;
    }

    public function getWgkGermany(): ?int
    {
        return $this->wgk_germany;
    }

    public function setWgkGermany(?int $wgk_germany): self
    {
        $this->wgk_germany = $wgk_germany;

        return $this;
    }

    public function getRtecs(): ?string
    {
        return $this->rtecs;
    }

    public function setRtecs(?string $rtecs): self
    {
        $this->rtecs = $rtecs !== null ? trim($rtecs, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS) : null;

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): self
    {
        $this->source = $source;

        return $this;
    }
}

