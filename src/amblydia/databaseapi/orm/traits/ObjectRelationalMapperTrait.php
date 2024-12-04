<?php
declare(strict_types=1);

namespace amblydia\databaseapi\orm\traits;

use amblydia\databaseapi\orm\ObjectRelationalMapper;
use amblydia\databaseapi\traits\ConnectionTrait;

use Exception;
use pocketmine\plugin\PluginBase;

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
	 * @param PluginBase $plugin
	 * @throws Exception
	 */
	public function initObjectRelationalMapper(PluginBase $plugin): void {
		$this->initDatabaseConnection($plugin);

		$this->objectRelationalMapper = new ObjectRelationalMapper($this->getDatabaseConnection());
	}
}