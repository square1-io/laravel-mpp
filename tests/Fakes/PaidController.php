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

    #[RequiresPayment(amount: '5.00', currency: 'USD', scope: 'attr.tiered', pricing: ['tiered'])]
    public function tiered(): Response
    {
        return response('TIERED', 200);
    }

    #[RequiresPayment(amount: '1.00', currency: 'USD', scope: 'attr.guarded', preconditions: ['deny'])]
    public function guarded(): Response
    {
        return response('GUARDED', 200);
    }
}
