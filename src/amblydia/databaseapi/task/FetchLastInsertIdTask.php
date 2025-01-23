<?php
declare(strict_types=1);

namespace amblydia\databaseapi\task;

use amblydia\databaseapi\Connection;
use amblydia\databaseapi\exception\DatabaseException;
use Exception;
use PDOException;
use pocketmine\scheduler\AsyncTask;

final class FetchLastIdTask extends AsyncTask {
    private string $dsn;
    private ?string $username = null;
    private ?string $password = null;

    /**
     * @param Connection $connection
     * @param callable|null $function
     */
    public function __construct(Connection $connection, ?callable $function) {
        $this->dsn = $connection->getDsn();
        $this->password = $connection->getPassword();
        $this->username = $connection->getUsername();

        $this->storeLocal("func", $function ?? null);
    }

    /**
     * Run the task asynchronously
     */
    public function onRun(): void {
        try {
            $connection = Connection::createPDOConnection($this->dsn, $this->username, $this->password);

            $lastInsertId = $connection->lastInsertId();

            $this->setResult($lastInsertId);

            unset($connection);
        } catch (Exception $exception) {
            $this->setResult("error:" . serialize($exception instanceof PDOException ? DatabaseException::create($exception, "No query executed") : $exception));
        }
    }

    /**
     * Callback after task completion
     */
    public function onCompletion(): void {
        $func = $this->fetchLocal("func");

        $result = $this->getResult();
        if (is_string($result)) {
            if (str_starts_with($result, "error:")) {
                $result = unserialize(substr($result, strlen('error:')));
            }
        }

        if (is_callable($func)) {
            $func($result);
        }
    }
}
