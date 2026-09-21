<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Services\Mail\MailPreviewReader;
use Illuminate\Http\JsonResponse;

class MailPreviewController extends BaseController
{
    public function __invoke(MailPreviewReader $reader): JsonResponse
    {
        if (app()->isProduction()
            || ! config('mail_preview.enabled')
            || config('mail.default') !== 'log'
            || config('mail.mailers.log.channel') !== 'mail_preview') {
            return $this->error('Not found.', 404);
        }

        return $this->success(['items' => $reader->recent()], 'Recent test emails.')
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}
