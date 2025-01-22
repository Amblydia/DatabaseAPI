<?php
declare(strict_types=1);

namespace amblydia\databaseapi\task;

use amblydia\databaseapi\Connection;

use amblydia\databaseapi\exception\DatabaseException;
use Exception;
use PDO;
use PDOException;
use pocketmine\scheduler\AsyncTask;

final class FetchQueryTask extends AsyncTask {
    private string $dsn;
    private ?string $username = null;
    private ?string $password = null;

    /**
     * @param Connection $connection
     * @param string $query
     * @param callable|null $function
     */
	public function __construct(Connection $connection, private readonly string $query, ?callable $function) {
        $this->dsn = $connection->getDsn();
        $this->password = $connection->getPassword();
        $this->username = $connection->getUsername();

		$this->storeLocal("func", $function ?? 0);
	}

	/**
	 *
	 */
	public function onRun(): void {
		try {
			$connection = Connection::createPDOConnection($this->dsn, $this->username, $this->password);

			$stmt = $connection->prepare($this->query);
			$stmt->execute();

			$result = $stmt->fetchAll(PDO::FETCH_ASSOC);

			$this->setResult($result);

			unset($connection);
		} catch (Exception $exception) {
            $this->setResult("error:" . serialize($exception instanceof PDOException ? DatabaseException::create($exception, $this->query) : $exception));
		}
	}

	/**
	 *
	 */
	public function onCompletion(): void {
		$func = $this->fetchLocal("func");

        $result = $this->getResult();
        if (is_string($result)){
            if (str_starts_with($result, "error:")) {
                $result = unserialize(substr($result, strlen('error:')));
            }
        }

        if (is_callable($func)) {
            $func($result);
        }
	}
}