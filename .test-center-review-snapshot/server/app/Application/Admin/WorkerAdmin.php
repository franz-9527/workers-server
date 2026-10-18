<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Admin;

use RuntimeException;
use Throwable;
use WorkerAdmin\Application\Configuration\WorkerRuntimeConfigSync;
use WorkerAdmin\Contracts\AdminWorkerRepository;
use WorkerAdmin\Contracts\WorkerCloudGateway;

final class WorkerAdmin
{
    private AdminWorkerRepository $workers;
    private WorkerCloudGateway $cloud;
    private WorkerRuntimeConfigSync $runtimeSync;

    public function __construct(AdminWorkerRepository $workers, WorkerCloudGateway $cloud, WorkerRuntimeConfigSync $runtimeSync)
    {
        $this->workers = $workers;
        $this->cloud = $cloud;
        $this->runtimeSync = $runtimeSync;
    }

    /** @return array<string,mixed> */
    public function listing(int $page, string $status, string $keyword): array
    {
        $page = max(1, $page);
        $status = mb_substr(trim($status), 0, 24);
        $keyword = mb_substr(trim($keyword), 0, 100);
        $fallback = [
            'items'=>[], 'total'=>0, 'page'=>$page, 'pages'=>1, 'page_size'=>20,
            'stats'=>['total'=>0,'pending'=>0,'approved'=>0,'rejected'=>0,'suspended'=>0,'legacy_records'=>0],
        ];
        $cloudError = '';
        try {
            $cloud = $this->cloud->invoke('admin.list', [
                'page'=>$page, 'page_size'=>20, 'status'=>$status, 'keyword'=>$keyword,
            ]);
        } catch (Throwable $exception) {
            $cloud = $fallback;
            $cloudError = $exception->getMessage();
        }

        $rows = [];
        foreach ((array)($cloud['items'] ?? []) as $cloudWorker) {
            if (!is_array($cloudWorker)) continue;
            $cloudId = (string)($cloudWorker['cloud_id'] ?? $cloudWorker['_id'] ?? '');
            $openid = (string)($cloudWorker['openid'] ?? $cloudWorker['_openid'] ?? '');
            $cloudWorker['local'] = $this->workers->localEnrichment($cloudId, $openid);
            $rows[] = $cloudWorker;
        }

        return [
            'result'=>[
                'rows'=>$rows,
                'total'=>(int)($cloud['total'] ?? 0),
                'page'=>(int)($cloud['page'] ?? $page),
                'pages'=>max(1, (int)($cloud['pages'] ?? 1)),
                'per_page'=>max(1, (int)($cloud['page_size'] ?? 20)),
            ],
            'stats'=>is_array($cloud['stats'] ?? null) ? $cloud['stats'] : $fallback['stats'],
            'cloud_error'=>$cloudError,
            'status'=>$status,
            'keyword'=>$keyword,
        ];
    }

    /** @return array{local_worker_id:int,cloud_worker:array<string,mixed>} */
    public function create(string $openid, array $input, int $adminId): array
    {
        $openid = trim($openid);
        if (preg_match('/^oyAoh[A-Za-z0-9_-]{15,59}$/', $openid) !== 1) {
            throw new RuntimeException('请输入 oyAoh 开头的本小程序真实 OpenID。');
        }
        $profile = $this->profile($input, true);
        $data = $this->cloud->invoke('admin.create', ['openid'=>$openid, 'profile'=>$profile]);
        $worker = is_array($data['worker'] ?? null) ? $data['worker'] : [];
        if (!$worker) throw new RuntimeException('云端新增已返回，但没有师傅资料，请检查云函数部署版本。');
        return ['local_worker_id'=>$this->workers->projectCloudWorker($worker, $adminId), 'cloud_worker'=>$worker];
    }

    /** @return array{local_worker_id:int,cloud_worker:array<string,mixed>} */
    public function update(string $cloudId, array $input, int $adminId): array
    {
        $cloudId = mb_substr(trim($cloudId), 0, 128);
        if ($cloudId === '') throw new RuntimeException('缺少云端师傅记录编号。');
        $profile = $this->profile($input, false);
        $data = $this->cloud->invoke('admin.update', ['id'=>$cloudId, 'patch'=>$profile]);
        $worker = is_array($data['worker'] ?? null) ? $data['worker'] : [];
        if (!$worker) throw new RuntimeException('云端保存已返回，但没有师傅资料，请检查云函数部署版本。');
        return ['local_worker_id'=>$this->workers->projectCloudWorker($worker, $adminId), 'cloud_worker'=>$worker];
    }

    public function delete(string $cloudId, string $reason): int
    {
        $cloudId = mb_substr(trim($cloudId), 0, 128);
        $reason = mb_substr(trim($reason), 0, 500);
        if ($cloudId === '' || mb_strlen($reason) < 2) throw new RuntimeException('请确认师傅并填写删除原因。');
        $this->cloud->invoke('admin.delete', ['id'=>$cloudId, 'reason'=>$reason]);
        return $this->workers->markDeleted($cloudId, $reason);
    }

