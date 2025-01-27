<?php
declare(strict_types=1);

namespace amblydia\databaseapi\orm;

use amblydia\databaseapi\Connection;

use amblydia\databaseapi\orm\attribute\TableName;

use amblydia\databaseapi\orm\component\Column;
use amblydia\databaseapi\orm\component\Table;

use amblydia\databaseapi\orm\migration\Migrator;

use amblydia\databaseapi\task\FetchLastIdTask;
use pocketmine\scheduler\CancelTaskException;
use pocketmine\scheduler\ClosureTask;

use Exception;
use pocketmine\Server;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use InvalidArgumentException;

final class ObjectRelationalMapper {

    /** @var Table[] */
    private array $tables = [];

    /** @var bool[] */
    private array $isMapping = [];

    /**
     * @param Connection $connection
     */
    public function __construct(private readonly Connection $connection) {
    }

    /**
     * @return Connection
     */
    public function getConnection(): Connection {
        return $this->connection;
    }

    /**
     * @param string $object
     * @param string $version
     * @param Migrator|null $migrator
     * @throws ReflectionException
     */
    public function map(string $object, string $version = "1.0.0", ?Migrator $migrator = null): void {
        $reflection = new ReflectionClass($object);

        /** @var TableName $tableNameAttr */
        $tableNameAttr = MappingParser::getAttribute($reflection, TableName::class);
        $tableName = $tableNameAttr === null ? $reflection->getShortName() : $tableNameAttr->value;

        $table = new Table($tableName, $version);
        foreach ($reflection->getProperties() as $property) {
            $table->map($property);
        }
        if ($table->getPrimaryKey() === null) {
            throw new InvalidArgumentException("Object must have a PrimaryKey attributed property");
        }

        $table->addColumn(new Column(
            Column::VERSION_COLUMN,
            "VARCHAR(36)",
            $table->getVersion(),
            []
        ));

        $this->tables[$object] = $table;

        $this->isMapping[$object] = true;

        $this->connection->fetchRaw("SELECT * FROM $tableName;", function ($result) use ($migrator, $object, $table, $tableName): void {
            if ($result instanceof Exception) {
                $this->connection->executeRaw($table->getCreationQuery(), function ($result) use ($object): void {
                    if ($result instanceof Exception) {
                        $this->connection->getPlugin()->getLogger()->logException($result);
                        return;
                    }

                    unset($this->isMapping[$object]);
                });
                return;
            }
            if (empty($result)) {
                // restructure anyway because it is empty (no risk on insertion)
                $this->restructureTable($table, function () use ($object): void {
                    unset($this->isMapping[$object]);
                });
                return;
            }

            if (($oldVersion = $result[0][Column::VERSION_COLUMN] ?? "null") !== $table->getVersion()) {
                // migration required!
                if ($migrator === null) {
                    throw new InvalidArgumentException("There are changes in table version, however migrator is not configured");
                }

                $this->connection->getPlugin()->getLogger()->info("Migrating table to new version: " . $tableName . "_v" . $table->getVersion());
                $this->restructureTable($table, function () use ($object, $tableName, $oldVersion, $migrator, $table, &$result): void {
                    $this->connection->batchExecute($migrator->getMigrationQueries($oldVersion), function () use ($object, $tableName): void {
                        unset($this->isMapping[$object]);

                        $this->connection->getPlugin()->getLogger()->info("Migration complete for table: " . $tableName);
                    });
                });
            } else unset($this->isMapping[$object]);
        });
    }

    /**
     * @param Table $table
     * @return array
     */
    protected function createDummyRow(Table $table): array {
        $row = [];
        foreach ($table->getColumns() as $column) {
            $row[$column->getName()] = $column->getDefault();
        }

        return $row;
    }

