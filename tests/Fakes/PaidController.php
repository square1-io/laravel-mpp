<?php

namespace Square1\Mpp\Tests\Fakes;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Square1\Mpp\Attributes\RequiresPayment;

class PaidController
{
    #[RequiresPayment(amount: '0.50', currency: 'USD')]
    public function clip(): Response
    {
        return response('CLIP', 200);
    }

    #[RequiresPayment(amount: '5.00', currency: 'USD', grants: 10, scope: 'report.basic')]
    public function report(): JsonResponse
    {
        return response()->json(['report' => 'ok']);
    }
}
