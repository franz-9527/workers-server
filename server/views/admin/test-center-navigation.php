<?php
$isDetail=is_array($instance??null);$tabs=\WorkerAdmin\Infrastructure\Testing\TestCenterNavigation::sections($isDetail);
$section=\WorkerAdmin\Infrastructure\Testing\TestCenterNavigation::section($section??null,$isDetail);
?>
<link rel="stylesheet" href="<?= e(asset_url('test-center.css')) ?>">
<nav class="test-nav" aria-label="<?= $isDetail?'测试实例功能':'测试中心功能' ?>">
<?php foreach($tabs as $key=>$label): ?><a href="<?= e(admin_url($isDetail?'test-center.detail':'test-center',array_merge($isDetail?['id'=>$instance['public_id']]:[],['section'=>$key]))) ?>" class="test-nav__item <?= $section===$key?'is-active':'' ?>" <?= $section===$key?'aria-current="page"':'' ?>><?= e($label) ?></a><?php endforeach; ?>
</nav>
<?php if($isDetail&&\WorkerAdmin\Infrastructure\Testing\TestCenterNavigation::panels($section)): ?>
<nav class="test-nav" aria-label="当前功能分类"><?php foreach(\WorkerAdmin\Infrastructure\Testing\TestCenterNavigation::panels($section) as $key=>$label): ?><a class="test-nav__item <?= ($panel??'')===$key?'is-active':'' ?>" href="<?= e(admin_url('test-center.detail',['id'=>$instance['public_id'],'section'=>$section,'panel'=>$key])) ?>" <?= ($panel??'')===$key?'aria-current="page"':'' ?>><?= e($label) ?></a><?php endforeach; ?></nav>
<?php endif; ?>
<script src="<?= e(asset_url('test-center.js')) ?>" defer></script>
