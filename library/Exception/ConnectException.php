<?php
declare(strict_types=1);
namespace Coinsnap\Exception;

class ConnectException extends CSException {
    public function __construct(string $connectErrorMessage, int $connectErrorCode){
        parent::__construct($connectErrorMessage, $connectErrorCode);
    }
}
