<?php

use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Core\View;

// 2026-08-14：集中提供后台通用帮助函数，避免页面重复处理配置、转义与安全逻辑。
function base_path($path = '')
{
    $base = defined('WORKER_BASE_PATH') ? WORKER_BASE_PATH : dirname(__DIR__, 2);
    return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
}

function app_config($key = null, $default = null)
{
    $config = isset($GLOBALS['worker_admin_config']) ? $GLOBALS['worker_admin_config'] : [];
    if ($key === null || $key === '') {
        return $config;
    }

    $value = $config;
    foreach (explode('.', (string) $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}

function is_installed()
{
    return (bool) app_config('installed', false)
        && is_file(base_path('config/installed.php'))
        && is_file(base_path('storage/install.lock'));
}

// 2026-08-14：发布新代码后若配置版本尚未同步，后台仅允许登录并进入升级页，API 与计划任务保持暂停。
function upgrade_required()
{
    return !empty($GLOBALS['worker_admin_upgrade_required']);
}

function db()
{
    static $database;
    if (!$database) {
        $config = (array) app_config('database', []);
        if (empty($config['database']) && !empty($config['name'])) {
            $config['database'] = $config['name'];
        }
        $database = new Database($config);
    }
    return $database;
}

function auth()
{
    static $auth;
    if (!$auth) {
        $auth = new Auth(db());
    }
    return $auth;
}

function csrf()
{
    static $csrf;
    if (!$csrf) {
        $csrf = new Csrf();
    }
    return $csrf;
}

function view($template, array $data = [], $layout = 'layouts/admin')
{
    return (new View(base_path('views')))->render($template, $data, $layout);
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_url($path = '')
{
    $base = rtrim((string) app_config('app.url', ''), '/');
    if ($base === '' && PHP_SAPI !== 'cli') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $base = ($https ? 'https://' : 'http://') . $host;
    }
    return $base . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

function admin_url($route = 'dashboard', array $params = [])
{
    $params = array_merge(['route' => $route], $params);
    return app_url('admin/?' . http_build_query($params));
}

function asset_url($path)
{
    // 后台热修不提升数据库版本时，追加文件指纹刷新 CSS/JS，避免浏览器或 CDN 继续命中旧 2.4.0 静态资源。
    $version = (string) app_config('app.version', app_config('version', '2.4.0'));
    $relative = ltrim((string) $path, '/');
    $assetPath = dirname(__DIR__, 2) . '/assets/' . $relative;
    $fingerprint = '';
    if (is_file($assetPath)) {
        $mtime = @filemtime($assetPath);
        $size = @filesize($assetPath);
        if ($mtime !== false && $size !== false) $fingerprint = '-' . (string) $mtime . '-' . (string) $size;
    }
    return app_url('assets/' . $relative) . '?v=' . rawurlencode($version . $fingerprint);
}

function redirect($url, $status = 302)
{
    header('Location: ' . $url, true, $status);
    exit;
}

function json_response(array $data, $status = 200)
{
    http_response_code((int) $status);
    header('Content-Type: application/json; charset=utf-8');
    // 2026-09-22：鉴权 API 可能位于 ESA/CDN 后方；显式禁止浏览器、代理和共享缓存保存或串用敏感响应。
    header('Cache-Control: no-store, private, max-age=0');
    header('Surrogate-Control: no-store');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Vary: Authorization', false);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_id()
{
    static $id;
    if (!$id) {
        try {
            $id = bin2hex(random_bytes(8));
        } catch (Exception $exception) {
            $id = str_replace('.', '', uniqid('', true));
        }
    }
    return $id;
}

function request_ip()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
    return substr($ip, 0, 45);
}

function write_log($channel, $message)
{
    $directory = base_path('storage/logs');
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }
    @file_put_contents($directory . DIRECTORY_SEPARATOR . preg_replace('/[^a-z0-9_-]/i', '', $channel) . '.log', $message, FILE_APPEND | LOCK_EX);
}

function flash($type = null, $message = null)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    if ($type !== null && $message !== null) {
        $_SESSION['_flash'][$type][] = (string) $message;
        return null;
    }
    $messages = isset($_SESSION['_flash']) ? $_SESSION['_flash'] : [];
    unset($_SESSION['_flash']);
    return $messages;
}

function old_input(array $values = null)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return [];
    }
    if ($values !== null) {
        $_SESSION['_old_input'] = $values;
        return [];
    }
    $old = isset($_SESSION['_old_input']) ? $_SESSION['_old_input'] : [];
    unset($_SESSION['_old_input']);
    return $old;
}