    /** @return array{local_worker_id:int,cloud_worker:array<string,mixed>} */
    public function review(string $cloudId, string $decision, string $reason, int $adminId): array
    {
        $cloudId = mb_substr(trim($cloudId), 0, 128);
        $decision = strtolower(trim($decision));
        $reason = mb_substr(trim($reason), 0, 500);
        if ($cloudId === '' || !in_array($decision, ['approved','pending','rejected','suspended'], true)) {
            throw new RuntimeException('师傅记录或审核结果不正确。');
        }
        if (in_array($decision, ['rejected','suspended'], true) && mb_strlen($reason) < 2) {
            throw new RuntimeException('驳回或暂停时必须填写原因。');
        }
        $data = $this->cloud->invoke('admin.review', ['id'=>$cloudId, 'decision'=>$decision, 'reason'=>$reason]);
        $worker = is_array($data['worker'] ?? null) ? $data['worker'] : [];
        if (!$worker) throw new RuntimeException('云端审核已返回，但没有师傅资料，请检查云函数部署版本。');
        $localId = $this->workers->projectCloudWorker($worker, $adminId);
        if ($localId > 0) {
            $this->workers->recordReview($localId, $cloudId, $decision, $reason, $adminId, (string)($worker['workerName'] ?? ''));
        }
        return ['local_worker_id'=>$localId, 'cloud_worker'=>$worker];
    }

    /** @return array{runtime:array<string,mixed>,stats:array<string,mixed>} */
    public function cloudHealth(): array
    {
        $runtime = $this->runtimeSync->execute();
        $data = $this->cloud->invoke('admin.health');
        return [
            'runtime'=>is_array($runtime) ? $runtime : [],
            'stats'=>is_array($data['stats'] ?? null) ? $data['stats'] : [],
        ];
    }

    public function rejectLegacyMediaRoute(): void
    {
        throw new RuntimeException('该旧照片入口已停用。请在师傅管理的“编辑云端资料”中维护公开图片，保存后会直接写入云数据库。');
    }

    /** @return array<string,mixed> */
    private function profile(array $input, bool $requireImages): array
    {
        $name = mb_substr(trim((string)($input['display_name'] ?? '')), 0, 80);
        $type = mb_substr(trim((string)($input['worker_type'] ?? '')), 0, 160);
        if ($name === '' || $type === '') throw new RuntimeException('请填写师傅姓名和安装类型。');

        $mobile = preg_replace('/\D+/', '', (string)($input['mobile'] ?? ''));
        if ($mobile !== '' && preg_match('/^1\d{10}$/', $mobile) !== 1) throw new RuntimeException('手机号应为 11 位中国大陆手机号。');
        $lat = $input['lat'] ?? null;
        $lng = $input['lng'] ?? null;
        if (!is_numeric($lat) || (float)$lat < -90 || (float)$lat > 90) throw new RuntimeException('请填写 -90 到 90 之间的地图纬度。');
        if (!is_numeric($lng) || (float)$lng < -180 || (float)$lng > 180) throw new RuntimeException('请填写 -180 到 180 之间的地图经度。');

        $imageLines = preg_split('/\r\n|\r|\n/', trim((string)($input['img_list'] ?? ''))) ?: [];
        $images = [];
        foreach ($imageLines as $image) {
            $image = trim($image);
            if ($image === '') continue;
            if (preg_match('#^(?:cloud|https?)://#i', $image) !== 1) throw new RuntimeException('师傅图片须填写 HTTPS 地址或 cloud:// 文件 ID。');
            if (!in_array($image, $images, true)) $images[] = $image;
            if (count($images) >= 6) break;
        }
        if ($requireImages && !$images) throw new RuntimeException('新增师傅至少需要一张公开图片。');

        $skills = [];
        foreach (['doEVA','doCurtain','doFilm','doPaint','dotArtpaint','doWallPaneling'] as $skill) {
            $skills[$skill] = $this->boolInput($input[$skill] ?? null);
        }

        return [
            'workerName'=>$name,
            'workerType'=>$type,
            'mobile'=>$mobile,
            'startDate'=>mb_substr((string)($input['start_date'] ?? ''), 0, 32),
            'endDate'=>mb_substr((string)($input['end_date'] ?? ''), 0, 32),
            'workerTime'=>mb_substr((string)($input['worker_time'] ?? ''), 0, 100),
            'baseFeeAmount'=>mb_substr((string)($input['base_fee_amount'] ?? ''), 0, 100),
            'paid'=>$this->boolInput($input['paid'] ?? null),
            'money'=>mb_substr((string)($input['measure_fee'] ?? ''), 0, 100),
            'pay_fixed'=>$this->boolInput($input['pay_fixed'] ?? null),
            'workerPaid'=>mb_substr((string)($input['worker_paid'] ?? ''), 0, 100),
            'pay_oldwall'=>$this->boolInput($input['pay_oldwall'] ?? null),
            'pay_oldwall_money'=>mb_substr((string)($input['pay_oldwall_money'] ?? ''), 0, 100),
            'sell'=>$this->boolInput($input['sell'] ?? null),
            'sell_goods'=>mb_substr((string)($input['sell_goods'] ?? ''), 0, 500),
            'content'=>mb_substr((string)($input['content'] ?? ''), 0, 3000),
            'skills'=>$skills,
            'doEVA'=>$skills['doEVA'],
            'doCurtain'=>$skills['doCurtain'],
            'doFilm'=>$skills['doFilm'],
            'doPaint'=>$skills['doPaint'],
            'dotArtpaint'=>$skills['dotArtpaint'],
            'doWallPaneling'=>$skills['doWallPaneling'],
            'imgList'=>$images,
            'location'=>[
                'province'=>mb_substr((string)($input['province'] ?? ''), 0, 64),
                'city'=>mb_substr((string)($input['city'] ?? ''), 0, 64),
                'district'=>mb_substr((string)($input['district'] ?? ''), 0, 64),
                'name'=>mb_substr((string)($input['location_name'] ?? ''), 0, 200),
                'address'=>mb_substr((string)($input['address'] ?? ''), 0, 500),
                'adcode'=>mb_substr((string)($input['adcode'] ?? ''), 0, 16),
                'latitude'=>(float)$lat,
                'longitude'=>(float)$lng,
            ],
        ];
    }

    private function boolInput($value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['1','true','yes','on'], true);
    }
}
