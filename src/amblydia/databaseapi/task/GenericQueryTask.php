<?php
declare(strict_types=1);

namespace amblydia\databaseapi\task;

use amblydia\databaseapi\Connection;

use amblydia\databaseapi\exception\DatabaseException;
use PDOException;
use pocketmine\scheduler\AsyncTask;

use Exception;

final class GenericQueryTask extends AsyncTask {

    private string $queries;
    private string $dsn;
    private ?string $username = null;
    private ?string $password = null;

    /**
     * @param Connection $connection
     * @param array $queries
     * @param callable|null $function
     */
    public function __construct(Connection $connection, array $queries, ?callable $function) {
        $this->dsn = $connection->getDsn();
        $this->password = $connection->getPassword();
        $this->username = $connection->getUsername();

        $this->queries = serialize($queries);

        $this->storeLocal("func", $function ?? 0);
    }

    /**
     *
     */
    public function onRun(): void {
        $queries = unserialize($this->queries);
        $connection = null;
        foreach ($queries as $query) {
            try {
                if ($connection === null)
                    $connection = Connection::createPDOConnection($this->dsn, $this->username, $this->password);

                $stmt = $connection->prepare($query);
                $stmt->execute();
            } catch (Exception $exception) {
                $this->setResult("error:" . serialize($exception instanceof PDOException ? DatabaseException::create($exception, null) : $exception));
            }
        }

        $this->setResult(true);

        unset($connection); // close the connection
    }

    /**
     *
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