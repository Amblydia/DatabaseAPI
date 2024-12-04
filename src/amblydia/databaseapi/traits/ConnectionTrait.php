<?php
declare(strict_types=1);

namespace amblydia\databaseapi\traits;

use amblydia\databaseapi\Connection;
use Exception;
use pocketmine\plugin\PluginBase;

trait ConnectionTrait {

	/** @var Connection */
	private Connection $connection;

	/**
	 * @param PluginBase $plugin
	 * @throws Exception
	 */
	public function initDatabaseConnection(PluginBase $plugin): void {
		$plugin->saveResource("sqlite.sql");
		$plugin->saveResource("mysql.sql");

		$this->connection = new Connection($plugin->getConfig()->get("database"), $plugin);
	}

	/**
	 * @return Connection
	 */
	public function getDatabaseConnection(): Connection {
		return $this->connection;
	}
}