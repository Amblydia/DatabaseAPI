<?php
declare(strict_types = 1);

namespace amblydia\databaseapi\orm\attribute;

use Attribute;

#[Attribute]
class ColumnName {

	public function __construct(public string $value) {}
}