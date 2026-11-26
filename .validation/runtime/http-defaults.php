<?php
// Synthetic configuration only: exercise early bootstrap errors without any installed site.
if (($_GET['fixture'] ?? '') === 'parse') {
    require __DIR__.'/broken.php';
}
if (($_GET['fixture'] ?? '') === 'error') {
    throw new RuntimeException('fixture-secret-must-not-be-returned');
}
return ['app'=>['env'=>'production','debug'=>true,'timezone'=>'UTC'],'installed'=>false];
