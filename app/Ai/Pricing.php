<?php

namespace App\Ai;

use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Puts a dollar figure on a model request using the rates in config/sunshine.php.
 */
final class Pricing
{
    /**
     * The cost in USD of one request, or null when the model has no configured price.
     */
    public static function cost(string $model, TextUsage $usage, int $webSearches = 0): ?float
    {
        $rates = config('sunshine.pricing.models')[$model] ?? null;

        if ($rates === null) {
            return null;
        }

        // The prompt length counts every input token, cached or not.
        if (isset($rates['long_context']) && $usage->inputTokens > $rates['long_context']['over']) {
            $rates = $rates['long_context'];
        }

        $cacheRead = $usage->cacheReadInputTokens ?? 0;
        $cacheWrite = $usage->cacheWriteInputTokens ?? 0;
        $uncachedInput = max(0, $usage->inputTokens - $cacheRead - $cacheWrite);

        $tokenCost = $uncachedInput * $rates['input']
            + $cacheWrite * $rates['cache_write']
            + $cacheRead * $rates['cache_read']
            + $usage->outputTokens * $rates['output'];

        return $tokenCost / 1_000_000 + $webSearches * config('sunshine.pricing.web_search');
    }
}
