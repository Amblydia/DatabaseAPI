<?php
declare(strict_types=1);

namespace amblydia\databaseapi\orm\component;

use amblydia\databaseapi\orm\attribute\ColumnName;
use amblydia\databaseapi\orm\attribute\Constraints;
use amblydia\databaseapi\orm\attribute\DefaultValue;
use amblydia\databaseapi\orm\attribute\NoMapping;
use amblydia\databaseapi\orm\MappingParser;

use Exception;
use ReflectionProperty;

final class Table {

    /** @var Column[] */
    private array $columns = [];

    /** @var array */
    private array $mapping = [];

    public function __construct(private readonly string $name) {

    }

    /**
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * @return array
     */
    public function getColumns(): array {
        return $this->columns;
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
        if ($dataType === null) {
            throw new Exception("Data type cannot be null");
        }

        /** @var ColumnName $tmp */
        $columnName = ($tmp = MappingParser::getAttribute($property, ColumnName::class)) === null ? $property->getName() : $tmp->value;
        /** @var DefaultValue $tmp */
        $default = ($tmp = MappingParser::getAttribute($property, DefaultValue::class)) === null ? null : $tmp->value;
        /** @var Constraints $tmp */
        $constraints = ($tmp = MappingParser::getAttribute($property, Constraints::class)) === null ? [] : $tmp->value;

        $this->columns[] = new Column(
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

        $size = count($this->columns);
        for ($i = 0; $i < $size; $i++) {
            $structure .= $this->columns[$i]->getStructure();
            if ($i !== ($size - 1)) {
                $structure .= ", ";
            }
        }

        $structure .= ");";

        return $structure;
    }
}