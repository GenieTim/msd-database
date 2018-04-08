<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\SubstanceRepository")
 */
class Substance {

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=1024, nullable=true)
     */
    private $name;

    /**
     * @ORM\Column(type="string", length=1024, nullable=true)
     */
    private $formula;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $pubchem_id;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private $cas_number;

    /**
     * @ORM\ManyToMany(targetEntity="App\Entity\Symbol")
     */
    private $symbols;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $signal_word;

    /**
     * @ORM\ManyToMany(targetEntity="App\Entity\Statement")
     */
    private $statements;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $ridadr;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $wgk_germany;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $rtecs;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $source;

    public function __construct() {
        $this->symbols = new ArrayCollection();
        $this->statements = new ArrayCollection();
    }

    public function getId() {
        return $this->id;
    }

    public function getName(): string {
        return $this->name;
    }

    public function setName(string $name): self {
        $this->name = $name;

        return $this;
    }

    public function getFormula(): string {
        return $this->formula;
    }

    public function setFormula(string $formula): self {
        $this->formula = $formula;

        return $this;
    }

    public function getPubchemId(): int {
        return $this->pubchem_id;
    }

    public function setPubchemId(int $pubchem_id): self {
        $this->pubchem_id = $pubchem_id;

        return $this;
    }

    public function getCASNumber() {
        return $this->cas_number;
    }

    public function setCASNumber($number) {
        $this->cas_number = $number;
        return $this;
    }

    /**
     * @return Collection|Symbol[]
     */
    public function getSymbols(): Collection {
        return $this->symbols;
    }

    public function setSymbols(array $symbols): self {
        $this->symbols = new ArrayColllection($symbols);

        return $this;
    }

    public function addSymbol(Symbol $symboly): self {
        if (!$this->symbols->contains($symboly)) {
            $this->symbols[] = $symboly;
        }

        return $this;
    }

    public function removeSymbol(Symbol $symboly): self {
        if ($this->symbols->contains($symboly)) {
            $this->symbols->removeElement($symboly);
        }

        return $this;
    }

    public function getSignalWord(): string {
        return $this->signal_word;
    }

    public function setSignalWord(string $signal_word): self {
        $this->signal_word = $signal_word;

        return $this;
    }

    /**
     * @return Collection|Statement[]
     */
    public function getStatements(): Collection {
        return $this->statements;
    }

    public function setStatements(array $statements): self {
        $this->statements = new ArrayCollection($statements);

        return $this;
    }

    public function addStatement(Statement $statement): self {
        if (!$this->statements->contains($statement)) {
            $this->statements[] = $statement;
        }

        return $this;
    }

    public function removeStatement(Statement $statement): self {
        if ($this->statements->contains($statement)) {
            $this->statements->removeElement($statement);
        }

        return $this;
    }

    public function getRidadr(): string {
        return $this->ridadr;
    }

    public function setRidadr(string $ridadr): self {
        $this->ridadr = $ridadr;

        return $this;
    }

    public function getWgkGermany(): int {
        return $this->wgk_germany;
    }

    public function setWgkGermany(int $wgk_germany): self {
        $this->wgk_germany = $wgk_germany;

        return $this;
    }

    public function getRtecs(): string {
        return $this->rtecs;
    }

    public function setRtecs(string $rtecs): self {
        $this->rtecs = $rtecs;

        return $this;
    }

    public function getSource() {
        return $this->source;
    }

    public function setSource(string $source): self {
        $this->source = $source;

        return $this;
    }

}
