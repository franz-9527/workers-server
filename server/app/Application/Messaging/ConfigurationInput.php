<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Messaging;
trait ConfigurationInput
{
    private function inputValue(array $input,string $key,string $default=''):string { return isset($input[$key])&&!is_array($input[$key])?trim((string)$input[$key]):$default; }
    private function inputArray(array $input,string $key):array { return isset($input[$key])&&is_array($input[$key])?$input[$key]:[]; }
}
