<?php

declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function(int $severity,string $message,string $file,int $line): bool {
    if(!(error_reporting()&$severity))return false;
    throw new ErrorException($message,0,$severity,$file,$line);
});
require __DIR__.'/v260_test_center_dispute_finance_cleanup_mysql.php';
