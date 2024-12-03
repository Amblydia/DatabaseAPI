<?php
declare(strict_types=1);

namespace amblydia\databaseapi\orm;

use amblydia\databaseapi\Connection;
use amblydia\databaseapi\orm\attribute\TableName;
use amblydia\databaseapi\orm\component\Table;
use amblydia\databaseapi\orm\traits\Mappable;

use amblydia\engine\Loader;

use pocketmine\scheduler\CancelTaskException;
use pocketmine\scheduler\ClosureTask;

use ReflectionClass;
use ReflectionProperty;

use Exception;
use RuntimeException;
use InvalidArgumentException;

use DateTime;
use Ramsey\Uuid\Uuid;

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
     * @return void
     * @throws Exception
     */
    public function map(string $object): void {
        $reflection = new ReflectionClass($object);
        if (!array_key_exists(Mappable::class, $reflection->getTraits())) {
            throw new InvalidArgumentException("Object must implement " . Mappable::class);
        }

        /** @var TableName $tableNameAttr */
        $tableNameAttr = MappingParser::getAttribute($reflection, TableName::class);
        $tableName = $tableNameAttr === null ? $reflection->getShortName() : $tableNameAttr->value;

        $table = new Table($tableName);

        foreach ($reflection->getProperties() as $property) {
            $table->map($property);
        }

        $this->tables[$object] = $table;

        $this->isMapping[$object] = true;

        $this->connection->fetchRaw("SELECT 1 FROM {$this->getTableCheckQuery($tableName)};", function ($result) use ($object, $table, $tableName): void {
            if (empty($result) || $this->connection->getType() === "MySQL" && is_string($result)) {
                $this->connection->executeRaw($table->getCreationQuery(), function ($creationResult) use ($object): void {
                    unset($this->isMapping[$object]);

                    if (is_string($creationResult)) {
                        throw new RuntimeException($creationResult);
                    }
                });
            } else {
                $this->connection->fetchRaw($this->connection->getType() === "SQLite3" ? "PRAGMA table_info($tableName);" : "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '$tableName'", function ($columnsResult) use ($object, $table, $tableName): void {
                    if (is_string($columnsResult)) {
                        throw new RuntimeException($columnsResult);
                    }

                    $existingColumns = array_column($columnsResult, $this->connection->getType() === "SQLite3" ? "name" : "COLUMN_NAME");
                    $expectedColumns = array_map(fn($col) => $col->getName(), $table->getColumns());
                    $alterQueries = [];

                    $toDelete = array_diff($existingColumns, $expectedColumns);
                    $toAdd = array_diff($expectedColumns, $existingColumns);

                    $queries = [];
                    foreach ($table->getColumns() as $column) {
                        if (in_array($column->getName(), $toAdd, true)) {
                            $alterQueries[] = "ADD COLUMN " . $column->getStructure();
                        }
                    }
                    foreach ($toDelete as $column) {
                        $queries[] = "ALTER TABLE $tableName DROP COLUMN " . $column . ";";
                    }
                    if (!empty($alterQueries)) {
                        $queries[] = "ALTER TABLE $tableName " . implode(", ", $alterQueries) . ";";
                    }
                    if (!empty($queries)) {
                        $this->connection->batchExecute($queries, function () use ($object): void {
                            unset($this->isMapping[$object]);
                        });
                    }else{
                        unset($this->isMapping[$object]);
                    }
                });
            }
        });
    }

    /**
     * @param string $tableName
     * @return string
     */
    private function getTableCheckQuery(string $tableName): string {
        return match ($this->connection->getType()) {
            'MySQL' => "information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$tableName'",
            'SQLite3' => "sqlite_master WHERE type='table' AND name='$tableName'",
            default => throw new RuntimeException("Unsupported database type"),
        };
    }

    /**
     * @param object $object
     * @param callable|null $onComplete
     * @param callable|null $onError
     */
    public function persist(object $object, ?callable $onComplete, ?callable $onError): void {
        if (!isset($this->tables[$object::class])) {
            throw new InvalidArgumentException("Object is not mapped");
        }
        if(isset($this->isMapping[$object::class])){
            Loader::getInstance()->getScheduler()->scheduleDelayedRepeatingTask(new ClosureTask(function () use($object, $onComplete, $onError): void {
                if (isset($this->isMapping[$object::class])) {
                    return;
                }

                $this->persist($object, $onComplete, $onError);
                throw new CancelTaskException();
            }), 1, 1);
            return;
        }

        $table = $this->tables[$object::class];

        /** @var Mappable $object */
        if ($object->getObjectId() === null) {
            $object->setObjectId(Uuid::fromDateTime(new DateTime())->toString());
        }

        $this->connection->fetchRaw("SELECT * FROM {$table->getName()} WHERE objectId='" . $object->getObjectId() . "';", function ($check) use ($onComplete, $onError, $table, $object): void {
            if (!is_array($check)) {
                if ($onError !== null) {
                    $onError($check);
                }

                return;
            }

            try {
                $mapping = $table->getMapping();
                $values = [];

                $statement = empty($check) ? "INSERT INTO" : "REPLACE INTO";
                $statement .= " " . $table->getName() . "(";

                $size = count($columns = $table->getColumns());
                for ($i = 0; $i < $size; $i++) {
                    $statement .= $columns[$i]->getName();

                    if ($i !== ($size - 1)) {
                        $statement .= ", ";
                    }

                    /** @var ReflectionProperty $property */
                    $property = $mapping[$columns[$i]->getName()]["property"];
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

                $this->connection->executeRaw($statement, function ($result) use ($onError, $onComplete): void {
                    if (is_string($result)) {
                        if ($onError !== null) {
                            $onError($result);
                        }

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
        if(isset($this->isMapping[$class])){
            Loader::getInstance()->getScheduler()->scheduleDelayedRepeatingTask(new ClosureTask(function () use($class, $condition, $supplier, $onError): void {
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
            if (is_string($result)) {
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
                    $onError($exception->getMessage() . "\n" . $exception->getTraceAsString());
                }
            }
        });
    }

    /**
     * @param object $object
     * @param callable|null $onComplete
     * @param callable|null $onError
     */
    public function deleteEntry(object $object, ?callable $onComplete = null, ?callable $onError = null): void {
        if (!isset($this->tables[$object::class])) {
            throw new InvalidArgumentException("Object is not mapped");
        }

        $table = $this->tables[$object::class];

        /** @var Mappable $object */
        if ($object->getObjectId() === null) {
            return;
        }

        $this->connection->executeRaw("DELETE FROM {$table->getName()} WHERE objectId='" . $object->getObjectId() . "';", function ($result) use ($onError, $onComplete): void {
            if (is_string($result)) {
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