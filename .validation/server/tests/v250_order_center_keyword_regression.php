<?php

declare(strict_types=1);

use WorkerAdmin\Application\Order\OrderCenterQuery;
use WorkerAdmin\Contracts\OrderCenterRepository;
use WorkerAdmin\Contracts\OrderMediaUrlSigner;
use WorkerAdmin\Contracts\PrivateValueCipher;

$root = dirname(__DIR__, 2);
require_once $root.'/server/app/Contracts/OrderCenterRepository.php';
require_once $root.'/server/app/Contracts/OrderMediaUrlSigner.php';
require_once $root.'/server/app/Contracts/PrivateValueCipher.php';
require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
require_once $root.'/server/app/Application/Order/OrderCenterQuery.php';

function centerKeywordSame($actual, $expected, string $label): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, '[FAIL] '.$label.' expected='.var_export($expected, true).' actual='.var_export($actual, true)."\n");
        exit(1);
    }
    fwrite(STDOUT, '[PASS] '.$label."\n");
}

final class FakeCenterKeywordRepository implements OrderCenterRepository
{
    public array $calls = [];
    public array $overrides = [];

    public function page(array $actor, string $kind, string $status, int $page, int $limit, string $keyword = ''): array
    {
        $this->calls[] = compact('actor', 'kind', 'status', 'page', 'limit', 'keyword');
        if ($kind !== 'mine') return ['rows' => [], 'has_more' => false];
        return ['rows' => [array_replace([
            'id' => 10,
            'user_id' => (int)$actor['user_id'],
            'public_no' => 'BXQBABC123',
            'title' => '墙布施工',
            'description' => '',
            'workflow_profile' => 'construction',
            'order_source_type' => 'consumer',
            'dispatch_mode' => 'multi_quote',
            'expected_fee_cents' => null,
            'expected_fee_text' => '',
            'category_names_snapshot' => '墙布',
            'category_name_snapshot' => '墙布',
            'district' => '浦东新区',
            'address_ciphertext' => '世纪花园 8号',
            'address_summary' => '世纪花园',
            'quantity_text' => '80㎡',
            'expected_visit_at' => null,
            'created_at' => null,
            'published_at' => null,
            'updated_at' => null,
            'quote_count' => 0,
            'own_quote_cents' => null,
            'fulfillment_status' => 'not_started',
            'assignment_status' => 'unassigned',
            'lifecycle_status' => 'active',
            'audit_status' => 'approved',
            'publish_status' => 'published',
            'quote_status' => 'quoting',
            'payment_status' => 'paid',
            'refund_status' => 'none',
            'selected_quote_id' => null,
            'active_repair_status' => '',
            'platform_pricing_reviewed' => 0,
            'platform_pricing_confirmation_pending' => 0,
            'platform_pricing_confirmed' => 0,
            'service_appointment_exists' => 0,
            'cover_media_id' => null,
        ], $this->overrides)], 'has_more' => false];
    }

    public function supplementCount(array $actor, string $kind): int { return 0; }
    public function todoCount(array $actor, string $kind): int { return 0; }
}

final class FakeCenterCipher implements PrivateValueCipher
{
    public function hmac(string $plainText, string $context): string { return ''; }
    public function encrypt(string $plainText, string $context): ?string { return $plainText; }
    public function decrypt(string $cipherText, string $context): string { return $cipherText; }
}

final class FakeCenterSigner implements OrderMediaUrlSigner
{
    public function sign(string $mediaPublicId, array $actor, int $orderId, string $entityType = 'order', ?int $entityId = null, ?string $shareHash = null): string
    {
        return '';
    }
}

$repo = new FakeCenterKeywordRepository();
$query = new OrderCenterQuery($repo, new FakeCenterCipher(), new FakeCenterSigner());
$actor = ['user_id' => 7, 'worker_id' => 88];

$result = $query->query($actor, 'mine', 'all', 1, "  浦东\n花园  ");
centerKeywordSame($repo->calls[0]['keyword'], '浦东 花园', 'owner keyword 去控制字符并 trim 后传给 Repository');
centerKeywordSame($result['keyword'], '浦东 花园', '响应回显实际搜索关键词');
centerKeywordSame($result['items'][0]['id'], 'BXQBABC123', '搜索结果仍使用统一订单卡片投影');
centerKeywordSame($result['items'][0]['address'], '世纪花园 8号', 'owner 搜索结果仍可解密自身订单地址');

$query->query($actor, 'worker', 'all', 1, '不应扩大师傅查询');
centerKeywordSame($repo->calls[1]['keyword'], '', 'worker center 忽略关键词，本轮不扩大师傅查询范围');

$long = str_repeat('a', 120);
$query->query($actor, 'mine', 'all', 1, $long);
centerKeywordSame(strlen($repo->calls[2]['keyword']), 80, 'ASCII 搜索词最多保留 80 字节');

$repositorySource = file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseOrderCenterRepository.php') ?: '';
$ownerPos = strpos($repositorySource, 'o.user_id=:center_user_id');
$keywordPos = strpos($repositorySource, 'o.public_no LIKE :center_keyword_no');
centerKeywordSame($ownerPos !== false && $keywordPos !== false && $ownerPos < $keywordPos, true, '关键词条件追加在 owner scope 之后');
centerKeywordSame(strpos($repositorySource, "COALESCE(o.address_summary,'')") !== false, true, '数据库搜索覆盖地址摘要');
centerKeywordSame(strpos($repositorySource, ':center_keyword_no') !== false && strpos($repositorySource, ':center_keyword_text') !== false, true, 'PDO 搜索使用不同命名占位符');
centerKeywordSame(strpos($repositorySource, "if (\$kind === 'mine' && \$keyword !== '')") !== false, true, 'Repository 仅对 mine 应用关键词 SQL');

$apiSource = file_get_contents($root.'/server/app/Http/Api/Modules/OrderCenterApiModule.php') ?: '';
centerKeywordSame(strpos($apiSource, "\$_GET['keyword']") !== false, true, 'orders.center API 接收 keyword');

fwrite(STDOUT, "V2.5.0 order center keyword regression OK\n");
$repo->overrides=['quote_count'=>3,'worker_quote_count'=>3,'selected_quote_id'=>99,'quote_status'=>'selected','assignment_status'=>'accepted'];
$selected=$query->query($actor,'mine','all',1,'')['items'][0];
centerKeywordSame($selected['worker_quote_count'],3,'选价后仍显示三人报价');
centerKeywordSame($selected['todo_action'],'查看服务进度','已选价不再要求选择报价');
$repo->overrides=['quote_count'=>3,'worker_quote_count'=>3];
centerKeywordSame($query->query($actor,'mine','all',1,'')['items'][0]['todo_action'],'选择报价','未选价仍引导选择');
$repo->overrides=['lifecycle_status'=>'cancelled'];
centerKeywordSame($query->query($actor,'mine','all',1,'')['items'][0]['todo_action'],'查看处理进度','结束订单不引导选价');
