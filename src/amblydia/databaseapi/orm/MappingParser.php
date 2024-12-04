<?php
declare(strict_types=1);

namespace amblydia\databaseapi\orm;

use amblydia\databaseapi\orm\attribute\Size;

use InvalidArgumentException;
use ReflectionProperty;
use ReflectionUnionType;

final class MappingParser {

	/**
	 * @param ReflectionProperty $property
	 * @return string
	 */
	public static function getDataType(ReflectionProperty $property): string {
		$mainType = $property->getType();

		/** @var Size $size */
		$size = self::getAttribute($property, Size::class);
		$size = is_null($size) ? null : $size->value;

		if ($mainType instanceof ReflectionUnionType) {
			$nonNullType = null;
			$canBeNull = false;
			foreach ($mainType->getTypes() as $type) {
				if ($type->getName() === 'null') {
					$canBeNull = true;
				} else {
					$nonNullType = $type->getName();
				}

				if ($nonNullType !== null && $nonNullType !== $type->getName()) {
					throw new InvalidArgumentException("Property " . $property->getName() . " cannot have multiple types when mapping");
				}
			}

			if ($nonNullType) {
				return self::mapPhpTypeToMySqlType($nonNullType, $canBeNull, $size);
			}
		} elseif ($mainType) {
			return self::mapPhpTypeToMySqlType($mainType->getName(), $mainType->allowsNull(), $size);
		}

		throw new InvalidArgumentException("Property has no valid data type: " . $property->getName());
	}

	/**
	 * @param string $phpType
	 * @param bool $isNullable
	 * @param int|null $size
	 * @return string|null
	 */
	private static function mapPhpTypeToMySqlType(string $phpType, bool $isNullable, ?int $size = null): ?string {
		$typeMap = [
			'int' => $size !== null ? "INT($size)" : "INT",
			'float' => $size !== null ? "FLOAT($size)" : "FLOAT",
			'double' => $size !== null ? "DOUBLE($size)" : "DOUBLE",
			'long' => $size !== null ? "LONG($size)" : "LONG",
			'string' => 'VARCHAR(' . ($size ?? 255) . ')',
			'bool' => 'TINYINT(1)',
			'array' => 'BLOB', // json
			'object' => 'BLOB', // serialized
			'mixed' => 'TEXT'
		];

		$mysqlType = $typeMap[$phpType] ?? null;
		if ($mysqlType === null) {
			return null;
		}

		return $isNullable ? "$mysqlType NULL" : "$mysqlType NOT NULL";
	}

	/**
	 * @param $reflection
	 * @param string $name
	 * @return mixed|null
	 */
	public static function getAttribute($reflection, string $name): ?object {
		foreach ($reflection->getAttributes($name) as $attribute) {
			if ($attribute->getName() === $name) {
				return $attribute->newInstance();
			}
		}

		return null;
	}
}