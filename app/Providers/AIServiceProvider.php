<?php

namespace App\Providers;

use App\AI\AgentService;
use App\AI\Contracts\AgentDriver;
use App\AI\Drivers\ClaudeDriver;
use App\AI\Drivers\GeminiDriver;
use App\AI\Tools\AddToCartTool;
use App\AI\Tools\CheckOrderStatusTool;
use App\AI\Tools\GeneratePaymentLinkTool;
use App\AI\Tools\GetAlternativeProductsTool;
use App\AI\Tools\GetProductDetailsTool;
use App\AI\Tools\GetProductRecommendationsTool;
use App\AI\Tools\GetRecentOrdersTool;
use App\AI\Tools\RemoveFromCartTool;
use App\AI\Tools\ViewCartTool;
use Illuminate\Support\ServiceProvider;

class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AgentDriver::class, function () {
            return match (config('ai.driver')) {
                'gemini' => new GeminiDriver,
                default => new ClaudeDriver,
            };
        });

        $this->app->bind(AgentService::class, function ($app) {
            return new AgentService(
                driver: $app->make(AgentDriver::class),
                tools: [
                    new GetProductRecommendationsTool,
                    new GetProductDetailsTool,
                    new GetAlternativeProductsTool,
                    new CheckOrderStatusTool,
                    new GetRecentOrdersTool,
                    $app->make(AddToCartTool::class),
                    new ViewCartTool,
                    new RemoveFromCartTool,
                    new GeneratePaymentLinkTool,
                ],
            );
        });
    }
}