function money($cents)
{
    return '¥' . number_format(((int) $cents) / 100, 2);
}

// 2026-08-14：数据库统一保存 UTC，后台所有可见时间通过此函数转换为站点时区，避免中国管理员看到时间少八小时。
function local_datetime($value, $format = 'Y-m-d H:i')
{
    $value = trim((string) $value);
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return '—';
    }
    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $date->setTimezone(new DateTimeZone((string) app_config('app.timezone', 'Asia/Shanghai')))->format((string) $format);
    } catch (Throwable $exception) {
        return $value;
    }
}

// 2026-08-14：生成不含易混淆字符的 26 位公开标识，数据库只暴露该标识而不暴露自增主键。
function public_id()
{
    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $bytes = random_bytes(26);
    $id = '';
    for ($index = 0; $index < 26; $index++) {
        $id .= $alphabet[ord($bytes[$index]) & 31];
    }
    return $id;
}

// 2026-08-14：业务单号包含类型、UTC 时间与随机尾号，便于人工检索且避免并发冲突。
function business_no($prefix)
{
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $prefix))
        . gmdate('YmdHis')
        . substr(public_id(), -6);
}

// 新订单号：BX + 两位类目码 + YYMMDD + '-' + 六位安全随机码。历史订单号保持不变。
// 生成函数不访问数据库；订单表 public_no 唯一索引与写入端重试负责处理极少数碰撞。
function order_business_no($categoryCode)
{
    $code = strtoupper(preg_replace('/[^A-Z]/i', '', (string) $categoryCode));
    $code = strlen($code) === 2 ? $code : 'QT';
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $suffix = '';
    for ($i = 0; $i < 6; $i++) {
        $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    $date = (new DateTimeImmutable('now', new DateTimeZone((string) app_config('app.timezone', 'Asia/Shanghai'))))->format('ymd');
    return 'BX' . $code . $date . '-' . $suffix;
}

// 2026-08-23：后台新增订单和云数据同步统一生成姓名、手机号的最小必要脱敏展示值。
function mask_private_name($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    $first = function_exists('mb_substr') ? mb_substr($value, 0, 1, 'UTF-8') : substr($value, 0, 1);
    return $first . str_repeat('*', max(1, min(3, $length - 1)));
}

function mask_private_mobile($value)
{
    $digits = preg_replace('/\D+/', '', (string) $value);
    if (strlen($digits) < 7) {
        return $digits === '' ? '' : substr($digits, 0, 2) . '***';
    }
    return substr($digits, 0, 3) . str_repeat('*', max(4, strlen($digits) - 7)) . substr($digits, -4);
}

// 2026-08-23：使用安装时生成的站点密钥加密微信支付、短信、云开发和订单隐私字段。
function encrypt_private_value($value, $context = 'private')
{
    $value = (string) $value;
    if ($value === '') {
        return null;
    }
    $appKey = (string) app_config('app.key', '');
    if ($appKey === '' || !function_exists('openssl_encrypt')) {
        throw new RuntimeException('站点加密密钥或 OpenSSL 不可用，无法保存敏感内容。');
    }
    $key = hash('sha256', $appKey, true);
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, (string) $context, 16);
    if (!is_string($ciphertext)) {
        throw new RuntimeException('敏感内容加密失败。');
    }
    return 'enc:v1:' . base64_encode($nonce . $tag . $ciphertext);
}

