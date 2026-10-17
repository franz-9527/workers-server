<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Order;

use RuntimeException;
use Throwable;

final class OrderOperationException extends RuntimeException
{
    private string $apiCode;
    private array $data;
    public function __construct(string $message,string $apiCode,int $httpStatus=422,array $data=[],?Throwable $previous=null){parent::__construct($message,$httpStatus,$previous);$this->apiCode=$apiCode;$this->data=$data;}
    public function apiCode():string{return$this->apiCode;}
    public function data():array{return$this->data;}
}
