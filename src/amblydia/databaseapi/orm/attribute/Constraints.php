<?php
declare(strict_types = 1);

namespace amblydia\databaseapi\orm\attribute;

use Attribute;

#[Attribute]
class Constraints {

    public function __construct(public array $value) {
    }
}