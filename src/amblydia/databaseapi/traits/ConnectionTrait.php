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
		$this->connection = new Connection($plugin);
	}

	/**
	 * @return Connection
	 */
	public function getDatabaseConnection(): Connection {
		return $this->connection;
	}
}