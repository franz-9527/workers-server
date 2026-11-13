<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Review;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\ReviewRepository;
use WorkerAdmin\Contracts\SettingsProvider;

/** 评价应用服务：校验双维度评分、公开评价展示和后台快捷短句配置。 */
final class ReviewManager
{
    private ReviewRepository $reviews;
    private SettingsProvider $settings;

    /** @var string[] */
    private array $defaultPhrases = ['服务态度好','沟通耐心','准时上门','施工专业','手艺细致','现场整洁','效果满意','值得推荐'];

    public function __construct(ReviewRepository $reviews, SettingsProvider $settings)
    {
        $this->reviews = $reviews;
        $this->settings = $settings;
    }

    /** @return array<string,mixed> */
    public function publicWorkerPage(string $workerReference, int $page = 1, int $limit = 10): array
    {
        $workerReference = trim($workerReference);
        if ($workerReference === '') throw new RuntimeException('缺少师傅编号', 422);
        $page = max(1, $page);
        $limit = max(5, min(20, $limit));
        $result = $this->reviews->publicWorkerPage($workerReference, $page, $limit);
        $summary = $result['summary'] ?? [];
        $reviewCount = (int)($summary['review_count'] ?? 0);
        $items = [];
        foreach (($result['rows'] ?? []) as $row) {
            $attitude = max(1, min(5, (int)($row['attitude_rating'] ?? 0)));
            $craft = max(1, min(5, (int)($row['craft_rating'] ?? 0)));
            $items[] = [
                'id' => (string)($row['public_id'] ?? ''),
                'reviewer' => $this->maskNickname((string)($row['nickname'] ?? '')),
                'attitude_rating' => $attitude,
                'craft_rating' => $craft,
                'overall_rating' => number_format(($attitude + $craft) / 2, 1, '.', ''),
                'content' => trim((string)($row['content'] ?? '')),
                'is_auto' => !empty($row['is_auto']),
                'reviewed_at' => $this->localTime((string)(($row['reviewed_at'] ?? '') ?: ($row['created_at'] ?? ''))),
                'order_no' => (string)($row['public_no'] ?? ''),
                'order_title' => mb_substr(trim((string)($row['title'] ?? '')), 0, 60),
            ];
        }
        return [
            'summary' => [
                'review_count' => $reviewCount,
                'attitude_average' => number_format($reviewCount > 0 ? (float)($summary['attitude_average'] ?? 0) : 0, 1, '.', ''),
                'craft_average' => number_format($reviewCount > 0 ? (float)($summary['craft_average'] ?? 0) : 0, 1, '.', ''),
                'overall_average' => number_format($reviewCount > 0 ? (float)($summary['overall_average'] ?? 0) : 0, 1, '.', ''),
            ],
            'items' => $items,
            'page' => $page,
            'has_more' => !empty($result['has_more']),
        ];
    }

    /** @return string[] */
    public function quickPhrases(): array
    {
        $raw = $this->settings->get('review.quick_phrases', null);
        $values = [];
        if (is_array($raw)) {
            $values = $raw;
        } elseif (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            $values = is_array($decoded) ? $decoded : preg_split('/[\r\n,，]+/u', $raw);
        }
        $clean = [];
        foreach ((array)$values as $value) {
            $text = mb_substr(trim((string)$value), 0, 30);
            if ($text !== '' && !in_array($text, $clean, true)) $clean[] = $text;
            if (count($clean) >= 20) break;
        }
        return $clean ?: $this->defaultPhrases;
    }

    /** @param array<string,mixed> $input @return array{review_id:string,overall_rating:string} */
    public function submit(string $token, array $input): array
    {
        $orderPublicNo = trim((string)($input['order_id'] ?? ''));
        if ($orderPublicNo === '') throw new RuntimeException('缺少订单编号', 422);
        if (!array_key_exists('attitude_rating', $input) || !array_key_exists('craft_rating', $input)) {
            throw new RuntimeException('请分别选择服务态度和施工手艺评分', 422);
        }
        $attitude = (int)$input['attitude_rating'];
        $craft = (int)$input['craft_rating'];
        if ($attitude < 1 || $attitude > 5 || $craft < 1 || $craft > 5) {
            throw new RuntimeException('服务态度和施工手艺都必须选择 1-5 星', 422);
        }
        $content = mb_substr(trim((string)($input['content'] ?? '')), 0, 2000);
        $reviewId = $this->reviews->createUserReview($token, $orderPublicNo, $attitude, $craft, $content);
        return ['review_id' => $reviewId, 'overall_rating' => number_format(($attitude + $craft) / 2, 1, '.', '')];
    }

    public function createAutoForOrder(int $orderId, string $content = '系统自动默认好评'): bool
    {
        if ($orderId < 1) return false;
        return $this->reviews->createAutoForOrder($orderId, mb_substr(trim($content), 0, 2000));
    }

    public function refreshWorkerSummary(int $workerId): void
    {
        if ($workerId > 0) $this->reviews->refreshWorkerSummary($workerId);
    }

    private function maskNickname(string $nickname): string
    {
        $nickname = trim($nickname);
        return $nickname === '' ? '用户***' : mb_substr($nickname, 0, 1) . '***';
    }

    private function localTime(string $utc): string
    {
        if ($utc === '') return '';
        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i');
        } catch (Throwable $exception) {
            return $utc;
        }
    }
}