function decrypt_private_value($value, $context = 'private')
{
    $value = (string) $value;
    if (strpos($value, 'enc:v1:') !== 0) {
        return $value;
    }
    $payload = base64_decode(substr($value, 7), true);
    $appKey = (string) app_config('app.key', '');
    if (!is_string($payload) || strlen($payload) < 29 || $appKey === '' || !function_exists('openssl_decrypt')) {
        return '';
    }
    $plaintext = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', hash('sha256', $appKey, true), OPENSSL_RAW_DATA, substr($payload, 0, 12), substr($payload, 12, 16), (string) $context);
    return is_string($plaintext) ? $plaintext : '';
}

function private_value_hmac($value, $context = 'private')
{
    return hash_hmac('sha256', (string) $value, hash('sha256', (string) app_config('app.key', ''), true) . (string) $context);
}

function status_meta($status)
{
    $map = [
        'pending' => ['待处理', 'warning'], 'pending_review' => ['待审核', 'warning'],
        'approved' => ['已通过', 'success'], 'active' => ['正常', 'success'],
        'published' => ['已公开', 'info'], 'paid' => ['已支付', 'success'],
        'completed' => ['已完成', 'success'], 'success' => ['成功', 'success'],
        'rejected' => ['已驳回', 'danger'], 'cancelled' => ['已取消', 'muted'],
        'canceled' => ['已取消', 'muted'], 'disabled' => ['已停用', 'muted'],
        'failed' => ['失败', 'danger'], 'refunding' => ['退款中', 'warning'],
        'unpaid' => ['未支付', 'muted'], 'processing' => ['处理中', 'info'],
        'open' => ['处理中', 'warning'], 'resolved' => ['已解决', 'success'],
        'hidden' => ['已隐藏', 'muted'], 'visible' => ['显示', 'success'],
        'waiting' => ['等待报价', 'warning'], 'collecting' => ['报价中', 'info'],
        'offered' => ['待师傅接单', 'warning'], 'accepted' => ['已接受', 'success'],
        'supplement_required' => ['待补资料', 'warning'], 'suspended' => ['已暂停', 'danger'],
        'none' => ['无', 'muted'], 'created' => ['已创建', 'warning'],
        'cancel_approved_pending_refund' => ['已取消待退款', 'warning'],
        'succeeded' => ['退款成功', 'success'], 'archived' => ['已归档', 'muted'],
        'draft' => ['草稿', 'warning'], 'payment_failed' => ['付款失败', 'danger'],
        'claimed' => ['已领取', 'success'], 'revoked' => ['已撤销', 'muted'],
        'expired' => ['已过期', 'muted'], 'overdue' => ['已逾期', 'danger'],
        'acknowledged' => ['已知悉', 'info'],
        'quoting' => ['报价中', 'info'], 'selected' => ['已选报价', 'success'],
        'unassigned' => ['待安排', 'warning'], 'assigned' => ['已安排', 'info'],
        'info_released' => ['已开放信息', 'success'], 'not_started' => ['未开始', 'muted'],
        'scheduled' => ['已预约', 'info'], 'refunded' => ['已退款', 'success'],
        'awaiting_payment' => ['待付预约费', 'warning'], 'in_progress' => ['施工中', 'info'],
        'submitted' => ['待验收', 'warning'], 'confirmed' => ['已确认', 'success'],
        'settled' => ['已抵扣结清', 'success'], 'released' => ['已释放', 'muted'],
        'deleted' => ['已删除', 'muted'], 'user' => ['用户责任', 'danger'],
        'worker' => ['师傅责任', 'danger'], 'platform' => ['平台责任', 'warning'],
        'shared' => ['双方协商', 'info'], 'unclear' => ['责任不清', 'warning'],
        'undetermined' => ['待判定', 'warning'], 'user_schedule_change' => ['用户行程原因', 'info'],
        'draft_pending_upload' => ['待上传图片', 'warning'], 'pending_payment' => ['待付款', 'warning'],
        'selected_pending_payment' => ['已选报价待付款', 'warning'], 'reviewed' => ['已审核', 'success'],
        'pending_contact_release' => ['待开放联系信息', 'warning'], 'completed_with_delay' => ['完成但尾款延期', 'warning'],
        'booking_fee' => ['预约费', 'info'], 'commission' => ['平台服务费', 'info'],
        'cancel_refund' => ['申请退款并取消订单', 'warning'], 'change_rejected' => ['拒绝加价', 'warning'],
        // 2026-08-28：补齐后台资源列表、支付、派单和通知中可能出现的枚举中文名。
        'closed' => ['已关闭', 'muted'], 'requested' => ['待提交', 'warning'],
        'received' => ['已接收', 'info'], 'sent' => ['已发送', 'success'], 'read' => ['已读', 'success'],
        'unknown' => ['未确定', 'muted'], 'recorded' => ['已记录', 'info'], 'effective' => ['已生效', 'success'],
        'locked' => ['已锁定', 'success'], 'superseded' => ['已被新版本替代', 'muted'],
        'order_booking' => ['订单预约费', 'info'], 'measurement_balance' => ['测量选师傅补款', 'info'], 'measurement_change' => ['测量现场加费', 'info'], 'booking_fee_paid' => ['预约费已支付', 'success'],
        'worker_quote' => ['师傅报价成交', 'info'], 'admin_assignment' => ['管理员指派', 'info'],
        'admin' => ['管理员指派', 'info'], 'invite' => ['邀请师傅接单', 'info'],
        'ordinary_order' => ['普通订单分享', 'info'], 'worker_invite' => ['邀请师傅分享', 'info'],
        'run_empty_fee' => ['跑空费', 'warning'], 'auto_72h' => ['72小时自动验收', 'info'],
        'schedule_changed' => ['行程变化', 'info'], 'forced_cancel_refund' => ['强制取消并退款', 'danger'],
        'change_accepted' => ['同意加价', 'success'], 'construction_issue' => ['施工问题', 'warning'],
        // 2026-08-29：尾款延期提交即为已登记，只有放弃收款才标记坏账。
        'reported' => ['已登记', 'info'], 'bad_debt' => ['已标记坏账', 'danger'],
        // 2026-09-09：实际尾款先自动生效，再由管理员抽查。
        'auto_approved' => ['自动预审已生效', 'success'],
        // 2026-09-15：补齐 V2.2.1 预计服务费在验收前的中文状态。
        'estimate' => ['预计服务费待缴', 'warning'], 'estimate_paid' => ['预计服务费部分已缴', 'info'],
        'estimate_settled' => ['预计服务费已结清', 'success'],
    ];
    return isset($map[$status]) ? $map[$status] : [$status ?: '—', 'muted'];
}