    /**
     * @param Table $table
     * @param callable|null $onCompletion
     * @return void
     */
    protected function restructureTable(Table $table, ?callable $onCompletion = null): void {
        $this->connection->executeRaw("DROP TABLE IF EXISTS {$table->getName()};", function ($result) use ($onCompletion, $table): void {
            if ($result instanceof Exception) {
                $this->connection->getPlugin()->getLogger()->logException($result);
                $this->connection->getPlugin()->getLogger()->error("Failed to restructure table: " . $table->getName());
                return;
            }

            $this->connection->executeRaw($table->getCreationQuery(), function ($result) use ($onCompletion, $table): void {
                if ($result instanceof Exception) {
                    $this->connection->getPlugin()->getLogger()->logException($result);
                    $this->connection->getPlugin()->getLogger()->error("Failed to restructure table: " . $table->getName());
                    return;
                }

                if (is_callable($onCompletion))
                    $onCompletion();
            });
        });
    }

    /**
     * @param object $object
     * @param callable|null $onComplete
     * @param callable|null $onError
     * @throws ReflectionException
     */
    public function persist(object $object, ?callable $onComplete = null, ?callable $onError = null): void {
        if (!isset($this->tables[$object::class])) {
            throw new InvalidArgumentException("Object is not mapped");
        }
        if (isset($this->isMapping[$object::class])) {
            $this->connection->getPlugin()->getScheduler()->scheduleDelayedRepeatingTask(new ClosureTask(function () use ($object, $onComplete, $onError): void {
                if (isset($this->isMapping[$object::class])) {
                    return;
                }

                $this->persist($object, $onComplete, $onError);
                throw new CancelTaskException();
            }), 1, 1);
            return;
        }

        $getValue = function (string $key, object $object): mixed {
            $class = new ReflectionClass($object);
            $property = $class->getProperty($key);
            $property->setAccessible(true);

            return $property->getValue($object);
        };

        $table = $this->tables[$object::class];
        $primaryKey = $table->getPrimaryKey();

        $this->connection->fetchRaw("SELECT * FROM {$table->getName()} WHERE $primaryKey='" . $getValue($primaryKey, $object) . "';", function ($check) use ($onComplete, $onError, $table, $object): void {
            if ($check instanceof Exception) {
                if ($onError !== null) {
                    $onError($check);
                }

                return;
            }

            try {
                $autoIncrementProperty = null;

                $mapping = $table->getMapping();
                $values = [];

                $statement = empty($check) ? "INSERT INTO" : "REPLACE INTO";
                $statement .= " " . $table->getName() . "(";

                $size = count($columnNames = array_keys($mapping));
                for ($i = 0; $i < $size; $i++) {
                    // no need to set value for auto_incrementing column when inserting
                    if ($table->getColumn($columnNames[$i])->isAutoIncrement() && empty($check)) {
                        $autoIncrementProperty = $mapping[$columnNames[$i]]["property"];
                        continue;
                    }

                    $statement .= $columnNames[$i];

                    if ($i !== ($size - 1)) {
                        $statement .= ", ";
                    }

                    /** @var ReflectionProperty $property */
                    $property = $mapping[$columnNames[$i]]["property"];
                    $value = $property->getValue($object);
                    if (is_array($value)) {
                        $value = json_encode($value);
                    } else if (is_object($value)) {
                        $value = serialize($value);
                    }

                    $values[] = "'" . $value . "'";
                }
                $statement .= ") VALUES (";

                for ($i = 0; $i < $size; $i++) {
                    $statement .= $values[$i];
                    if ($i !== ($size - 1)) {
                        $statement .= ", ";
                    }
                }
                $statement .= ");";

                $this->connection->executeRaw($statement, function ($result) use ($object, $onError, $onComplete, $autoIncrementProperty): void {
                    if ($result instanceof Exception) {
                        if ($onError !== null) {
                            $onError($result);
                        }

                        return;
                    }
                    if ($autoIncrementProperty !== null) {
                        Server::getInstance()->getAsyncPool()->submitTask(new FetchLastIdTask(
                            $this->connection,
                            function ($lastID) use ($object, $onError, $onComplete, $autoIncrementProperty): void {
                                if ($lastID instanceof Exception) {
                                    if ($onError !== null) {
                                        $onError($lastID);

                                        return;
                                    }
                                }

                                /** @var ReflectionProperty $autoIncrementProperty */
                                $autoIncrementProperty->setValue($object, $lastID);
                                if ($onComplete !== null) {
                                    $onComplete();
                                }
                            }
                        ));
                        return;
                    }
                    if ($onComplete !== null) {
                        $onComplete();
                    }
                });
            } catch (Exception $exception) {
                if ($onError !== null) {
                    $onError($exception->getMessage() . "\n" . $exception->getTraceAsString());
                }
            }
        });
    }

