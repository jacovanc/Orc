<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Workflow\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return to_route('workflows.index');
})->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/workflows', [WorkflowController::class, 'index'])->name('workflows.index');
    Route::get('/workflows/start', [WorkflowController::class, 'create'])->name('workflows.create');
    Route::post('/workflows', [WorkflowController::class, 'store'])->name('workflows.store');
    Route::get('/workflows/{workflowRun}', [WorkflowController::class, 'show'])->name('workflows.show');
    Route::post('/workflows/{workflowRun}/attempts/{stageRun}/simulate', [WorkflowController::class, 'simulate'])
        ->name('workflows.attempts.simulate');
    Route::post('/workflows/{workflowRun}/attempts/{stageRun}/human-action', [WorkflowController::class, 'humanAction'])
        ->name('workflows.attempts.human-action');
    Route::post('/workflows/{workflowRun}/cancel', [WorkflowController::class, 'cancel'])
        ->name('workflows.cancel');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
