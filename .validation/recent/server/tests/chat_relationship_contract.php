<?php
declare(strict_types=1);

use WorkerAdmin\Domain\Chat\ConversationScope;

require_once dirname(__DIR__).'/app/Domain/Chat/ConversationScope.php';

$expect=static function(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);};
$expect(ConversationScope::assignment('7','9')==='order:7:assignment:9','assignment binding');
$expect(ConversationScope::collaboration('7')==='order:7:collaboration','collaboration binding');
foreach([['9','10'],['9007199254740993','18446744073709551615']] as [$low,$high]){
    $expect(ConversationScope::direct($high,$low)===ConversationScope::direct($low,$high),'pair symmetry');
    $expect(ConversationScope::direct($high,$low)==='direct:'.$low.':'.$high,'numeric pair order');
}
foreach([['7','7'],['0','2'],['01','2'],['1','18446744073709551616'],['+1','2']] as [$first,$second]){
    try{ConversationScope::direct($first,$second);}catch(InvalidArgumentException $error){continue;}
    throw new RuntimeException('Invalid pair accepted');
}
$expect(ConversationScope::support('9')==='support:platform_feedback:9','feedback ownership');
$expect(ConversationScope::support('9',true)==='support:platform_feedback_test:9','test feedback ownership');
echo "Conversation relationship keys: OK\n";
