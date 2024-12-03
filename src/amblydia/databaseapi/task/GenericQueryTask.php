<?php
declare(strict_types=1);

namespace amblydia\databaseapi\task;

use amblydia\databaseapi\helper\ConnectionHelper;
use Exception;
use pocketmine\scheduler\AsyncTask;
use pocketmine\utils\Utils;

class GenericQueryTask extends AsyncTask {

    /** @var string */
    private string $credentials;

    /** @var string */
    private string $queries;

    /**
     * GenericQueryTask constructor.
     * @param array $credentials
     * @param string[] $queries
     * @param callable|null $function
     */
    public function __construct(array $credentials, array $queries, ?callable $function) {
        Utils::validateCallableSignature($var = function ($result): void {
        }, $function ?? $var);

        $this->credentials = serialize($credentials);
        $this->queries = serialize($queries);

        $this->storeLocal("func", $function ?? 0);
    }

    /**
     *
     */
    public function onRun(): void {
        try {
            $connection = ConnectionHelper::connect(unserialize($this->credentials));
            $queries = unserialize($this->queries);

            foreach ($queries as $query) {
                $stmt = $connection->prepare($query);
                $stmt->execute();
            }

            $this->setResult(true);

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