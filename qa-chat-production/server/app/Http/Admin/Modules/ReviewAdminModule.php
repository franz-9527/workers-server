<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use RuntimeException;
use Throwable;
use WorkerAdmin\Application\Admin\ReviewAdmin;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Media\UploadedImageInput;
use WorkerAdmin\Foundation\ReviewAdminComposition;
use WorkerAdmin\Http\Admin\AdminModule;

final class ReviewAdminModule implements AdminModule
{
    private const ROUTES = [
        'reviews','reviews.create','reviews.update','reviews.delete',
        'reviews.media.upload','reviews.media.delete','reviews.toggle',
    ];

    private Auth $auth;
    private ReviewAdmin $reviews;

    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    {
        $this->auth = $auth;
        $this->reviews = ReviewAdminComposition::build($db);
    }

    public function supports(string $route): bool
    {
        return in_array($route, self::ROUTES, true);
    }

    public function dispatch(string $route): void
    {
        $this->auth->requirePermission('reviews.manage');
        $expected = $route === 'reviews' ? 'GET' : 'POST';
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== $expected) {
            http_response_code(405);
            throw new RuntimeException('该操作只接受 '.$expected.' 请求。');
        }

        try {
            switch ($route) {
                case 'reviews': $this->listing(); return;
                case 'reviews.create': $this->create(); return;
                case 'reviews.update': $this->update(); return;
                case 'reviews.delete': $this->delete(); return;
                case 'reviews.media.upload': $this->uploadMedia(); return;
                case 'reviews.media.delete': $this->deleteMedia(); return;
                case 'reviews.toggle': $this->toggle(); return;
            }
            throw new RuntimeException('评价管理路由不存在。');
        } catch (RuntimeException $exception) {
            if ($expected === 'POST') {
                \flash('danger', $exception->getMessage());
                \redirect(\admin_url('reviews'));
            }
            throw $exception;
        } catch (Throwable $exception) {
            $requestId = \request_id();
            \write_log('admin-review-error', '['.date('c').'] request_id='.$requestId.' route='.$route.' '.$exception->getMessage()."\n");
            if ($expected === 'POST') {
                \flash('danger', '评价处理失败，请稍后重试。请求编号：'.$requestId);
                \redirect(\admin_url('reviews'));
            }
            http_response_code(500);
            echo \view('errors/500', ['pageTitle'=>'系统处理失败','message'=>'评价列表读取失败，请稍后重试。请求编号：'.$requestId]);
        }
    }

    private function listing(): void
    {
        $data = $this->reviews->listing(max(1, (int)($_GET['page'] ?? 1)), (bool)\setting('demo.data_enabled', true));
        $columns = [
            ['key'=>'public_no','label'=>'订单号'],['key'=>'worker_name','label'=>'师傅'],
            ['key'=>'attitude_rating','label'=>'态度'],['key'=>'craft_rating','label'=>'手艺'],
            ['key'=>'overall_rating','label'=>'综合'],['key'=>'content','label'=>'评价内容'],
            ['key'=>'is_auto','label'=>'自动评价','type'=>'bool'],['key'=>'status','label'=>'状态','type'=>'status'],
            ['key'=>'created_at','label'=>'时间','type'=>'datetime'],
        ];
        $result = [
            'rows'=>$data['rows'],'total'=>$data['total'],'page'=>$data['page'],
            'pages'=>$data['pages'],'per_page'=>$data['per_page'],
        ];
        echo \view('admin/resource-list', [
            'pageTitle'=>'评价管理','activeMenu'=>'reviews','result'=>$result,'resource'=>'reviews','columns'=>$columns,
            'mediaByReview'=>$data['media_by_review'],'reviewOrders'=>$data['review_orders'],
        ]);
    }

    private function create(): void
    {
        $result = $this->reviews->create(
            (int)$this->post('order_id'),
            (int)$this->post('attitude_rating', '5'),
            (int)$this->post('craft_rating', '5'),
            $this->post('content')
        );
        \audit('review.create', 'review', $result['review_id'], '管理员新增评价');
        \flash('success', '评价已新增，可继续上传评价图片。');
        \redirect(\admin_url('reviews'));
    }

    private function update(): void
    {
        $id = $this->id();
        $this->reviews->update($id,(int)$this->post('attitude_rating','5'),(int)$this->post('craft_rating','5'),$this->post('content'),$this->post('status'));
        \audit('review.update','review',$id,'管理员编辑评价');
        \flash('success','评价已保存。');
        \redirect(\admin_url('reviews'));
    }

    private function delete(): void
    {
        $id=$this->id();$reason=$this->post('reason');$this->reviews->delete($id,$reason);
        \audit('review.delete','review',$id,$reason);\flash('success','评价已删除，关联图片进入延迟清理。');\redirect(\admin_url('reviews'));
    }

    private function toggle(): void
    {
        $id=$this->id();$reason=$this->post('reason');$this->reviews->toggle($id,$reason);
        \audit('review.toggle','review',$id,$reason);\flash('success','评价展示状态已切换。');\redirect(\admin_url('reviews'));
    }

    private function uploadMedia(): void
    {
        $id=$this->id();$file=isset($_FILES['file'])&&is_array($_FILES['file'])?$_FILES['file']:[];
        $input=new UploadedImageInput(
            isset($file['tmp_name'])&&!is_array($file['tmp_name'])?(string)$file['tmp_name']:'',
            isset($file['size'])&&!is_array($file['size'])?(int)$file['size']:0,
            isset($file['error'])&&!is_array($file['error'])?(int)$file['error']:UPLOAD_ERR_NO_FILE,
            isset($file['name'])&&!is_array($file['name'])?(string)$file['name']:''
        );
        $admin=$this->auth->user();$this->reviews->uploadMedia($id,$input,(int)($admin['id']??0));
        \audit('review.media.upload','review',$id,'管理员上传评价图片');\flash('success','评价图片已压缩保存。');\redirect(\admin_url('reviews'));
    }

    private function deleteMedia(): void
    {
        $id=$this->id();$this->reviews->removeMedia($id,(int)$this->post('media_id'));
        \audit('review.media.delete','review',$id,'管理员移除评价图片');\flash('success','评价图片已移除。');\redirect(\admin_url('reviews'));
    }

    private function id(): int
    {
        return max(0,(int)($_GET['id']??$_POST['id']??0));
    }

    private function post(string $key,string $default=''): string
    {
        return isset($_POST[$key])&&!is_array($_POST[$key])?(string)$_POST[$key]:$default;
    }
}
