<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Testing;
final class TestCenterNavigation
{
    public static function sections(bool $detail):array
    {
        return $detail?['overview'=>'实例概览','workflow'=>'订单流程','measurement'=>'测量与代下单','aftersale'=>'售后与资金','messages'=>'消息与聊天','records'=>'检查与记录','tools'=>'测试入口与清理']:['instances'=>'实例管理','scenarios'=>'创建场景','coverage'=>'覆盖报告','environment'=>'环境诊断'];
    }
    public static function section($value,bool $detail):string
    {return is_string($value)&&isset(self::sections($detail)[$value])?$value:($detail?'overview':'instances');}
    public static function panels(string $section):array
    {return ['messages'=>['chat'=>'模拟会话','notices'=>'通知收件箱','delivery'=>'渠道与回执','regression'=>'聊天回归'],'records'=>['checks'=>'数据检查','runs'=>'执行记录','events'=>'订单事件','coverage'=>'执行覆盖','replay'=>'失败复现','scheduler'=>'计划任务']][$section]??[];}
    public static function panel(string $section,$value):string
    {$panels=self::panels($section);return is_string($value)&&isset($panels[$value])?$value:(string)(array_key_first($panels)??'');}
}
