<?php
declare(strict_types=1);

namespace amblydia\databaseapi\orm\component;

use amblydia\databaseapi\orm\attribute\ColumnName;
use amblydia\databaseapi\orm\attribute\Constraints;
use amblydia\databaseapi\orm\attribute\DefaultValue;
use amblydia\databaseapi\orm\attribute\NoMapping;
use amblydia\databaseapi\orm\attribute\PrimaryKey;
use amblydia\databaseapi\orm\MappingParser;

use Exception;
use InvalidArgumentException;
use ReflectionProperty;

final class Table {

	/** @var Column[] */
	private array $columns = [];

	/** @var array */
	private array $mapping = [];

    private ?string $primaryKey = null;

	public function __construct(private readonly string $name, private readonly string $version) {

    }

	/**
	 * @return string
	 */
	public function getName(): string {
		return $this->name;
	}

    /**
     * @return string|null
     */
    public function getPrimaryKey(): ?string {
        return $this->primaryKey;
    }

    /**
     * @return string
     */
    public function getVersion(): string {
        return $this->version;
    }

	/**
	 * @return array
	 */
	public function getColumns(): array {
		return $this->columns;
	}

    /**
     * @param string $name
     * @return Column|null
     */
    public function getColumn(string $name): ?Column {
        return $this->columns[$name] ?? null;
    }

	/**
	 * Adds a column to the table and updates the internal mapping
	 *
	 * @param ReflectionProperty $property
	 * @throws Exception
	 */
	public function map(ReflectionProperty $property): void {
		$property->setAccessible(true);

		if (MappingParser::getAttribute($property, NoMapping::class) !== null) {
			return;
		}

		$dataType = MappingParser::getDataType($property);

        /** @var ColumnName $tmp */
		$columnName = ($tmp = MappingParser::getAttribute($property, ColumnName::class)) === null ? $property->getName() : $tmp->value;
		/** @var DefaultValue $tmp */
		$default = ($tmp = MappingParser::getAttribute($property, DefaultValue::class)) === null ? null : $tmp->value;
		/** @var Constraints $tmp */
		$constraints = ($tmp = MappingParser::getAttribute($property, Constraints::class)) === null ? [] : $tmp->value;

        if(($primaryKey = MappingParser::getAttribute($property, PrimaryKey::class)) !== null){
            if($this->primaryKey !== null){
                throw new InvalidArgumentException("Multiple PrimaryKey properties detected: " . $this->primaryKey . " and " . $columnName);
            }

            $constraints[] = "PRIMARY KEY";

            $this->primaryKey = $columnName;
        }

		$this->columns[$columnName] = new Column(
			$columnName,
			$dataType,
			$default,
			$constraints
		);

		$this->mapping[$columnName] = [
			"property" => $property,
			"default" => $default
		];
	}

	/**
	 * @return array
	 */
	public function getMapping(): array {
		return $this->mapping;
	}

	/**
	 * @param Column $column
	 */
	public function addColumn(Column $column): void {
		$this->columns[] = $column;
	}

	/**
	 * @return string
	 */
	public function getCreationQuery(): string {
		$structure = "CREATE TABLE IF NOT EXISTS `$this->name` (";
		foreach ($this->columns as $column) {
			$structure .= $column->getStructure() . ", ";
		}
        $structure = rtrim($structure, ", ");
		$structure .= ");";

		return $structure;
	}
}