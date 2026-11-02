<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use WorkerAdmin\Contracts\SubscriptionMetadata;

final class SubscriptionMetadataAdapter implements SubscriptionMetadata
{

    public function definitions():array { return SubscriptionCatalog::subscriptionSceneDefinitions(); }
    public function semanticLabels():array { return SubscriptionCatalog::subscriptionSemanticLabels(); }
    public function fixedReviewFields():array { return SubscriptionCatalog::reviewResultFixedTemplateFields(); }
    public function infer(array $scene,array $actual,array $previous=[]):array { return SubscriptionCatalog::inferSubscriptionTemplateBinding($scene,$actual,$previous); }
    public function recommend(array $scene,array $keywords):array { return SubscriptionCatalog::recommendSubscriptionKeywords($scene,$keywords); }
}
