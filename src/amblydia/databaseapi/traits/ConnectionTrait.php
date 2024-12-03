<?php
declare(strict_types=1);

namespace amblydia\databaseapi\traits;

use amblydia\databaseapi\Connection;
use amblydia\engine\feature\api\AmbFeature;
use Exception;

trait ConnectionTrait {

    /** @var Connection */
    private Connection $connection;

    /**
     * @param AmbFeature $module
     * @throws Exception
     */
    public function initDatabaseConnection(AmbFeature $module): void {
        $module->saveResource("sqlite.sql");
        $module->saveResource("mysql.sql");

        $this->connection = new Connection($module->getConfig()->get("database"), $module);
    }

    /**
     * @return Connection
     */
    public function getDatabaseConnection(): Connection {
        return $this->connection;
    }
}