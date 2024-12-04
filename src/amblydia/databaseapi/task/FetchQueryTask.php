<?php
declare(strict_types=1);

namespace amblydia\databaseapi\task;

use amblydia\databaseapi\helper\ConnectionHelper;
use Exception;
use PDO;
use pocketmine\scheduler\AsyncTask;
use pocketmine\utils\Utils;

class FetchQueryTask extends AsyncTask {

	/** @var string */
	private string $credentials;

	/**
	 * GenericQueryTask constructor.
	 * @param array $credentials
	 * @param string $query
	 * @param callable|null $function
	 */
	public function __construct(array $credentials, private readonly string $query, ?callable $function) {
		Utils::validateCallableSignature($var = function ($result): void {
		}, $function ?? $var);

		$this->credentials = serialize($credentials);

		$this->storeLocal("func", $function ?? 0);
	}

	/**
	 *
	 */
	public function onRun(): void {
		try {
			$connection = ConnectionHelper::connect(unserialize($this->credentials));

			$stmt = $connection->prepare($this->query);
			$stmt->execute();

			$result = $stmt->fetchAll(PDO::FETCH_ASSOC);

			$this->setResult($result);

			unset($connection); // close the connection
		} catch (Exception $exception) {
			$this->setResult($exception->getMessage() . "\n" .$exception->getTraceAsString());
		}
	}

	/**
	 *
	 */
	public function onCompletion(): void {
		$func = $this->fetchLocal("func");

		if (is_callable($func)) {
			$func($this->getResult());
		}
	}
}