// 2026-08-25：模板只需要中文状态文字时统一走同一份映射，避免列表与详情翻译不一致。
function status_text($status)
{
    $meta = status_meta($status);
    return $meta[0];
}

// 2026-08-30：订单专用状态按小程序列表的业务优先级计算，避免 active 被笼统翻译成“正常”。
function order_business_status_meta(array $order)
{
    $refundStatus = (string) ($order['refund_status'] ?? '');
    $lifecycleStatus = (string) ($order['lifecycle_status'] ?? 'active');
    $auditStatus = (string) ($order['audit_status'] ?? '');
    $paymentStatus = (string) ($order['payment_status'] ?? '');
    $fulfillmentStatus = (string) ($order['fulfillment_status'] ?? '');
    $assignmentStatus = (string) ($order['assignment_status'] ?? '');
    $quoteStatus = (string) ($order['quote_status'] ?? '');
    $dispatchMode = (string) ($order['dispatch_mode'] ?? 'multi_quote');
    $expectedFeeType = (string) ($order['expected_fee_type'] ?? '');
    $expectedFeeCents = max(0, (int) ($order['expected_fee_cents'] ?? 0));

    if (in_array($refundStatus, ['created', 'pending_review', 'approved', 'processing', 'refunding'], true) || $lifecycleStatus === 'cancel_approved_pending_refund') return ['退款处理中', 'warning'];
    if ($refundStatus === 'succeeded') return ['已退款', 'success'];
    if ($lifecycleStatus === 'expired') return ['已到期取消', 'warning'];
    if ($lifecycleStatus === 'cancelled') return ['已取消', 'danger'];
    if ($lifecycleStatus === 'completed') return ['已完成', 'success'];
    if ($auditStatus === 'draft' && $paymentStatus === 'awaiting_payment') return ['待付预约费', 'warning'];
    if ($auditStatus === 'draft') return ['草稿', 'muted'];
    if ($auditStatus === 'pending') return ['待审核', 'warning'];
    if ($auditStatus === 'supplement_required') return ['待补充资料', 'warning'];
    if ($auditStatus === 'rejected') return ['审核未通过', 'danger'];
    if ($fulfillmentStatus === 'accepted') return ['已验收', 'success'];
    if ($fulfillmentStatus === 'submitted') return ['待验收', 'warning'];
    if ($fulfillmentStatus === 'in_progress') return ['施工中', 'info'];
    if ($fulfillmentStatus === 'scheduled') return ['已预约', 'info'];
    if ($paymentStatus === 'awaiting_payment') return ['待付预约费', 'warning'];
    if ($assignmentStatus === 'accepted') {
        if ($dispatchMode === 'single_grab') return [$expectedFeeType === 'total' && $expectedFeeCents > 0 ? '已按期望总价抢单，待施工' : '抢单报价已成交，待施工', 'info'];
        return ['已安排师傅，待施工', 'info'];
    }
    if (in_array($assignmentStatus, ['offered', 'assigned'], true)) {
        if ($dispatchMode === 'single_grab' && $assignmentStatus === 'offered') return ['师傅已抢单，待沟通报价', 'warning'];
        return ['已安排师傅，待接单', 'warning'];
    }
    if (!empty($order['selected_quote_id']) || $quoteStatus === 'selected') return ['已选报价，待安排师傅', 'warning'];
    if (in_array($quoteStatus, ['quoting', 'collecting'], true)) return [$dispatchMode === 'single_grab' ? '等待师傅抢单' : '报价中', 'info'];
    return [$dispatchMode === 'single_grab' ? '等待师傅抢单' : '等待报价', 'info'];
}

