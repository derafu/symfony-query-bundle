<?php

declare(strict_types=1);

/**
 * Derafu: Symfony Query Bundle - Integrates derafu/query with Symfony.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\QueryBundle;

use Derafu\QueryBundle\DependencyInjection\QueryExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Derafu Query Bundle — Symfony integration for derafu/query.
 *
 * Registers the derafu/query path-based query system as Symfony services:
 * operator manager, filter/path/expression parsers, SQL query builder,
 * Doctrine engine, and bridge condition appliers for Doctrine ORM and DBAL.
 */
final class QueryBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension === null) {
            $this->extension = new QueryExtension();
        }

        return $this->extension ?: null;
    }
}
