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
        private readonly LoggerInterface $logger,
    ) {
        $this->substanceRepo = $em->getRepository(Substance::class);
    }

    public function supports(string $search): bool
    {
        return '' !== trim($search, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS);
    }

    public function loadSubstance(string $search): ?Substance
    {
        $search = trim($search, SigmaAldrichSubstanceLoader::TRIM_CHARACTERS);
        if ('' === $search) {
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
                $this->logger->info(\sprintf('Querying substance loader %s for: "%s"', get_debug_type($loader), $search));
                $result = $loader->loadSubstance($search);

                if ($result instanceof Substance) {
                    if (!$loadedSubstance instanceof Substance) {
                        $loadedSubstance = $result;
                    } else {
                        $this->enrichSubstance($loadedSubstance, $result);
                    }

                    // If we have full data (name, statements, symbols, signal word), stop chain
                    if (
                        null !== $loadedSubstance->getSignalWord()
                        && !$loadedSubstance->getStatements()->isEmpty()
                        && !$loadedSubstance->getSymbols()->isEmpty()
                    ) {
                        break;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning(\sprintf('Loader %s failed for "%s": %s', get_debug_type($loader), $search, $e->getMessage()), [
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
        if (null === $target->getCASNumber() && null !== $source->getCASNumber()) {
            $target->setCASNumber($source->getCASNumber());
        }
        if (null === $target->getFormula() && null !== $source->getFormula()) {
            $target->setFormula($source->getFormula());
        }
        if (null === $target->getPubchemId() && null !== $source->getPubchemId()) {
            $target->setPubchemId($source->getPubchemId());
        }
        if (null === $target->getSignalWord() && null !== $source->getSignalWord()) {
            $target->setSignalWord($source->getSignalWord());
        }
        if (null === $target->getWgkGermany() && null !== $source->getWgkGermany()) {
            $target->setWgkGermany($source->getWgkGermany());
        }
        if (null === $target->getRtecs() && null !== $source->getRtecs()) {
            $target->setRtecs($source->getRtecs());
        }
        if (null === $target->getMolecularWeight() && null !== $source->getMolecularWeight()) {
            $target->setMolecularWeight($source->getMolecularWeight());
        }
        if (null === $target->getSmiles() && null !== $source->getSmiles()) {
            $target->setSmiles($source->getSmiles());
        }
        if (null === $target->getInchi() && null !== $source->getInchi()) {
            $target->setInchi($source->getInchi());
        }
        if (null === $target->getInchikey() && null !== $source->getInchikey()) {
            $target->setInchikey($source->getInchikey());
        }
        if (null === $target->getBoilingPoint() && null !== $source->getBoilingPoint()) {
            $target->setBoilingPoint($source->getBoilingPoint());
        }
        if (null === $target->getMeltingPoint() && null !== $source->getMeltingPoint()) {
            $target->setMeltingPoint($source->getMeltingPoint());
        }
        if (null === $target->getDensity() && null !== $source->getDensity()) {
            $target->setDensity($source->getDensity());
        }
        if (null !== $source->getSynonyms()) {
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
