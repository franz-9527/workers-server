<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Testing;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;
use WorkerAdmin\Infrastructure\Scheduling\AutoAcceptanceProcessor;

/** 仅当前平台实例使用到期时钟夹具，复用正式自动验收单笔处理器，无客户身份。 */
final class TestingPlatformAutomaticAcceptance
{
    private Database $db;
    public function __construct(Database $db){$this->db=$db;}

    public function accept(int $instance):array
    {
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('仅允许 testing。','TEST_ACTION_PRODUCTION_BLOCKED',403);
        $i=$this->db->fetch('SELECT scenario_key FROM test_instances WHERE id=:id',['id'=>$instance]);
        if(!$i||!TestingPlatformScenario::supports((string)$i['scenario_key']))throw new TestCenterException('自动验收实例不属于平台测试场景。','TEST_PLATFORM_AUTO_ACCEPT_STATE',409);
        try{
            return(new AutoAcceptanceProcessor($this->db))->acceptTestInstance(
                $instance,
                true,
                static function():void{throw new RuntimeException('平台单不得生成普通佣金');},
                'platform'
            );
        }catch(TestCenterException $e){throw$e;}
        catch(RuntimeException $e){
            $message=$e->getMessage();
            $code=strpos($message,'未解决业务')!==false?'TEST_PLATFORM_AUTO_ACCEPT_BLOCKED':'TEST_PLATFORM_AUTO_ACCEPT_STATE';
            throw new TestCenterException($message,$code,409);
        }
    }
}
