<?php

use App\Http\Controllers\AccountingController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\OrderStatusController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StockFlowController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout']);

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::get('/push/vapid', [PushController::class, 'vapid']);
    Route::post('/push/subscribe', [PushController::class, 'subscribe']);
    Route::delete('/push/subscribe', [PushController::class, 'unsubscribe']);
    Route::get('/dashboard', DashboardController::class);
    Route::get('/search', SearchController::class);
    Route::get('/inbox', [InboxController::class, 'index']);
    Route::post('/inbox/{type}/{id}/read', [InboxController::class, 'read'])->whereNumber('id');
    Route::get('/{type}/{id}/comments', [CommentController::class, 'index'])->whereNumber('id');
    Route::post('/{type}/{id}/comments', [CommentController::class, 'store'])->whereNumber('id');
    Route::post('/{type}/{id}/comments/read', [CommentController::class, 'read'])->whereNumber('id');
    Route::get('/{type}/{id}/attachments', [AttachmentController::class, 'index'])->whereNumber('id');
    Route::post('/{type}/{id}/attachments', [AttachmentController::class, 'store'])->whereNumber('id');
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show']);
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy']);

    Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.create');
    Route::put('/users/{user}', [UserController::class, 'update'])->middleware('permission:users.update');
    Route::patch('/users/{user}/active', [UserController::class, 'toggleActive'])->middleware('permission:users.toggle_active');

    Route::get('/roles', [UserController::class, 'roles'])->middleware('permission:users.view,roles.view');
    Route::get('/permissions', [UserController::class, 'permissions'])->middleware('permission:users.view,roles.view,permissions.view');
    Route::post('/roles', [UserController::class, 'storeRole'])->middleware('permission:roles.create');
    Route::put('/roles/{role}', [UserController::class, 'updateRole'])->middleware('permission:roles.update');
    Route::delete('/roles/{role}', [UserController::class, 'destroyRole'])->middleware('permission:roles.delete');
    Route::put('/roles/{role}/permissions', [UserController::class, 'updateRolePermissions'])->middleware('permission:roles.update');
    Route::post('/permissions', [UserController::class, 'storePermission'])->middleware('permission:permissions.create');
    Route::put('/permissions/{permission}', [UserController::class, 'updatePermission'])->middleware('permission:permissions.update');
    Route::delete('/permissions/{permission}', [UserController::class, 'destroyPermission'])->middleware('permission:permissions.delete');

    Route::get('/order-statuses', [OrderStatusController::class, 'index']);
    Route::put('/order-statuses/{orderStatus}', [OrderStatusController::class, 'update'])->middleware('permission:statuses.update');

    Route::get('/departments', [MasterDataController::class, 'departments']);
    Route::post('/departments', [MasterDataController::class, 'storeDepartment'])->middleware('permission:departments.create');
    Route::put('/departments/{department}', [MasterDataController::class, 'updateDepartment'])->middleware('permission:departments.update');
    Route::delete('/departments/{department}', [MasterDataController::class, 'destroyDepartment'])->middleware('permission:departments.delete');

    Route::get('/clients', [MasterDataController::class, 'clients'])->middleware('permission:clients.view');
    Route::post('/clients', [MasterDataController::class, 'storeClient'])->middleware('permission:clients.create');
    Route::get('/clients/{client}/summary', [MasterDataController::class, 'clientSummary'])->middleware('permission:clients.view');
    Route::get('/clients/{client}', [MasterDataController::class, 'showClient'])->middleware('permission:clients.view');
    Route::put('/clients/{client}', [MasterDataController::class, 'updateClient'])->middleware('permission:clients.update');
    Route::delete('/clients/{client}', [MasterDataController::class, 'destroyClient'])->middleware('permission:clients.delete');
    Route::post('/clients/{client}/phones', [MasterDataController::class, 'storePhone'])->middleware('permission:clients.update');
    Route::put('/phones/{phone}', [MasterDataController::class, 'updatePhone'])->middleware('permission:clients.update');
    Route::delete('/phones/{phone}', [MasterDataController::class, 'destroyPhone'])->middleware('permission:clients.update');
    Route::post('/clients/{client}/locations', [MasterDataController::class, 'storeLocation'])->middleware('permission:clients.update');
    Route::put('/locations/{location}', [MasterDataController::class, 'updateLocation'])->middleware('permission:clients.update');
    Route::delete('/locations/{location}', [MasterDataController::class, 'destroyLocation'])->middleware('permission:clients.update');
    Route::post('/locations/{location}/machines', [MasterDataController::class, 'storeMachine'])->middleware('permission:clients.update');
    Route::put('/machines/{machine}', [MasterDataController::class, 'updateMachine'])->middleware('permission:clients.update');
    Route::delete('/machines/{machine}', [MasterDataController::class, 'destroyMachine'])->middleware('permission:clients.update');

    Route::get('/items', [InventoryController::class, 'items'])->middleware('permission:items.view');
    Route::post('/items', [InventoryController::class, 'storeItem'])->middleware('permission:items.create');
    Route::put('/items/{item}', [InventoryController::class, 'updateItem'])->middleware('permission:items.update');
    Route::delete('/items/{item}', [InventoryController::class, 'destroyItem'])->middleware('permission:items.delete');
    Route::get('/warehouses', [InventoryController::class, 'warehouses'])->middleware('permission:inventory.view,inventory.view_own,items.view,transfers.view,adjustments.view,inventory.receive');
    Route::get('/stock-levels', [InventoryController::class, 'stockLevels'])->middleware('permission:inventory.view,inventory.view_own');
    Route::post('/warehouses', [InventoryController::class, 'storeWarehouse'])->middleware('permission:warehouses.create');
    Route::put('/warehouses/{warehouse}', [InventoryController::class, 'updateWarehouse'])->middleware('permission:warehouses.update');
    Route::delete('/warehouses/{warehouse}', [InventoryController::class, 'destroyWarehouse'])->middleware('permission:warehouses.delete');
    Route::post('/warehouses/{warehouse}/receipts', [InventoryController::class, 'receive'])->middleware('permission:inventory.receive');
    Route::get('/warehouses/{warehouse}/movements', [InventoryController::class, 'movements'])->middleware('permission:transfers.view,inventory.view,adjustments.view');

    Route::get('/transfers', [StockFlowController::class, 'transfers'])->middleware('permission:transfers.view');
    Route::post('/transfers', [StockFlowController::class, 'storeTransfer'])->middleware('permission:transfers.create');
    Route::put('/transfers/{transfer}', [StockFlowController::class, 'updateTransfer'])->middleware('permission:transfers.update');
    Route::delete('/transfers/{transfer}', [StockFlowController::class, 'destroyTransfer'])->middleware('permission:transfers.delete');
    Route::post('/transfers/{transfer}/send', [StockFlowController::class, 'sendTransfer'])->middleware('permission:transfers.send');
    Route::post('/transfers/{transfer}/receive', [StockFlowController::class, 'receiveTransfer'])->middleware('permission:transfers.receive');
    Route::post('/transfers/{transfer}/cancel', [StockFlowController::class, 'cancelTransfer'])->middleware('permission:transfers.cancel');

    Route::get('/adjustments', [StockFlowController::class, 'adjustments'])->middleware('permission:adjustments.view');
    Route::post('/adjustments', [StockFlowController::class, 'storeAdjustment'])->middleware('permission:adjustments.create');
    Route::put('/adjustments/{adjustment}', [StockFlowController::class, 'updateAdjustment'])->middleware('permission:adjustments.update');
    Route::delete('/adjustments/{adjustment}', [StockFlowController::class, 'destroyAdjustment'])->middleware('permission:adjustments.delete');
    Route::post('/adjustments/{adjustment}/post', [StockFlowController::class, 'postAdjustment'])->middleware('permission:adjustments.post');

    Route::get('/orders', [OrderController::class, 'index'])->middleware('permission:orders.view');
    Route::post('/orders', [OrderController::class, 'store'])->middleware('permission:orders.create');
    Route::get('/orders/filter-options', [OrderController::class, 'filterOptions'])->middleware('permission:orders.view');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->middleware('permission:orders.view');
    Route::put('/orders/{order}', [OrderController::class, 'update'])->middleware('permission:orders.update');
    Route::delete('/orders/{order}', [OrderController::class, 'destroy'])->middleware('permission:orders.delete');

    Route::get('/dispatch/board', [OrderController::class, 'board'])->middleware('permission:orders.dispatch');
    Route::post('/dispatch/reorder', [OrderController::class, 'reorderQueue'])->middleware('permission:orders.dispatch');
    Route::post('/orders/{order}/assign', [OrderController::class, 'assign'])->middleware('permission:orders.dispatch');
    Route::post('/technicians/{technician}/reorder', [OrderController::class, 'reorder'])->middleware('permission:orders.dispatch');
    Route::post('/orders/{order}/hold', [OrderController::class, 'hold'])->middleware('permission:orders.hold');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('permission:orders.cancel');

    Route::get('/tech/current', [OrderController::class, 'current'])->middleware('permission:orders.accept,orders.reached,orders.complete,invoices.create');
    Route::post('/orders/{order}/accept', [OrderController::class, 'accept'])->middleware('permission:orders.accept');
    Route::post('/orders/{order}/reached', [OrderController::class, 'reached'])->middleware('permission:orders.reached');
    Route::post('/orders/{order}/complete', [OrderController::class, 'complete'])->middleware('permission:orders.complete');
    Route::post('/orders/{order}/invoice', [InvoiceController::class, 'store'])->middleware('permission:invoices.create');

    Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('permission:invoices.view');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:invoices.view');
    Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->middleware('permission:invoices.update');
    Route::post('/invoices/{invoice}/confirm', [InvoiceController::class, 'confirm'])->middleware('permission:invoices.confirm');
    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->middleware('permission:invoices.delete');
    Route::post('/payments', [InvoiceController::class, 'pay'])->middleware('permission:payments.collect');
    Route::post('/payments/apply', [InvoiceController::class, 'applyCredit'])->middleware('permission:payments.collect');

    Route::get('/contracts', [ContractController::class, 'index'])->middleware('permission:contracts.view');
    Route::post('/contracts', [ContractController::class, 'store'])->middleware('permission:contracts.create');
    Route::get('/contracts/{contract}', [ContractController::class, 'show'])->middleware('permission:contracts.view');
    Route::put('/contracts/{contract}', [ContractController::class, 'update'])->middleware('permission:contracts.update');

    Route::middleware('permission:accounting.view')->group(function () {
        Route::get('/accounts', [AccountingController::class, 'accounts']);
        Route::get('/journals', [AccountingController::class, 'journals']);
        Route::get('/trial-balance', [AccountingController::class, 'trialBalance']);
        Route::get('/accounts/{account}/ledger', [AccountingController::class, 'ledger']);
        Route::get('/contracts-profit', [AccountingController::class, 'contractProfit']);
        Route::get('/reports/income-statement', [AccountingController::class, 'incomeStatement']);
        Route::get('/reports/balance-sheet', [AccountingController::class, 'balanceSheet']);
        Route::get('/reports/ar-aging', [AccountingController::class, 'arAging']);
        Route::get('/reports/collections', [AccountingController::class, 'collections']);
        Route::get('/reports/client-statement', [AccountingController::class, 'clientStatement']);
        Route::get('/reports/installments', [AccountingController::class, 'installmentReport']);
    });
    Route::post('/journals', [AccountingController::class, 'store'])->middleware('permission:accounting.create');
    Route::put('/journals/{journal}', [AccountingController::class, 'update'])->middleware('permission:accounting.update');
    Route::delete('/journals/{journal}', [AccountingController::class, 'destroy'])->middleware('permission:accounting.delete');
    Route::post('/accounts', [AccountingController::class, 'storeAccount'])->middleware('permission:accounting.create');
    Route::put('/accounts/{account}', [AccountingController::class, 'updateAccount'])->middleware('permission:accounting.update');
    Route::delete('/accounts/{account}', [AccountingController::class, 'destroyAccount'])->middleware('permission:accounting.delete');
    Route::get('/inventory/valuation', [AccountingController::class, 'valuation'])->middleware('permission:inventory.valuation');
});
