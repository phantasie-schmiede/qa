<?php

declare(strict_types=1);

namespace PSBits\Qa\Testing\Support;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

/**
 * Trait PersistenceSupportTrait
 *
 * The standard persistence sequence of the functional tests: the given objects are added to the extbase
 * persistence manager, all changes are persisted, and the state is cleared.
 */
trait PersistenceSupportTrait
{
    protected function persistAll(object ...$objects): void
    {
        $persistenceManager = GeneralUtility::makeInstance(PersistenceManager::class);

        foreach ($objects as $object) {
            $persistenceManager->add($object);
        }

        $persistenceManager->persistAll();
        $persistenceManager->clearState();
    }
}
