<?php

use App\Services\Contracts\ContractService;
use Illuminate\Support\Facades\Schedule;

Schedule::call(fn () => app(ContractService::class)->expireContracts())
    ->daily()
    ->name('contracts-expire');

Schedule::call(fn () => app(ContractService::class)->billDueInstallments())
    ->daily()
    ->name('contracts-bill-installments');

Schedule::call(fn () => app(ContractService::class)->recognizeRevenue())
    ->monthlyOn(1, '02:00')
    ->name('contracts-recognize-revenue');
