<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Admin;

use WorkerAdmin\Contracts\MiniAdminWorkerStoreRepository;
use WorkerAdmin\Domain\Admin\MiniAdminOperationException;

/** 小程序管理员的师傅/门店审核与维护用例。 */
final class MiniAdminWorkerStoreOperations
{
    private MiniAdminWorkerStoreRepository $repository;

    public function __construct(MiniAdminWorkerStoreRepository $repository)
    {
        $this->repository = $repository;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function execute(string $route, array $actor, array $input): array
    {
        switch ($route) {
            case 'admin.worker.update':
                return $this->updateWorker($input);
            case 'admin.worker.review':
                return $this->reviewWorker($input);
            case 'admin.worker.sms-stats':
                return ['message'=>'','data'=>$this->repository->workerSmsStats($actor, $this->cloudId($input, 'WORKER_SMS_STATS_TARGET_REQUIRED', '缺少师傅资料编号'))];
            case 'admin.store.review':
                return $this->reviewStore($input);
            case 'admin.review.records':
                return $this->reviewRecords($input);
        }
        throw new MiniAdminOperationException('接口不存在', 'NOT_FOUND', 404);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function updateWorker(array $input): array
    {
        $cloudId = $this->cloudId($input, 'WORKER_ADMIN_UPDATE_TARGET_REQUIRED', '缺少师傅资料编号');
        $source = is_array($input['patch'] ?? null) ? $input['patch'] : [];
        $allowed = [
            'workerName','workerType','skills','baseFeeAmount','paid','money','pay_oldwall','pay_oldwall_money',
            'pay_fixed','workerPaid','sell','sell_goods','content','startDate','endDate','workerTime','imgList','location',
        ];
        $patch = [];
        foreach ($allowed as $key) if (array_key_exists($key, $source)) $patch[$key] = $source[$key];
        if (!$patch) throw new MiniAdminOperationException('没有可保存的资料字段', 'WORKER_ADMIN_UPDATE_EMPTY');
        if (isset($patch['skills']) && !is_array($patch['skills'])) throw new MiniAdminOperationException('技能资料格式不正确', 'WORKER_ADMIN_UPDATE_SKILLS_INVALID');
        if (isset($patch['location']) && !is_array($patch['location'])) throw new MiniAdminOperationException('地址资料格式不正确', 'WORKER_ADMIN_UPDATE_LOCATION_INVALID');
        if (isset($patch['imgList']) && !is_array($patch['imgList'])) throw new MiniAdminOperationException('图片资料格式不正确', 'WORKER_ADMIN_UPDATE_IMAGES_INVALID');

        $result = $this->repository->updateWorker($cloudId, $patch);
        $worker = $result['worker'];
        return [
            'message'=>'资料已保存',
            'data'=>[
                'cloud_worker_id'=>$cloudId,
                'updated_fields'=>array_values(array_keys($patch)),
                'review_status'=>(string)($worker['reviewStatus'] ?? $worker['status'] ?? ''),
            ],
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function reviewWorker(array $input): array
    {
        $cloudId = $this->cloudId($input, 'WORKER_REVIEW_INVALID', '师傅记录或审核结果不正确');
        $decision = strtolower(trim((string)($input['decision'] ?? '')));
        $reason = mb_substr(trim((string)($input['reason'] ?? '')), 0, 500);
        if (!in_array($decision, ['approved','pending','rejected','suspended'], true)) {
            throw new MiniAdminOperationException('师傅记录或审核结果不正确', 'WORKER_REVIEW_INVALID');
        }
        if (in_array($decision, ['rejected','suspended'], true) && mb_strlen($reason) < 2) {
            throw new MiniAdminOperationException('驳回入驻或暂停接单时，请填写处理说明', 'WORKER_REVIEW_REASON_REQUIRED');
        }
        $result = $this->repository->reviewWorker($cloudId, $decision, $reason);
        $messages = [
            'approved'=>'审核通过，师傅现在可以接单报价',
            'pending'=>'已改为待审核，师傅暂时不能报价',
            'rejected'=>'已驳回入驻，原因已同步给师傅',
            'suspended'=>'已暂停接单，进行中订单仍可处理',
        ];
        return [
            'message'=>$messages[$decision],
            'data'=>[
                'worker'=>$result['worker'],
                'worker_status'=>$decision,
                'local_worker_id'=>(int)$result['local_worker_id'],
            ],
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function reviewStore(array $input): array
    {
        $cloudId = $this->cloudId($input, 'STORE_REVIEW_INVALID', '门店记录或审核结果不正确');
        $decision = strtolower(trim((string)($input['decision'] ?? '')));
        if (!in_array($decision, ['approved','pending'], true)) {
            throw new MiniAdminOperationException('门店记录或审核结果不正确', 'STORE_REVIEW_INVALID');
        }
        $data = $this->repository->reviewStore($cloudId, $decision);
        return [
            'message'=>$decision === 'approved' ? '门店已审核通过' : '门店已改为待审核',
            'data'=>[
                'cloud_store_id'=>$cloudId,
                'published'=>!empty($data['published']),
                'decision'=>$decision,
            ],
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function reviewRecords(array $input): array
    {
        $source = (string)($input['source'] ?? 'worker');
        if (!in_array($source, ['worker','store'], true)) {
            throw new MiniAdminOperationException('审核来源不正确', 'ADMIN_REVIEW_SOURCE_INVALID');
        }
        $page = max(1, (int)($input['page'] ?? 1));
        $pageSize = max(1, min(50, (int)($input['page_size'] ?? 20)));
        $keyword = mb_substr(trim((string)($input['keyword'] ?? '')), 0, 80);
        return ['message'=>'','data'=>$this->repository->reviewRecords($source, $page, $pageSize, $keyword)];
    }

    /** @param array<string,mixed> $input */
    private function cloudId(array $input, string $apiCode, string $message): string
    {
        $cloudId = mb_substr(trim((string)($input['cloud_id'] ?? $input['id'] ?? $input['cloud_worker_id'] ?? '')), 0, 128);
        if ($cloudId === '') throw new MiniAdminOperationException($message, $apiCode);
        return $cloudId;
    }
}
