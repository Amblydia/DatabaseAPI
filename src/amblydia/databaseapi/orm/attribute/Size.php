<?php
declare(strict_types = 1);

namespace amblydia\databaseapi\orm\attribute;

use Attribute;

#[Attribute]
class Size {

    /**
     * @param int $value
     */
    public function __construct(public int $value) {

    }
}