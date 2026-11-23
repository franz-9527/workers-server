<?php
declare(strict_types=1);
namespace WorkerAdmin\Domain\Chat;

final class ConversationScope
{
    public const FEEDBACK_SERVICE='platform_feedback';
    public const FEEDBACK_TEST_SERVICE='platform_feedback_test';

    private static function actor(string $value):string
    {
        if(!preg_match('/^[1-9][0-9]{0,19}$/D',$value)||(strlen($value)===20&&strcmp($value,'18446744073709551615')>0)){
            throw new \InvalidArgumentException('无效的会话绑定编号');
        }
        return $value;
    }

    public static function assignment(string $order,string $assignment):string
    {
        return 'order:'.self::actor($order).':assignment:'.self::actor($assignment);
    }

    public static function collaboration(string $order):string
    {
        return 'order:'.self::actor($order).':collaboration';
    }

    public static function pair(string $first,string $second):array
    {
        self::actor($first);
        self::actor($second);
        if($first===$second)throw new \InvalidArgumentException('不能与自己建立普通私聊');
        $order=strlen($first)<=>strlen($second);
        return ($order?:strcmp($first,$second))<0?[$first,$second]:[$second,$first];
    }

    public static function direct(string $first,string $second):string
    {
        return 'direct:'.implode(':',self::pair($first,$second));
    }

    public static function support(string $owner,bool $sandbox=false):string
    {
        return 'support:'.($sandbox?self::FEEDBACK_TEST_SERVICE:self::FEEDBACK_SERVICE).':'.self::actor($owner);
    }
}
