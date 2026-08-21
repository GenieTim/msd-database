<?php

declare(strict_types=1);

/*
 * (c) Tim Bernhard
 */

namespace App\Twig;

use App\Entity\Substance;
use App\Entity\Symbol;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * ChemicalExtension provides Twig helpers for chemical structure previews,
 * GHS pictogram URLs/descriptions, and signal word badge styling.
 */
class ChemicalExtension extends AbstractExtension
{
    private const string PUBCHEM_PUG_REST_BASE = 'https://pubchem.ncbi.nlm.nih.gov/rest/pug';
    private const string PUBCHEM_GHS_BASE = 'https://pubchem.ncbi.nlm.nih.gov/images/ghs';
    private const string CHEBI_IMAGE_BASE = 'https://www.ebi.ac.uk/chebi/displayImage.do';

    /**
     * Standard GHS Pictogram definitions and metadata.
     *
     * @var array<string, array{code: string, name: string, description: string, aliases: array<string>}>
     */
    private const array GHS_MAP = [
        'GHS01' => [
            'code' => 'GHS01',
            'name' => 'Exploding Bomb',
            'description' => 'Explosive',
            'aliases' => ['ghs01', 'ghs1', '1', '01', 'explos', 'explosive', 'exploding bomb'],
        ],
        'GHS02' => [
            'code' => 'GHS02',
            'name' => 'Flame',
            'description' => 'Flammable',
            'aliases' => ['ghs02', 'ghs2', '2', '02', 'flamme', 'flame', 'flammable'],
        ],
        'GHS03' => [
            'code' => 'GHS03',
            'name' => 'Flame Over Circle',
            'description' => 'Oxidizing',
            'aliases' => ['ghs03', 'ghs3', '3', '03', 'rondflam', 'flame over circle', 'oxidiz', 'oxidizing'],
        ],
        'GHS04' => [
            'code' => 'GHS04',
            'name' => 'Gas Cylinder',
            'description' => 'Compressed Gas',
            'aliases' => ['ghs04', 'ghs4', '4', '04', 'bottle', 'gas cylinder', 'compressed gas', 'gas'],
        ],
        'GHS05' => [
            'code' => 'GHS05',
            'name' => 'Corrosion',
            'description' => 'Corrosive',
            'aliases' => ['ghs05', 'ghs5', '5', '05', 'acid', 'corrosion', 'corrosive'],
        ],
        'GHS06' => [
            'code' => 'GHS06',
            'name' => 'Skull and Crossbones',
            'description' => 'Toxic',
            'aliases' => ['ghs06', 'ghs6', '6', '06', 'skull', 'skull and crossbones', 'toxic', 'acute toxicity', 'poison'],
        ],
        'GHS07' => [
            'code' => 'GHS07',
            'name' => 'Exclamation Mark',
            'description' => 'Harmful / Irritant',
            'aliases' => ['ghs07', 'ghs7', '7', '07', 'exclam', 'exclamation', 'exclamation mark', 'harmful', 'irritant'],
        ],
        'GHS08' => [
            'code' => 'GHS08',
            'name' => 'Health Hazard',
            'description' => 'Health Hazard',
            'aliases' => ['ghs08', 'ghs8', '8', '08', 'silhouete', 'silhouette', 'health hazard', 'carcinogen', 'mutagen'],
        ],
        'GHS09' => [
            'code' => 'GHS09',
            'name' => 'Environment',
            'description' => 'Environmental Hazard',
            'aliases' => ['ghs09', 'ghs9', '9', '09', 'pollu', 'pollutant', 'environment', 'environmental hazard', 'aquatic toxicity'],
        ],
    ];

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('structure_image_url', [$this, 'getStructureImageUrl']),
            new TwigFunction('substance_structure_url', [$this, 'getStructureImageUrl']),
            new TwigFunction('chebi_structure_url', [$this, 'getChebiStructureImageUrl']),
            new TwigFunction('smiles_structure_url', [$this, 'getSmilesStructureImageUrl']),
            new TwigFunction('ghs_symbol_url', [$this, 'getGhsSymbolUrl']),
            new TwigFunction('ghs_symbol_code', [$this, 'getGhsSymbolCode']),
            new TwigFunction('ghs_symbol_description', [$this, 'getGhsSymbolDescription']),
            new TwigFunction('symbol_badge_class', [$this, 'getSymbolBadgeClass']),
            new TwigFunction('signal_word_badge_class', [$this, 'getSignalWordBadgeClass']),
        ];
    }

    /**
     * @return array<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('structure_image_url', [$this, 'getStructureImageUrl']),
            new TwigFilter('substance_structure_url', [$this, 'getStructureImageUrl']),
            new TwigFilter('chebi_structure_url', [$this, 'getChebiStructureImageUrl']),
            new TwigFilter('smiles_structure_url', [$this, 'getSmilesStructureImageUrl']),
            new TwigFilter('ghs_symbol_url', [$this, 'getGhsSymbolUrl']),
            new TwigFilter('ghs_symbol_code', [$this, 'getGhsSymbolCode']),
            new TwigFilter('ghs_symbol_description', [$this, 'getGhsSymbolDescription']),
            new TwigFilter('symbol_badge_class', [$this, 'getSymbolBadgeClass']),
            new TwigFilter('signal_word_badge_class', [$this, 'getSignalWordBadgeClass']),
        ];
    }

    public function getSymbolBadgeClass(Symbol|string|null $symbol): string
    {
        $code = $this->getGhsSymbolCode($symbol);
        return match ($code) {
            'GHS01', 'GHS02', 'GHS03' => 'badge-danger bg-danger text-white',
            'GHS06', 'GHS08' => 'badge-dark bg-dark text-white',
            'GHS05' => 'badge-warning bg-warning text-dark',
            'GHS07', 'GHS09' => 'badge-info bg-info text-dark',
            default => 'badge-secondary bg-secondary text-white',
        };
    }

    public function getStructureUrl(Substance|string|int|null $input, int|string $size = '300x300'): ?string
    {
        return $this->getStructureImageUrl($input, $size);
    }

    /**
     * Returns a 2D chemical structure image URL for a Substance entity, PubChem CID, ChEBI ID, SMILES, or CAS number.
     */
    public function getStructureImageUrl(Substance|string|int|null $input, int|string $size = '300x300'): ?string
    {
        if ($input === null) {
            return null;
        }

        $sizeStr = $this->normalizeSizeString($size);
        $dimension = $this->extractDimension($size);

        if ($input instanceof Substance) {
            return $input->getStructureImageUrl($sizeStr);
        }

        if (is_int($input)) {
            return sprintf('%s/compound/CID/%d/PNG?image_size=%s', self::PUBCHEM_PUG_REST_BASE, $input, $sizeStr);
        }

        $trimmed = trim($input);
        if ($trimmed === '') {
            return null;
        }

        // 1. Digits only -> PubChem CID
        if (ctype_digit($trimmed)) {
            return sprintf('%s/compound/CID/%s/PNG?image_size=%s', self::PUBCHEM_PUG_REST_BASE, $trimmed, $sizeStr);
        }

        // 2. ChEBI ID (e.g., CHEBI:15377, CHEBI_15377)
        if (preg_match('/^CHEBI[:_]?(\d+)$/i', $trimmed, $matches)) {
            return $this->getChebiStructureImageUrl($matches[1], $dimension);
        }

        // 3. CAS Number (e.g., 64-17-5)
        if (preg_match('/^\d{2,7}-\d{2}-\d$/', $trimmed)) {
            return sprintf('%s/compound/name/%s/PNG?image_size=%s', self::PUBCHEM_PUG_REST_BASE, rawurlencode($trimmed), $sizeStr);
        }

        // 4. Fallback to SMILES representation
        return $this->getSmilesStructureImageUrl($trimmed, $sizeStr);
    }

    /**
     * Generate ChEBI 2D structure image URL.
     */
    public function getChebiStructureImageUrl(string $chebiId, int|string $dimensions = 300): string
    {
        $id = preg_replace('/^CHEBI[:_]?/i', '', trim($chebiId));
        $dim = $this->extractDimension($dimensions);

        return sprintf('%s?defaultImage=true&imageIndex=0&chebiId=CHEBI:%s&dimensions=%s', self::CHEBI_IMAGE_BASE, rawurlencode((string) $id), $dim);
    }

    /**
     * Generate PubChem SMILES 2D structure image URL.
     */
    public function getSmilesStructureImageUrl(string $smiles, int|string $size = '300x300'): string
    {
        $sizeStr = $this->normalizeSizeString($size);

        return sprintf('%s/compound/smiles/%s/PNG?image_size=%s', self::PUBCHEM_PUG_REST_BASE, rawurlencode(trim($smiles)), $sizeStr);
    }

    /**
     * Returns the SVG URL for a GHS symbol / pictogram.
     */
    public function getGhsSymbolUrl(Symbol|string|null $symbol): ?string
    {
        $code = $this->getGhsSymbolCode($symbol);
        if ($code === null) {
            return null;
        }

        return sprintf('%s/%s.svg', self::PUBCHEM_GHS_BASE, $code);
    }

    /**
     * Returns canonical GHS code (e.g., "GHS02") for a Symbol entity or string.
     */
    public function getGhsSymbolCode(Symbol|string|null $symbol): ?string
    {
        if ($symbol === null) {
            return null;
        }

        $name = $symbol instanceof Symbol ? (string) $symbol->getName() : (string) $symbol;
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        // Check exact match (e.g. GHS02, GHS07)
        $upper = strtoupper($name);
        if (isset(self::GHS_MAP[$upper])) {
            return self::GHS_MAP[$upper]['code'];
        }

        // Search aliases
        $lower = strtolower($name);
        foreach (self::GHS_MAP as $ghs) {
            foreach ($ghs['aliases'] as $alias) {
                if ($alias === $lower || str_contains($lower, $alias)) {
                    return $ghs['code'];
                }
            }
        }

        // Check regex for GHS0[1-9] pattern within string
        if (preg_match('/GHS0[1-9]/i', $name, $matches)) {
            $code = strtoupper($matches[0]);
            if (isset(self::GHS_MAP[$code])) {
                return self::GHS_MAP[$code]['code'];
            }
        }

        return null;
    }

    /**
     * Returns human-readable description for a GHS symbol.
     */
    public function getGhsSymbolDescription(Symbol|string|null $symbol): ?string
    {
        if ($symbol instanceof Symbol && $symbol->getDescription() !== null && trim($symbol->getDescription()) !== '') {
            return trim($symbol->getDescription());
        }

        $code = $this->getGhsSymbolCode($symbol);
        if ($code !== null && isset(self::GHS_MAP[$code])) {
            return self::GHS_MAP[$code]['description'];
        }

        if ($symbol instanceof Symbol) {
            return $symbol->getName();
        }

        return $symbol !== null && trim((string) $symbol) !== '' ? trim((string) $symbol) : null;
    }

    /**
     * Returns color-coded Bootstrap badge class for GHS Signal Word:
     * - "Danger" -> Red badge ("badge bg-danger")
     * - "Warning" -> Yellow/Orange badge ("badge bg-warning text-dark")
     * - Other -> Secondary badge ("badge bg-secondary")
     */
    public function getSignalWordBadgeClass(?string $signalWord): string
    {
        if ($signalWord === null) {
            return 'badge bg-secondary';
        }

        $trimmed = strtolower(trim($signalWord));
        if ($trimmed === 'danger' || str_contains($trimmed, 'danger') || str_contains($trimmed, 'gefahr')) {
            return 'badge bg-danger';
        }

        if ($trimmed === 'warning' || str_contains($trimmed, 'warning') || str_contains($trimmed, 'achtung')) {
            return 'badge bg-warning text-dark';
        }

        return 'badge bg-secondary';
    }

    private function normalizeSizeString(int|string $size): string
    {
        if (is_int($size)) {
            return sprintf('%dx%d', $size, $size);
        }

        $str = trim($size);
        if (str_contains($str, 'x')) {
            return $str;
        }

        if (ctype_digit($str)) {
            return sprintf('%sx%s', $str, $str);
        }

        return '300x300';
    }

    private function extractDimension(int|string $size): string
    {
        if (is_int($size)) {
            return (string) $size;
        }

        $str = trim($size);
        if (preg_match('/^(\d+)/', $str, $m)) {
            return $m[1];
        }

        return '300';
    }
}
