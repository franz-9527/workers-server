<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Testing;

final class TestScenarioCatalog
{
    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return [
            $this->ordinary('ordinary.construction.multi_quote.waiting.zero_quotes','普通施工 · 待报价（0 人）',0),
            $this->ordinary('ordinary.construction.multi_quote.waiting.one_quote','普通施工 · 待报价（1 人）',1),
            $this->ordinary('ordinary.construction.multi_quote.waiting.three_quotes','普通施工 · 待报价（3 人）',3),
            $this->singleGrab(),
            $this->measurement(),
            $this->negative('ordinary.construction.multi_quote.waiting.far_distance','普通施工 · 超距离未确认报价','far_distance','DISTANCE_ACK_REQUIRED'),
            $this->negative('ordinary.construction.multi_quote.waiting.pending_worker','普通施工 · 待审核师傅报价','pending_worker','WORKER_NOT_APPROVED'),
            $this->platform('platform.construction.multi_quote.waiting.zero_prepayment','平台施工 · 待报价（无外部预付款）',0),
            $this->platform('platform.construction.multi_quote.waiting.with_prepayment','平台施工 · 待报价（有外部预付款）',10000),
            [
                'key'=>'review.samples.health','version'=>1,'name'=>'审核展示样例健康检查',
                'family'=>'review_sample','workflow'=>'construction','dispatch_mode'=>'multi_quote','node'=>'health_check',
                'purpose'=>'只读检查 BXDEMOHALL01～06，不生成、不修改、不清理','buildable'=>false,'read_only'=>true,
                'blocked_reason'=>'只读健康检查通过测试中心首页诊断展示，不创建测试实例。','defaults'=>[],'editable'=>[],
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public function find(string $key): ?array
    {
        foreach($this->all() as $scenario)if(($scenario['key']??'')===$key)return$scenario;
        return null;
    }

    /** @return array<string,mixed> */
    private function ordinary(string $key,string $name,int $quoteCount): array
    {
        return [
            'key'=>$key,'version'=>1,'name'=>$name,'family'=>'ordinary','source'=>'consumer','workflow'=>'construction','dispatch_mode'=>'multi_quote','node'=>'waiting_quote',
            'purpose'=>'普通预约费内部模拟 / 当前表单夹具 / 大厅与报价列表页面','buildable'=>true,'read_only'=>false,'blocked_reason'=>'','quote_count'=>$quoteCount,
            'defaults'=>[
                'title'=>'测试普通施工单（'.$quoteCount.' 报价）','description'=>'TEST 场景数据，仅用于 testing 环境页面与流程验证。',
                'province'=>'浙江省','city'=>'杭州市','district'=>'拱墅区','address'=>'测试路 88 号（虚构地址）','lat'=>30.3192,'lng'=>120.1506,
            ],
            'editable'=>['title','description','province','city','district','address','lat','lng'],
        ];
    }

    /** @return array<string,mixed> */
    private function singleGrab(): array
    {
        return [
            'key'=>'ordinary.construction.single_grab.waiting.open','version'=>1,'name'=>'普通施工 · 单师傅抢单（待抢）',
            'family'=>'ordinary','source'=>'consumer','workflow'=>'construction','dispatch_mode'=>'single_grab','node'=>'waiting_grab',
            'purpose'=>'固定期望总价抢单 / 两师傅竞争 / assignment 唯一性 / 正式失败码','buildable'=>true,'read_only'=>false,'blocked_reason'=>'','quote_count'=>0,
            'expected_fee_type'=>'total','expected_fee_cents'=>52000,
            'defaults'=>[
                'title'=>'测试普通施工单（单师傅抢单）','description'=>'TEST 单师傅抢单场景；第一位师傅抢单即按期望总价成交，第二位师傅必须收到正式已被抢错误。',
                'province'=>'浙江省','city'=>'杭州市','district'=>'拱墅区','address'=>'测试路 88 号（虚构地址）','lat'=>30.3192,'lng'=>120.1506,'expected_fee_cents'=>52000,
            ],
            'editable'=>['title','description','province','city','district','address','lat','lng','expected_fee_cents'],
        ];
    }

    /** @return array<string,mixed> */
    private function measurement(): array
    {
        return [
            'key'=>'ordinary.measurement.multi_quote.waiting.zero_quotes','version'=>1,'name'=>'普通测量 · 待报价（0 人）',
            'family'=>'measurement','source'=>'consumer','workflow'=>'measurement','dispatch_mode'=>'multi_quote','node'=>'waiting_quote',
            'purpose'=>'测量报价 / 补齐测量服务款 / 测量成果 / 冻结结算 / 一对一关联施工','buildable'=>true,'read_only'=>false,'blocked_reason'=>'','quote_count'=>0,
            'defaults'=>[
                'title'=>'测试普通测量单（待报价）','description'=>'TEST 普通测量场景；后续真实推进测量报价、服务款、成果确认及关联施工。',
                'province'=>'浙江省','city'=>'杭州市','district'=>'拱墅区','address'=>'测试路 88 号（虚构地址）','lat'=>30.3192,'lng'=>120.1506,
            ],
            'editable'=>['title','description','province','city','district','address','lat','lng'],
        ];
    }

    /** @return array<string,mixed> */
    private function negative(string $key,string $name,string $probe,string $expectedCode): array
    {
        return [
            'key'=>$key,'version'=>1,'name'=>$name,'family'=>'ordinary','source'=>'consumer','workflow'=>'construction','dispatch_mode'=>'multi_quote','node'=>'waiting_quote',
            'purpose'=>'从真实普通待报价起点调用正式 quotes.create，验证服务端负向拒绝','buildable'=>true,'read_only'=>false,'blocked_reason'=>'','quote_count'=>0,
            'negative_probe'=>$probe,'expected_error_code'=>$expectedCode,
            'defaults'=>[
                'title'=>'测试普通施工负向报价（'.$probe.'）','description'=>'TEST 负向场景；生成后检查会调用正式报价服务并要求被拒绝。',
                'province'=>'浙江省','city'=>'杭州市','district'=>'拱墅区','address'=>'测试路 88 号（虚构地址）','lat'=>30.3192,'lng'=>120.1506,
            ],
            'editable'=>['title','description','province','city','district','address','lat','lng'],
        ];
    }

    /** @return array<string,mixed> */
    private function platform(string $key,string $name,int $externalPrepaymentCents): array
    {
        return [
            'key'=>$key,'version'=>1,'name'=>$name,'family'=>'platform','source'=>'platform','workflow'=>'construction','dispatch_mode'=>'multi_quote','node'=>'waiting_quote','purpose'=>'页面查看 / 数据结构 / 大厅公开条件',
            'buildable'=>true,'read_only'=>false,'blocked_reason'=>'',
            'defaults'=>[
                'title'=>$externalPrepaymentCents>0?'测试平台施工单（含外部预付款）':'测试平台施工单（无外部预付款）',
                'description'=>'TEST 场景数据，仅用于 testing 环境页面与流程验证。','province'=>'浙江省','city'=>'杭州市','district'=>'拱墅区','address'=>'测试路 88 号（虚构地址）',
                'lat'=>30.3192,'lng'=>120.1506,'contact_name'=>'测试客户','contact_mobile'=>'13900000001','customer_total_cents'=>50000,'external_prepayment_cents'=>$externalPrepaymentCents,'quote_days'=>7,
            ],
            'editable'=>['title','description','province','city','district','address','lat','lng','customer_total_cents'],
        ];
    }
}
