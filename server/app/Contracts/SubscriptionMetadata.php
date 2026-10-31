<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface SubscriptionMetadata
{
    public function definitions():array;
    public function semanticLabels():array;
    public function fixedReviewFields():array;
    public function infer(array $scene,array $actual,array $previous=[]):array;
    public function recommend(array $scene,array $keywords):array;
}
