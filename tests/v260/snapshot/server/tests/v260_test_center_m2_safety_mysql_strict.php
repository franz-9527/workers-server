<?php

declare(strict_types=1);

set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{
    if(!(error_reporting()&$severity))return false;
    throw new ErrorException($message,0,$severity,$file,$line);
});
require __DIR__.'/v260_test_center_m2_safety_mysql.php';
