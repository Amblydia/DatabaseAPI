<?php
declare(strict_types=1);

namespace amblydia\databaseapi;

use amblydia\databaseapi\task\FetchQueryTask;
use amblydia\databaseapi\task\GenericQueryTask;
use Exception;
use InvalidArgumentException;
use PDO;
use PDOException;
use pocketmine\plugin\PluginBase;
use RuntimeException;

final class Connection {

	/** @var array */
	private array $queries = [];

	/** @var string */
	private string $type;

	// todo: implement this
	/** @var Connection|null */
	private ?Connection $blockingConnection = null;

	/**
	 * Connection constructor.
	 * @param array $credentials
	 * @param PluginBase $plugin
	 * @throws Exception
	 */
	public function __construct(private array $credentials, private readonly PluginBase $plugin) {
		$type = $this->credentials["type"];
		$config = $this->credentials["config"];

		switch ($type) {
			case "sqlite":
			case "sqlite3":
				$this->credentials = ["path" => $plugin->getDataFolder() . "/data" . ($config["path"] ?? "db.sqlite")];
				$path = $plugin->getDataFolder() . "sqlite.sql";
				$this->type = "SQLite3";
				break;

			case "mysql":
				$this->credentials = $config;
				$path = $plugin->getDataFolder() . "mysql.sql";
				$this->type = "MySQL";
				break;

			default:
				throw new RuntimeException("Unsupported database type: $type");
		}

		if (file_exists($path)) {
			$this->loadQueries($path);
		}

		foreach ($this->queries as $queryId => $data) {
			if (str_contains($queryId, "__init")) {
				$this->executeRaw($this->queries[$queryId]["query"], function ($result) use ($plugin): void {
					if ($result instanceof Exception) {
						if ($result instanceof PDOException) {
							$plugin->getLogger()->error($result->getMessage());

							return;
						}

						$plugin->getLogger()->logException($result);
					}
				});
			}
		}
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
			$pdo = $this->createPDOConnection();
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
			$pdo = $this->createPDOConnection();

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
	 * @return PDO
	 */
	private function createPDOConnection(): PDO {
		$dsn = match ($this->type) {
			"SQLite3" => "sqlite:" . $this->credentials["path"],
			"MySQL" => sprintf(
				"mysql:host=%s;dbname=%s;port=%d",
				$this->credentials["host"],
				$this->credentials["database"],
				$this->credentials["port"] ?? 3306
			),
			default => throw new RuntimeException("Unsupported database type: {$this->type}")
		};

		return new PDO(
			$dsn,
			$this->credentials["user"] ?? null,
			$this->credentials["password"] ?? null,
			[
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			]
		);
	}

	/**
	 * @param string $file
	 * @return void
	 */
	public function loadQueries(string $file): void {
		preg_match_all('/--\s+([\w\.]+)\s*\(([^)]*)\)\s*{\s*([^}]+)\s*}/', file_get_contents($file), $matches, PREG_SET_ORDER);

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
			new GenericQueryTask($this->credentials, $queries, function ($result) use($onComplete): void {
				if (is_string($result)) {
					$this->getPlugin()->getLogger()->error($result);

					return;
				}
				if ($onComplete !== null){
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
			new GenericQueryTask($this->credentials, [$rawSQL], $function)
		);
	}

	/**
	 * @param string $rawSQL
	 * @param callable|null $function
	 */
	public function fetchRaw(string $rawSQL, ?callable $function = null): void {
		$this->getPlugin()->getServer()->getAsyncPool()->submitTask(
			new FetchQueryTask($this->credentials, $rawSQL, $function)
		);
	}

	/**
	 * @param string $queryId
	 * @param array $args
	 * @return string
	 */
	protected function getPredefinedQuery(string $queryId, array $args): string {
		if (!isset($this->queries[$queryId])) {
			throw new InvalidArgumentException("No such query with queryId: $queryId");
		}

		$query = $this->queries[$queryId]["query"];
		foreach (($this->queries[$queryId]["args"] ?? []) as $arg) {
			$query = str_replace(":" . $arg, (string)$args[$arg], $query);
		}

		return $query;
	}
}