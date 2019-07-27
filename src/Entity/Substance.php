<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use App\Service\SigmaAldrichSubstanceLoader;
use App\Entity\Statement;
use App\Entity\Symbol;

/**
 * @ORM\Entity(repositoryClass="App\Repository\SubstanceRepository")
 * @ORM\Table(indexes={
 *      @ORM\Index(name="substance_name_idx", columns={"name"}),
 *      @ORM\Index(name="substance_formula_idx", columns={"formula"}),
 *      @ORM\Index(name="substance_cas_idx", columns={"cas_number"}),
 *      @ORM\Index(name="substance_pubchem_idx", columns={"pubchem_id"})
 * })
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

    public function getName(): ?string {
        return $this->name;
    }

    public function setName($name): self {
        $this->name = trim($name, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS);

        return $this;
    }

    public function getFormula(): ?string {
        return $this->formula;
    }

    public function setFormula($formula): self {
        $this->formula = trim($formula, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS);

        return $this;
    }

    public function getPubchemId(): ?int {
        return $this->pubchem_id;
    }

    public function setPubchemId($pubchem_id): self {
        $this->pubchem_id = $pubchem_id;

        return $this;
    }

    public function getCASNumber(): ?string {
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
        $this->symbols = new ArrayCollection();
        foreach ($symbols as $symbol) {
            if ($symbol instanceof Symbol) {
                $this->addSymbol($symbol);
            }
        }

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

    public function getSignalWord(): ?string {
        return $this->signal_word;
    }

    public function setSignalWord($signal_word): self {
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
        $this->statements = new ArrayCollection();
        foreach ($statements as $statement) {
            if ($statement instanceof Statement) {
                $this->addStatement($statement);
            }
        }

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

    public function getRidadr(): ?string {
        return $this->ridadr;
    }

    public function setRidadr($ridadr): self {
        $this->ridadr = trim($ridadr, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS);

        return $this;
    }

    public function getWgkGermany(): ?int {
        return $this->wgk_germany;
    }

    public function setWgkGermany($wgk_germany): self {
        if ($wgk_germany && trim($wgk_germany, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS) !== "") {
            $this->wgk_germany = intval($wgk_germany);
        }

        return $this;
    }

    public function getRtecs(): ?string {
        return $this->rtecs;
    }

    public function setRtecs($rtecs): self {
        $this->rtecs = trim($rtecs, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS);

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
