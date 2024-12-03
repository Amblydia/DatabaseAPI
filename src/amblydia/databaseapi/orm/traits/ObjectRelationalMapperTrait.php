<?php
declare(strict_types=1);

namespace amblydia\databaseapi\orm\traits;

use amblydia\databaseapi\orm\ObjectRelationalMapper;
use amblydia\databaseapi\traits\ConnectionTrait;

use amblydia\engine\feature\api\AmbFeature;

use Exception;

trait ObjectRelationalMapperTrait {
    use ConnectionTrait;

    private ObjectRelationalMapper $objectRelationalMapper;

    /**
     * @return ObjectRelationalMapper
     */
    public function getObjectRelationalMapper(): ObjectRelationalMapper {
        return $this->objectRelationalMapper;
    }

    /**
     * @param AmbFeature $feature
     * @throws Exception
     */
    public function initObjectRelationalMapper(AmbFeature $feature): void {
        $this->initDatabaseConnection($feature);

        $this->objectRelationalMapper = new ObjectRelationalMapper($this->getDatabaseConnection());
    }
}