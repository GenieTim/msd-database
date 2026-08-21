<?php

declare(strict_types=1);

/*
 * (c) Tim Bernhard
 */

namespace App\Service;

use App\Entity\Substance;
use App\Repository\SubstanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * ChainSubstanceLoader executes multiple substance loaders in priority order with caching.
 *
 * @author timbernhard
 */
class ChainSubstanceLoader implements SubstanceLoaderInterface
{
    private readonly SubstanceRepository $substanceRepo;

    /**
     * @param iterable<SubstanceLoaderInterface> $loaders
     */
    public function __construct(
        private readonly iterable $loaders,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger
    ) {
        $this->substanceRepo = $em->getRepository(Substance::class);
    }

    public function supports(string $search): bool
    {
        return trim($search, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS) !== '';
    }

    public function loadSubstance(string $search): ?Substance
    {
        $search = trim($search, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS);
        if ($search === '') {
            return null;
        }

        // 1. Check local database first
        $existing = $this->substanceRepo->findByAny($search);
        if ($existing instanceof Substance) {
            return $existing;
        }

        // 2. Iterate through loaders in priority order
        $loadedSubstance = null;
        foreach ($this->loaders as $loader) {
            if ($loader === $this || !$loader->supports($search)) {
                continue;
            }

            try {
                $this->logger->info(sprintf('Querying substance loader %s for: "%s"', get_debug_type($loader), $search));
                $result = $loader->loadSubstance($search);

                if ($result instanceof Substance) {
                    if (!$loadedSubstance instanceof \App\Entity\Substance) {
                        $loadedSubstance = $result;
                    } else {
                        $this->enrichSubstance($loadedSubstance, $result);
                    }

                    // If we have full data (name, statements, symbols, signal word), stop chain
                    if (
                        $loadedSubstance->getSignalWord() !== null &&
                        !$loadedSubstance->getStatements()->isEmpty() &&
                        !$loadedSubstance->getSymbols()->isEmpty()
                    ) {
                        break;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf('Loader %s failed for "%s": %s', get_debug_type($loader), $search, $e->getMessage()), [
                    'exception' => $e,
                ]);
            }
        }

        // 3. Persist new substance to database
        if ($loadedSubstance instanceof Substance) {
            // Check for duplicates before persisting
            $duplicate = null;
            if ($loadedSubstance->getCASNumber()) {
                $duplicate = $this->substanceRepo->findOneBy(['cas_number' => $loadedSubstance->getCASNumber()]);
            }
            if (!$duplicate && $loadedSubstance->getPubchemId()) {
                $duplicate = $this->substanceRepo->findOneBy(['pubchem_id' => $loadedSubstance->getPubchemId()]);
            }

            if ($duplicate instanceof Substance) {
                $this->enrichSubstance($duplicate, $loadedSubstance);
                $this->em->persist($duplicate);
                $this->em->flush();
                $loadedSubstance = $duplicate;
            } else {
                $this->em->persist($loadedSubstance);
                $this->em->flush();
            }
        }

        return $loadedSubstance;
    }

    /**
     * Merge fields from secondary source into target substance if missing.
     */
    private function enrichSubstance(Substance $target, Substance $source): void
    {
        if ($target->getCASNumber() === null && $source->getCASNumber() !== null) {
            $target->setCASNumber($source->getCASNumber());
        }
        if ($target->getFormula() === null && $source->getFormula() !== null) {
            $target->setFormula($source->getFormula());
        }
        if ($target->getPubchemId() === null && $source->getPubchemId() !== null) {
            $target->setPubchemId($source->getPubchemId());
        }
        if ($target->getSignalWord() === null && $source->getSignalWord() !== null) {
            $target->setSignalWord($source->getSignalWord());
        }
        if ($target->getWgkGermany() === null && $source->getWgkGermany() !== null) {
            $target->setWgkGermany($source->getWgkGermany());
        }
        if ($target->getRtecs() === null && $source->getRtecs() !== null) {
            $target->setRtecs($source->getRtecs());
        }
        if ($target->getMolecularWeight() === null && $source->getMolecularWeight() !== null) {
            $target->setMolecularWeight($source->getMolecularWeight());
        }
        if ($target->getSmiles() === null && $source->getSmiles() !== null) {
            $target->setSmiles($source->getSmiles());
        }
        if ($target->getInchi() === null && $source->getInchi() !== null) {
            $target->setInchi($source->getInchi());
        }
        if ($target->getInchikey() === null && $source->getInchikey() !== null) {
            $target->setInchikey($source->getInchikey());
        }
        if ($target->getBoilingPoint() === null && $source->getBoilingPoint() !== null) {
            $target->setBoilingPoint($source->getBoilingPoint());
        }
        if ($target->getMeltingPoint() === null && $source->getMeltingPoint() !== null) {
            $target->setMeltingPoint($source->getMeltingPoint());
        }
        if ($target->getDensity() === null && $source->getDensity() !== null) {
            $target->setDensity($source->getDensity());
        }
        if ($source->getSynonyms() !== null) {
            $mergedSynonyms = array_merge($target->getSynonyms() ?? [], $source->getSynonyms());
            $target->setSynonyms($mergedSynonyms);
        }
        if ($target->getStatements()->isEmpty() && !$source->getStatements()->isEmpty()) {
            foreach ($source->getStatements() as $statement) {
                $target->addStatement($statement);
            }
        }
        if ($target->getSymbols()->isEmpty() && !$source->getSymbols()->isEmpty()) {
            foreach ($source->getSymbols() as $symbol) {
                $target->addSymbol($symbol);
            }
        }
    }
}
