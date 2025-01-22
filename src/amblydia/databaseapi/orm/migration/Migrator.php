<?php
declare(strict_types=1);

namespace amblydia\databaseapi\orm\migration;

abstract class Migrator {

    abstract public function migrate(string $oldVersion, array &$oldRows, array &$newRows): void;

}