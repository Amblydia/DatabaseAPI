<?php
declare(strict_types=1);

namespace amblydia\databaseapi;

use amblydia\databaseapi\task\FetchQueryTask;
use amblydia\databaseapi\task\GenericQueryTask;

use pocketmine\plugin\PluginBase;

use PDO;

use InvalidArgumentException;
use Exception;

final class Connection {

    public const TYPE_MYSQL = 'mysql';
    public const TYPE_SQLITE = 'sqlite';

    /** @var string[] */
    private array $queries = [];
    private string $type;
    private string $dsn = "";
    private ?string $username = null;
    private ?string $password = null;

    /**
     * @param PluginBase $plugin
     */
    public function __construct(private readonly PluginBase $plugin) {
        $credentials = [];
        if (file_exists($file = $this->plugin->getDataFolder() . "database.yml")) {
            $credentials = yaml_parse_file($file);
        }
        if (empty($credentials)) {
            $this->type = self::TYPE_SQLITE;
        } else {
            $this->type = match (strtolower($credentials["type"])) {
                "mysql" => self::TYPE_MYSQL,
                default => self::TYPE_SQLITE
            };
        }


        switch ($this->type) {
            case self::TYPE_SQLITE:
                $this->dsn = "sqlite:" . $plugin->getDataFolder() . "/data" . ($credentials["path"] ?? "db.sqlite");
                break;
            case self::TYPE_MYSQL:
                $this->dsn = sprintf(
                    "mysql:host=%s;dbname=%s;port=%d",
                    $credentials["host"],
                    $credentials["dbname"],
                    $credentials["port"] ?? 3306
                );
                $this->username = $credentials["username"];
                $this->password = $credentials["password"];

                break;
        }

        $this->loadQueries();

        foreach ($this->queries as $queryId => $data) {
            if (str_contains($queryId, "__init")) {
                $this->executeRaw($this->queries[$queryId]["query"], function ($result) use ($plugin): void {
                    if ($result instanceof Exception) {

                        $plugin->getLogger()->error($result->getMessage());
                        return;
                    }

                    $plugin->getLogger()->logException($result);
                });
            }
        }
    }

    /**
     * @return string
     */
    public function getDsn(): string {
        return $this->dsn;
    }

    /**
     * @return string|null
     */
    public function getUsername(): ?string {
        return $this->username;
    }

    /**
     * @return string|null
     */
    public function getPassword(): ?string {
        return $this->password;
    }

    /**
     * @return string
     */
    public function getType(): string {
        return $this->type;
    }

    /**
     * @return PluginBase
     */
    public function getPlugin(): PluginBase {
        return $this->plugin;
    }

    /**
     * @param string $rawSQL
     * @param callable|null $callback
     */
    public function executeRawBlocking(string $rawSQL, ?callable $callback = null): void {
        try {
            $pdo = self::createPDOConnection($this->dsn, $this->username, $this->password);
            $result = $pdo->exec($rawSQL);

            if ($callback !== null) {
                $callback($result !== false);
            }
        } catch (Exception $e) {
            if ($callback !== null) {
                $callback($e);
            }
        }
    }

    /**
     * @param string $queryId
     * @param array $args
     * @param callable|null $callback
     * @return void
     */
    public function executeBlocking(string $queryId, array $args = [], ?callable $callback = null): void {
        try {
            $pdo = self::createPDOConnection($this->dsn, $this->username, $this->password);

            $stmt = $pdo->prepare($this->getPredefinedQuery($queryId, $args));
            $stmt->execute($queryData["args"] ?? []);

            if ($callback !== null) {
                $callback(true);
            }
        } catch (Exception $e) {
            if ($callback !== null) {
                $callback($e);
            }
        }
    }

    /**
     * @param string $dsn
     * @param string|null $user
     * @param string|null $password
     * @return PDO
     */
    public static function createPDOConnection(string $dsn, ?string $user, ?string $password): PDO {
        return new PDO(
            $dsn,
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    /**
     * @return void
     */
    public function loadQueries(): void {
        $resource = null;
        switch ($this->type) {
            case self::TYPE_MYSQL:
                $resource = $this->getPlugin()->getResource("mysql.sql");
                break;
            case self::TYPE_SQLITE:
                $resource = $this->getPlugin()->getResource("sqlite.sql");
                break;
        }
        if ($resource === null) {
            return;
        }

        $content = stream_get_contents($resource);
        fclose($resource);

        preg_match_all('/--\s+([\w\.]+)\s*\(([^)]*)\)\s*{\s*([^}]+)\s*}/', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $node = $match[1];

            $pos = strpos($match[3], "--");
            $query = substr($match[3], 0, $pos !== false ? $pos : null);
            $args = [];

            if ($match[2] !== "") {
                $args = array_map("trim", explode(",", $match[2]));
            }

            $this->queries[$node] = [
                "query" => $query,
                "args" => $args
            ];
        }
    }

    /**
     * @param string $queryId
     * @param array $args
     * @param callable|null $function
     *
     * @note Passes true to callback on success, false on failure and exception on any errors
     */
    public function execute(string $queryId, array $args = [], ?callable $function = null): void {
        $this->executeRaw($this->getPredefinedQuery($queryId, $args), $function);
    }

    /**
     * @param string $queryId
     * @param array $args
     * @param callable|null $function
     *
     * @note This function passes an assoc array to the callback upon completion along with any caught exception
     */
    public function fetch(string $queryId, array $args = [], ?callable $function = null): void {
        $this->fetchRaw($this->getPredefinedQuery($queryId, $args), $function);
    }

    /**
     * @param string $file
     * @param callable|null $onComplete
     */
    public function batchExecuteFile(string $file, ?callable $onComplete = null): void {
        $queryFile = fopen($file, "r");
        $query = fread($queryFile, filesize($file));

        $queries = explode(";", $query);

        $this->batchExecute($queries, $onComplete);

        fclose($queryFile);
    }

    /**
     * @param array $queries
     * @param callable|null $onComplete
     */
    public function batchExecute(array $queries, ?callable $onComplete = null): void {
        $this->getPlugin()->getServer()->getAsyncPool()->submitTask(
            new GenericQueryTask($this, $queries, function ($result) use ($onComplete): void {
                if ($result instanceof Exception) {
                    $this->getPlugin()->getLogger()->logException($result);
                    return;
                }
                if ($onComplete !== null) {
                    $onComplete();
                }
            })
        );
    }


    /**
     * @param string $rawSQL
     * @param callable|null $function
     */
    public function executeRaw(string $rawSQL, ?callable $function = null): void {
        $this->getPlugin()->getServer()->getAsyncPool()->submitTask(
            new GenericQueryTask($this, [$rawSQL], $function)
        );
    }

    /**
     * @param string $rawSQL
     * @param callable|null $function
     */
    public function fetchRaw(string $rawSQL, ?callable $function = null): void {
        $this->getPlugin()->getServer()->getAsyncPool()->submitTask(
            new FetchQueryTask($this, $rawSQL, $function)
        );
    }

    /**
     * @param string $queryId
     * @param array $args
     * @return string
     */
    protected function getPredefinedQuery(string $queryId, array $args): string {
        if (!isset($this->queries[$queryId])) {
            throw new InvalidArgumentException("No such query with id: $queryId");
        }

        $query = $this->queries[$queryId]["query"];
        foreach (($this->queries[$queryId]["args"] ?? []) as $arg) {
            $query = str_replace(":" . $arg, (string)$args[$arg], $query);
        }

        return $query;
    }
}