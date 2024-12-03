<?php
declare(strict_types = 1);

namespace amblydia\databaseapi\orm\traits;

use amblydia\databaseapi\orm\attribute\Constraints;

trait Mappable {

    /** @var string|null */
    #[Constraints(["UNIQUE"])]
    protected ?string $objectId = null;

    /**
     * @return string|null
     */
    public function getObjectId(): ?string{
        return $this->objectId;
    }

    /**
     * @param string $objectId
     */
    public function setObjectId(string $objectId): void{
        $this->objectId = $objectId;
    }

}