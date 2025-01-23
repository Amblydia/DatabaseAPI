<?php
declare(strict_types=1);

namespace amblydia\databaseapi\orm\component;

final class Column {

    public const VERSION_COLUMN = "__version";

    private bool $autoIncrement = false;

	/**
	 * @param string $name
	 * @param string $type
	 * @param mixed $default
	 * @param string[] $constraints
	 */
	public function __construct(private readonly string $name, private readonly string $type, private readonly mixed $default, private readonly array $constraints) {}


    /**
     * @return bool
     */
    public function isAutoIncrement(): bool {
        return $this->autoIncrement;
    }

    /**
     * @param bool $autoIncrement
     */
    public function setAutoIncrement(bool $autoIncrement): void {
        $this->autoIncrement = $autoIncrement;
    }

	/**
	 * @return string
	 */
	public function getName(): string {
		return $this->name;
	}

	/**
	 * @return string
	 */
	public function getType(): string {
		return $this->type;
	}

	/**
	 * @return mixed
	 */
	public function getDefault(): mixed {
		return $this->default;
	}

	/**
	 * @return array
	 */
	public function getConstraints(): array {
		return $this->constraints;
	}

	/**
	 * @return string
	 */
	public function getStructure(): string {
		$structure =  $this->name . " " . $this->type . ($this->autoIncrement ? " AUTO_INCREMENT" : "");
		if ($this->default !== null){
			$structure .= " DEFAULT " . $this->default;
		}
		foreach ($this->constraints as $constraint){
			$structure .= " " . $constraint;
		}

		return $structure;
	}
}