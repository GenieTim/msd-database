<?php

declare(strict_types=1);

/*
 * (c) Tim Bernhard
 */
namespace App\Service;

use App\Entity\Substance;

/**
 * Interface for chemical substance loaders.
 *
 * @author timbernhard
 */
interface SubstanceLoaderInterface
{
    /**
     * Attempts to load or create a Substance entity from this data source.
     */
    public function loadSubstance(string $search): ?Substance;

    /**
     * Whether this loader supports the given search term.
     */
    public function supports(string $search): bool;
}

