<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function demoBoundaryRead(string $root, string $relative): string
{
    $content = @file_get_contents($root . '/' . $relative);
    if (!is_string($content)) {
        fwrite(STDERR, "[FAIL] 无法读取 {$relative}\n");
        exit(1);
    }
    return $content;
}

function demoBoundaryExpect(string $content, string $needle, string $label): void
{
    if (strpos($content, $needle) === false) {
        fwrite(STDERR, "[FAIL] {$label}：缺少 {$needle}\n");
        exit(1);
    }
    fwrite(STDOUT, "[PASS] {$label}\n");
}

function demoBoundaryExpectNot(string $content, string $needle, string $label): void
{
    if (strpos($content, $needle) !== false) {
        fwrite(STDERR, "[FAIL] {$label}：不应存在 {$needle}\n");
        exit(1);
    }
    fwrite(STDOUT, "[PASS] {$label}\n");
}

$discovery = demoBoundaryRead($root, 'server/app/Infrastructure/Persistence/DatabaseOrderDiscoveryRepository.php');
$detail = demoBoundaryRead($root, 'server/app/Infrastructure/Persistence/DatabaseOrderDetailRepository.php');
$decorator = demoBoundaryRead($root, 'server/app/Infrastructure/Persistence/NegotiationAwareOrderDetailRepository.php');
$workerDetail = demoBoundaryRead($root, 'miniprogram/pages/workerOrder/detail.wxml');

// 审核样例必须仍在 is_demo=1 数据域，仅列表与详情读取提供窄范围公开预览。
demoBoundaryExpect($discovery, "BXDEMOHALL", '接单大厅保留公开审核样例识别');
demoBoundaryExpect($detail, "DEMO_DATA_BOUNDARY", '通用订单详情仍保留真实/演示数据域隔离');
demoBoundaryExpect($decorator, 'isPublicHallDemoSample', '详情只读层存在公开审核样例判定');
demoBoundaryExpect($decorator, "strpos(\$publicNo, 'BXDEMOHALL') !== 0", '公开详情例外只匹配 BXDEMOHALL 前缀');
demoBoundaryExpect($decorator, "empty(\$row['is_demo'])", '公开详情例外仍要求订单属于演示数据域');
demoBoundaryExpect($decorator, "\$previewActor['is_demo_actor'] = true", '只在读取内部临时匹配演示数据域');
demoBoundaryExpect($decorator, "\$previewActor['worker_status'] = 'suspended'", '内部读取只降级报价资格，不丢失真实师傅身份');
demoBoundaryExpectNot($decorator, "\$previewActor['worker_id'] = 0", '公开预览不得清空真实 worker_id');
demoBoundaryExpect($decorator, "'can_start_worker_onboarding'", '公开预览返回师傅入驻 capability');
demoBoundaryExpect($decorator, "'worker_onboarding_label'", '公开预览返回入驻按钮文案');
demoBoundaryExpect($decorator, '申请成为师傅并审核通过后可报价', '普通用户仍按正常大厅规则提示先入驻再报价');
demoBoundaryExpect($decorator, '师傅资料未通过，修改并重新提交后可报价', '驳回师傅可回到正常入驻资料流程');
demoBoundaryExpect($decorator, '审核展示订单，不产生真实报价或抢单', '已审核师傅查看样例时明确保持只读');
demoBoundaryExpect($decorator, "['can_start_worker_onboarding']", '审核样例关闭业务动作时保留入驻 capability');
demoBoundaryExpect($workerDetail, 'wx:elif="{{actions.can_start_worker_onboarding}}"', '师傅详情底部优先显示入驻入口');
demoBoundaryExpect($workerDetail, 'url="/pages/plus/plus"', '详情入驻入口复用现有师傅入驻页面');
demoBoundaryExpect($workerDetail, "{{actions.worker_onboarding_label || '申请成为师傅后报价'}}", '详情按服务端 capability 展示入驻文案');

fwrite(STDOUT, "V2.5.0 public demo detail boundary regression: OK\n");