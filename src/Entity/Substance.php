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

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::FLOAT, nullable: true)]
    private ?float $molecular_weight = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::STRING, length: 1024, nullable: true)]
    private ?string $smiles = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::STRING, length: 2048, nullable: true)]
    private ?string $inchi = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::STRING, length: 64, nullable: true)]
    private ?string $inchikey = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::STRING, length: 255, nullable: true)]
    private ?string $boiling_point = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::STRING, length: 255, nullable: true)]
    private ?string $melting_point = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::STRING, length: 255, nullable: true)]
    private ?string $density = null;

    /**
     * @var array<string>|null
     */
    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::JSON, nullable: true)]
    private ?array $synonyms = null;

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

    public function getMolecularWeight(): ?float
    {
        return $this->molecular_weight;
    }

    public function setMolecularWeight(?float $molecular_weight): self
    {
        $this->molecular_weight = $molecular_weight;

        return $this;
    }

    public function getSmiles(): ?string
    {
        return $this->smiles;
    }

    public function setSmiles(?string $smiles): self
    {
        $this->smiles = $smiles !== null ? trim($smiles) : null;

        return $this;
    }

    public function getInchi(): ?string
    {
        return $this->inchi;
    }

    public function setInchi(?string $inchi): self
    {
        $this->inchi = $inchi !== null ? trim($inchi) : null;

        return $this;
    }

    public function getInchiKey(): ?string
    {
        return $this->inchikey;
    }

    public function setInchiKey(?string $inchikey): self
    {
        $this->inchikey = $inchikey !== null ? trim($inchikey, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS) : null;

        return $this;
    }

    public function getBoilingPoint(): ?string
    {
        return $this->boiling_point;
    }

    public function setBoilingPoint(?string $boiling_point): self
    {
        $this->boiling_point = $boiling_point !== null ? trim($boiling_point, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS) : null;

        return $this;
    }

    public function getMeltingPoint(): ?string
    {
        return $this->melting_point;
    }

    public function setMeltingPoint(?string $melting_point): self
    {
        $this->melting_point = $melting_point !== null ? trim($melting_point, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS) : null;

        return $this;
    }

    public function getDensity(): ?string
    {
        return $this->density;
    }

    public function setDensity(?string $density): self
    {
        $this->density = $density !== null ? trim($density, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS) : null;

        return $this;
    }

    /**
     * @return array<string>|null
     */
    public function getSynonyms(): ?array
    {
        return $this->synonyms;
    }

    /**
     * @param array<string>|null $synonyms
     */
    public function setSynonyms(?array $synonyms): self
    {
        if ($synonyms !== null) {
            $cleaned = [];
            foreach ($synonyms as $s) {
                $trimmed = trim((string) $s, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS);
                if ($trimmed !== '') {
                    $cleaned[] = $trimmed;
                }
            }
            $this->synonyms = $cleaned !== [] ? array_values(array_unique($cleaned)) : null;
        } else {
            $this->synonyms = null;
        }

        return $this;
    }

    public function addSynonym(string $synonym): self
    {
        $trimmed = trim($synonym, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS);
        if ($trimmed !== '') {
            $this->synonyms ??= [];
            if (!in_array($trimmed, $this->synonyms, true)) {
                $this->synonyms[] = $trimmed;
            }
        }

        return $this;
    }

    /**
     * Helper to return 2D chemical structure image URL.
     */
    public function getStructureImageUrl(int|string $size = '300x300'): ?string
    {
        $sizeStr = is_int($size) ? sprintf('%dx%d', $size, $size) : (str_contains((string) $size, 'x') ? (string) $size : sprintf('%sx%s', $size, $size));
        $dimension = is_int($size) ? (string) $size : (preg_match('/^(\d+)/', (string) $size, $m) ? $m[1] : '300');

        if ($this->pubchem_id !== null) {
            return sprintf('https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/CID/%d/PNG?image_size=%s', $this->pubchem_id, $sizeStr);
        }

        if ($this->smiles !== null && trim($this->smiles) !== '') {
            return sprintf('https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/smiles/%s/PNG?image_size=%s', rawurlencode(trim($this->smiles)), $sizeStr);
        }

        if ($this->source !== null && preg_match('/CHEBI[:_]?(\d+)/i', $this->source, $matches)) {
            return sprintf('https://www.ebi.ac.uk/chebi/displayImage.do?defaultImage=true&imageIndex=0&chebiId=CHEBI:%s&dimensions=%s', $matches[1], $dimension);
        }

        if ($this->cas_number !== null && trim($this->cas_number) !== '') {
            return sprintf('https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/name/%s/PNG?image_size=%s', rawurlencode(trim($this->cas_number)), $sizeStr);
        }

        return null;
    }
}
