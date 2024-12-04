<?php
declare(strict_types = 1);

namespace amblydia\databaseapi\orm\attribute;

use Attribute;

#[Attribute]
class DefaultValue {

	public function __construct(public mixed $value) {}
}