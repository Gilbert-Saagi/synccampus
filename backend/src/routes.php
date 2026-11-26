<?php
declare(strict_types=1);

use SyncCampus\Controller\AdminController;
use SyncCampus\Controller\AuthController;
use SyncCampus\Controller\CommunityController;
use SyncCampus\Controller\DelegateController;
use SyncCampus\Controller\LeaderController;
use SyncCampus\Controller\StudentController;
use SyncCampus\Controller\WorkspaceController;
use SyncCampus\Router;

$router = new Router();

$router->get('/health', static fn () => ['status' => 'ok']);

// Students (anonymous)
$router->get('/categories', [StudentController::class, 'categories']);
$router->post('/concerns', [StudentController::class, 'submit']);
$router->post('/track', [StudentController::class, 'track']);
$router->post('/track/info', [StudentController::class, 'addInfo']);
$router->post('/track/confirm', [StudentController::class, 'confirm']);
$router->get('/public/concerns', [StudentController::class, 'publicFeed']);
$router->get('/public/stats', [StudentController::class, 'stats']);

// Guild updates page (anonymous)
$router->get('/public/workspace', [CommunityController::class, 'updates']);
$router->get('/public/surveys', [CommunityController::class, 'surveys']);
$router->post('/public/surveys/{id}/responses', [CommunityController::class, 'answerSurvey']);
$router->post('/suggestions', [CommunityController::class, 'submitSuggestion']);
$router->post('/suggestions/track', [CommunityController::class, 'trackSuggestion']);
$router->post('/suggestions/track/reply', [CommunityController::class, 'replyToSuggestion']);

// Staff accounts
$router->post('/auth/login', [AuthController::class, 'login']);
$router->get('/auth/me', [AuthController::class, 'me']);
$router->post('/auth/change-password', [AuthController::class, 'changePassword']);
$router->post('/staff/invitations', [AuthController::class, 'createInvitation']);
$router->post('/staff/invitations/inspect', [AuthController::class, 'inspectInvitation']);
$router->post('/staff/invitations/redeem', [AuthController::class, 'redeemInvitation']);

// System admin (accounts only, never concerns)
$router->get('/admin/staff', [AdminController::class, 'staff']);
$router->patch('/admin/staff/{id}', [AdminController::class, 'updateStaff']);
$router->get('/admin/invitations', [AdminController::class, 'invitations']);
$router->post('/admin/invitations/{id}/revoke', [AdminController::class, 'revokeInvitation']);

// Guild President and Vice
$router->get('/leader/queue', [LeaderController::class, 'queue']);
$router->get('/leader/concerns/{id}', [LeaderController::class, 'concern']);
$router->post('/leader/concerns/{id}/assign', [LeaderController::class, 'assign']);
$router->get('/leader/workload', [LeaderController::class, 'workload']);
$router->get('/leader/analytics', [LeaderController::class, 'analytics']);
$router->get('/leader/trends', [LeaderController::class, 'trends']);
$router->get('/leader/escalations', [LeaderController::class, 'escalations']);
$router->post('/leader/run-escalation', [LeaderController::class, 'runEscalation']);
$router->post('/leader/escalations/{id}/acknowledge', [LeaderController::class, 'acknowledge']);
$router->get('/leader/performance', [LeaderController::class, 'performance']);
$router->get('/leader/handover', [LeaderController::class, 'handovers']);
$router->post('/leader/handover', [LeaderController::class, 'createHandover']);
$router->get('/leader/surveys', [WorkspaceController::class, 'surveys']);
$router->post('/leader/surveys', [WorkspaceController::class, 'createSurvey']);
$router->patch('/leader/surveys/{id}/publish', [WorkspaceController::class, 'togglePublished']);
$router->get('/leader/suggestions', [WorkspaceController::class, 'suggestions']);
$router->post('/leader/suggestions/{id}/update', [WorkspaceController::class, 'updateSuggestion']);

// Delegates (and leaders working a concern themselves)
$router->get('/delegate/concerns', [DelegateController::class, 'myConcerns']);
$router->post('/delegate/concerns/{id}/update', [DelegateController::class, 'postUpdate']);
$router->get('/delegate/performance', [DelegateController::class, 'myPerformance']);
$router->get('/delegate/availability', [DelegateController::class, 'availability']);
$router->post('/delegate/availability', [DelegateController::class, 'setAvailability']);

// Guild workspace
$router->get('/workspace/items', [WorkspaceController::class, 'items']);
$router->post('/workspace/items', [WorkspaceController::class, 'createItem']);
$router->post('/workspace/projects/{id}/tasks', [WorkspaceController::class, 'addTask']);
$router->patch('/workspace/tasks/{id}', [WorkspaceController::class, 'updateTask']);
$router->get('/staff/messages', [WorkspaceController::class, 'messages']);
$router->post('/staff/messages', [WorkspaceController::class, 'postMessage']);

return $router;
