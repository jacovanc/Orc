<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\Workflow\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return to_route('workflows.index');
})->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('/projects/{project}/settings', [ProjectController::class, 'settings'])->name('projects.settings');
    Route::post('/projects/{project}/connections/setup', [ProjectController::class, 'issueSetup'])->name('projects.connections.setup');
    Route::post('/projects/{project}/connections', [ProjectController::class, 'configure'])->name('projects.connections.store');
    Route::post('/projects/{project}/connections/verify', [ProjectController::class, 'verify'])->name('projects.connections.verify');
    Route::get('/projects/{project}/workflows/start', [WorkflowController::class, 'create'])->name('projects.workflows.create');
    Route::post('/projects/{project}/workflows', [WorkflowController::class, 'store'])->name('projects.workflows.store');
    Route::get('/workflows', [WorkflowController::class, 'index'])->name('workflows.index');
    Route::get('/workflows/start', [WorkflowController::class, 'create'])->name('workflows.create');
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
