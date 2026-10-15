<?php

declare(strict_types=1);

require_once __DIR__.'/OrderCenterRepository.php';
require_once __DIR__.'/PrivateValueCipher.php';
require_once __DIR__.'/OrderMediaUrlSigner.php';
require_once __DIR__.'/OrderOperationException.php';
require_once __DIR__.'/OrderCenterQuery.php';

use WorkerAdmin\Application\Order\OrderCenterQuery;
use WorkerAdmin\Contracts\OrderCenterRepository;
use WorkerAdmin\Contracts\OrderMediaUrlSigner;
use WorkerAdmin\Contracts\PrivateValueCipher;

function same($actual, $expected, string $label): void {
    if ($actual !== $expected) {
        fwrite(STDERR, '[FAIL] '.$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");
        exit(1);
    }
    fwrite(STDOUT, '[PASS] '.$label."\n");
}

final class Repo implements OrderCenterRepository {
    public array $calls=[];
    public function page(array $actor,string $kind,string $status,int $page,int $limit,string $keyword=''): array { $this->calls[]=compact('kind','status','page','limit','keyword'); return ['rows'=>[],'has_more'=>false]; }
    public function supplementCount(array $actor,string $kind): int { return 0; }
    public function todoCount(array $actor,string $kind): int { return 0; }
}
final class Cipher implements PrivateValueCipher {
    public function hmac(string $plainText,string $context): string { return ''; }
    public function encrypt(string $plainText,string $context): ?string { return $plainText; }
    public function decrypt(string $cipherText,string $context): string { return $cipherText; }
}
final class Signer implements OrderMediaUrlSigner {
    public function sign(string $mediaPublicId,array $actor,int $orderId,string $entityType='order',?int $entityId=null,?string $shareHash=null): string { return ''; }
}

$repo=new Repo();
$query=new OrderCenterQuery($repo,new Cipher(),new Signer());
$actor=['user_id'=>7,'worker_id'=>88];
$result=$query->query($actor,'mine','completed',1,"  浦东\n花园  ");
same($repo->calls[0]['keyword'],'浦东 花园','owner keyword normalize');
same($result['keyword'],'浦东 花园','response keyword');
$query->query($actor,'worker','all',1,'secret owner address');
same($repo->calls[1]['keyword'],'','worker keyword disabled');
$query->query($actor,'mine','all',1,str_repeat('a',120));
same(strlen($repo->calls[2]['keyword']),80,'keyword capped');

$sql=file_get_contents(__DIR__.'/repository-sql.txt') ?: '';
$owner=strpos($sql,'o.user_id=:center_user_id');
$search=strpos($sql,'o.public_no LIKE :center_keyword_no');
same($owner!==false && $search!==false && $owner<$search,true,'owner scope before keyword');
same(strpos($sql,"COALESCE(o.address_summary,'')")!==false,true,'address summary searchable');
same(strpos($sql,':center_keyword_no')!==false && strpos($sql,':center_keyword_text')!==false,true,'unique PDO placeholders');
same(strpos($sql,"if (\$kind === 'mine' && \$keyword !== '')")!==false,true,'keyword only mine');

fwrite(STDOUT,"V2.5.0 scoped order center search smoke OK\n");