// 2026-08-28：后台订单时间线统一显示中文事件标题；事件正文继续展示具体金额、原因和处理结果。
function order_event_text($eventType)
{
    $map = [
        'draft_created'=>'订单草稿已保存','admin_created'=>'管理员新增订单','admin_updated'=>'管理员修改订单资料',
        'order_ready_for_prepayment'=>'等待支付预约费','order_submitted'=>'订单已提交审核','order_approved'=>'审核通过并公开',
        'order_supplement_required'=>'要求补充资料','order_supplemented'=>'用户补充资料并重新提交','order_rejected'=>'订单审核未通过','order_published'=>'订单公开到大厅','order_hidden'=>'订单从大厅隐藏','order_edited'=>'订单资料已修改',
        'worker_quoted'=>'师傅提交报价','worker_quote_updated'=>'师傅修改报价','platform_quoted'=>'平台提交报价','platform_quote_created'=>'平台新增报价','quote_selected_pending_payment'=>'用户选择报价',
        'booking_fee_paid'=>'预约费支付成功','booking_fee_paid_upfront'=>'下单预约费支付成功','payment_reservation_released'=>'支付占位已释放',
        'worker_invite_created'=>'生成邀请师傅分享','invite_claimed'=>'受邀师傅领取订单','worker_assigned'=>'管理员安排师傅','order_grabbed'=>'师傅抢单成功','single_grab_quote_confirmed'=>'抢单报价直接成交','single_grab_fixed_price_accepted'=>'按期望总价抢单成交','assignment_accepted'=>'师傅确认接单','contact_revealed'=>'完整联系信息已开放',
        'appointment_recorded'=>'电话预约已记录','exceptional_arrival_reported'=>'到场异常已提交','change_requested'=>'师傅申请加价','change_accepted'=>'用户同意加价','change_rejected'=>'用户拒绝加价',
        'measurement_service_paid'=>'测量服务款已补齐','measurement_change_accepted_pending_payment'=>'测量加费待支付','measurement_change_paid'=>'测量现场加费已支付','measurement_change_refund_created'=>'测量加费已登记退款','measurement_change_refunded'=>'测量加费已退款','measurement_late_balance_refund_created'=>'测量补款已登记退款','measurement_late_balance_refunded'=>'测量补款已退款',
        'measurement_result_submitted'=>'测量成果已提交','measurement_result_accepted'=>'测量成果已确认','measurement_auto_accepted'=>'测量成果自动确认','measurement_rework_requested'=>'用户要求补测',
        'measurement_hold_extension_requested'=>'申请延长测量冻结期','measurement_hold_extension_approved'=>'测量冻结延期已批准','measurement_hold_extension_rejected'=>'测量冻结延期未批准',
        'measurement_income_released'=>'测量收入已释放','measurement_construction_linked'=>'测量单已关联施工',
        'offline_collection_delayed'=>'线下尾款延期已登记','offline_collection_bad_debt'=>'线下尾款已标记坏账','completion_submitted'=>'师傅提交完工','completion_accepted_by_user'=>'用户确认验收','auto_completion_accepted'=>'系统自动验收','auto_accepted_72h'=>'72小时自动验收',
        'auto_reviewed_5d'=>'5天自动评价','review_submitted'=>'用户提交评价','commission_receivable_created'=>'生成平台服务费账单','commission_paid'=>'平台服务费已结清',
        'cancel_requested'=>'用户申请退款取消','cancel_approved'=>'管理员同意取消退款','admin_cancelled'=>'管理员强制取消','forced_cancel_refund'=>'管理员强制取消退款','auto_cancelled_7d'=>'7天未成交自动取消',
        'cancel_rejected'=>'取消退款申请未通过','cancel_approved'=>'取消退款审核通过','refund_succeeded'=>'预约费退款成功','dispute_opened'=>'订单进入争议处理','dispute_resolved'=>'订单争议已结案','order_demo_seeded'=>'测试流程数据建立'
    ];
    return isset($map[$eventType]) ? $map[$eventType] : '订单流程记录';
}