    /**
     * @param string $class
     * @param string|null $condition
     * @param callable $supplier
     * @param callable|null $onError
     */
    public function getEntries(string $class, ?string $condition, callable $supplier, ?callable $onError = null): void {
        if (!isset($this->tables[$class])) {
            throw new InvalidArgumentException("Object is not mapped");
        }
        if (isset($this->isMapping[$class])) {
            $this->connection->getPlugin()->getScheduler()->scheduleDelayedRepeatingTask(new ClosureTask(function () use ($class, $condition, $supplier, $onError): void {
                if (isset($this->isMapping[$class])) {
                    return;
                }

                $this->getEntries($class, $condition, $supplier, $onError);
                throw new CancelTaskException();
            }), 1, 1);
            return;
        }

        $table = $this->tables[$class];

        $statement = "SELECT * FROM " . $table->getName();
        if ($condition !== null) {
            $statement .= " WHERE " . $condition;
        }
        $statement .= ";";

        $this->connection->fetchRaw($statement, function ($result) use ($onError, $supplier, $table, $class): void {
            if ($result instanceof Exception) {
                if ($onError !== null) {
                    $onError($result);
                }

                return;
            }

            try {
                $reflection = new ReflectionClass($class);
                $objects = [];

                foreach ($result as $row) {
                    $object = $reflection->newInstanceWithoutConstructor();
                    foreach ($table->getMapping() as $columnName => $data) {
                        /** @var ReflectionProperty $property */
                        $property = $data["property"];
                        $value = $row[$columnName] ?? $data["default"];

                        match ($property->getType()->getName()) {
                            'bool' => $value = ($value === 1),
                            'array' => $value = ($value === null ? [] : json_decode($value, true)),
                            'object' => $value = ($value === null ? null : unserialize($value)),
                            'int', 'float', 'long', 'double' => $value = ($value === null ? 0 : $value),
                            'string' => $value = ($value === null ? "" : $value),
                            'mixed' => $value = ($value === null ? null : $value),
                            default => null
                        };

                        $property->setValue($object, $value);
                    }

                    $objects[] = $object;
                }

                $supplier($objects);
            } catch (Exception $exception) {
                if ($onError !== null) {
                    $onError($exception);
                }
            }
        });
    }

    /**
     * @param object $object
     * @param callable|null $onComplete
     * @param callable|null $onError
     * @throws ReflectionException
     */
    public function deleteEntry(object $object, ?callable $onComplete = null, ?callable $onError = null): void {
        if (!isset($this->tables[$object::class])) {
            throw new InvalidArgumentException("Object is not mapped");
        }

        $getValue = function (string $key, object $object): mixed {
            $class = new ReflectionClass($object);
            $property = $class->getProperty($key);
            $property->setAccessible(true);

            return $property->getValue($object);
        };

        $table = $this->tables[$object::class];
        $primaryKey = $table->getPrimaryKey();

        $this->connection->executeRaw("DELETE FROM {$table->getName()} WHERE $primaryKey='" . $getValue($primaryKey, $object) . "';", function ($result) use ($onError, $onComplete): void {
            if ($result instanceof Exception) {
                if ($onError !== null) {
                    $onError($result);
                }

                return;
            }

            if ($onComplete !== null) {
                $onComplete();
            }
        });
    }
}