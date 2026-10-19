<?php
declare(strict_types=1);
namespace Brightery\Core\Developer;
/** App-owned policies may reject already normalized form values. Never rely on browser validation alone. */
interface CrudValidationPolicy {
    /** @throws \InvalidArgumentException */
    public function validate(array $schema,array $values,bool $partial):void;
}