function audit($action, $resourceType, $resourceId = null, $description = '', $before = null, $after = null)
{
    if (!is_installed()) {
        return;
    }
    try {
        $admin = auth()->user();
        // 2026-08-14：审计字段与不可变审计表保持一致，记录操作者快照及业务对象。
        db()->execute(
            "INSERT INTO audit_logs (request_id, actor_type, actor_id, actor_name_snapshot, action, entity_type, entity_id, before_json, after_json, reason, ip_address, user_agent, result, created_at) VALUES (:request_id,'admin',:actor_id,:actor_name,:action,:entity_type,:entity_id,:before_json,:after_json,:reason,:ip,:ua,'success',NOW())",
            [
                'actor_id' => $admin ? $admin['id'] : null,
                'actor_name' => $admin ? ($admin['real_name'] ?: $admin['username']) : 'system',
                'action' => $action,
                'entity_type' => $resourceType,
                'entity_id' => $resourceId === null ? null : (string) $resourceId,
                'reason' => $description,
                'before_json' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
                'after_json' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
                'ip' => request_ip(),
                'ua' => substr(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'cli', 0, 500),
                'request_id' => request_id(),
            ]
        );
    } catch (Throwable $exception) {
        write_log('audit', '[' . date('c') . '] ' . $exception->getMessage() . "\n");
    }
}

function setting($key, $default = null)
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $row = db()->fetch('SELECT setting_value, value_type FROM system_settings WHERE setting_key = :key LIMIT 1', ['key' => $key]);
        if (!$row) {
            return $cache[$key] = $default;
        }
        $value = $row['setting_value'];
        // 2026-08-25：新设置按运营要求明文保存；这里继续兼容读取升级前已有的 enc:v1 密文。
        if (is_string($value) && strpos($value, 'enc:v1:') === 0) {
            $value = decrypt_private_value($value, 'system-setting:' . (string) $key);
        }
        if (in_array($row['value_type'], ['int', 'integer'], true)) {
            $value = (int) $value;
        } elseif (in_array($row['value_type'], ['bool', 'boolean'], true)) {
            $value = in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
        } elseif ($row['value_type'] === 'json') {
            $decoded = json_decode((string) $value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
        }
        return $cache[$key] = $value;
    } catch (Throwable $exception) {
        return $default;
    }
}
