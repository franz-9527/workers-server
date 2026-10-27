<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use RuntimeException;
use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Money\PaymentNotificationProcessor;
use WorkerAdmin\Foundation\ApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

/** wechatpay.notify 支付成功通知的正式 HTTP 适配。 */
final class PaymentNotificationApiModule implements ApiModule
{
    private PaymentNotificationProcessor $processor;

    public function __construct(Database $db, ?PaymentNotificationProcessor $processor = null)
    {
        $this->processor = $processor ?: (new ApplicationContainer($db))->paymentNotifications();
    }

    public function supports(string $route): bool
    {
        return $route === 'wechatpay.notify';
    }

    public function requiresContentSecurity(string $route): bool
    {
        return false;
    }

    public function dispatch(string $route): void
    {
        if (!$this->supports($route)) throw new RuntimeException('接口不存在');
        if (strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET')) !== 'POST') {
            $this->respond(false,'');
        }

        $raw = (string)file_get_contents('php://input');
        $headers = function_exists('getallheaders') ? (array)getallheaders() : $this->serverHeaders();
        try {
            $result = $this->processor->process($raw,$headers);
            if (!empty($result['auto_refund_error'])) {
                \write_log('auto-refund','['.date('c').'] '.(string)$result['auto_refund_error']."\n");
            }
            $this->respond(true,$raw);
        } catch (Throwable $exception) {
            \write_log('wechatpay-notify','['.date('c').'] '.$exception->getMessage()."\n");
            $this->respond(false,$raw);
        }
    }

    private function serverHeaders(): array
    {
        $headers=[];
        foreach ($_SERVER as $key=>$value) {
            if (strpos($key,'HTTP_')===0) $headers[str_replace('_','-',strtolower(substr($key,5)))]=$value;
        }
        return $headers;
    }

    /** v2 保持 XML 应答；v3 保持 JSON 应答。 */
    private function respond(bool $success, string $raw): void
    {
        if (strncmp(ltrim($raw),'<xml',4)===0) {
            http_response_code($success?200:500);
            header('Content-Type: text/xml; charset=utf-8');
            $code=$success?'SUCCESS':'FAIL';
            $message=$success?'成功':'通知处理失败';
            echo '<xml><return_code><![CDATA['.$code.']]></return_code><return_msg><![CDATA['.$message.']]></return_msg></xml>';
            exit;
        }
        \json_response(['code'=>$success?'SUCCESS':'FAIL','message'=>$success?'成功':'通知处理失败'],$success?200:500);
    }
}
