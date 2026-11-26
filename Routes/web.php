<?php

declare(strict_types=1);

use App\Controllers\ActivityController;
use App\Controllers\ApplicationController;
use App\Controllers\AuthController;
use App\Controllers\AutomationController;
use App\Controllers\DashboardController;
use App\Controllers\DocumentController;
use App\Controllers\InboxController;
use App\Controllers\JobController;
use App\Controllers\ProfileController;
use App\Controllers\SettingsController;
use App\Controllers\OnboardingController;
use App\Controllers\BillingController;
use App\Controllers\AdminController;
use App\Controllers\CatalogueController;
use App\Controllers\CollectorController;

$router->add('GET', '/admin', [AdminController::class, 'index']);
$router->add('GET', '/admin/jobs/add', [AdminController::class, 'editor']);
$router->add('GET', '/admin/jobs/{id}/edit', [AdminController::class, 'editor']);
$router->add('POST', '/api/admin/jobs', [AdminController::class, 'saveJob']);
$router->add('POST', '/api/admin/jobs/{id}', [AdminController::class, 'saveJob']);
$router->add('POST', '/api/admin/jobs/{id}/publish', [AdminController::class, 'publish']);
$router->add('POST', '/api/admin/sources', [AdminController::class, 'saveSource']);
$router->add('POST', '/api/admin/sources/{id}', [AdminController::class, 'saveSource']);
$router->add('POST', '/api/admin/collect', [AdminController::class, 'collect']);
$router->add('GET', '/catalogue', [CatalogueController::class, 'index']);
$router->add('GET', '/catalogue/{id}', [CatalogueController::class, 'show']);
$router->add('POST', '/api/catalogue/{id}/save', [CatalogueController::class, 'save']);
$router->add('POST', '/api/collector/claim', [CollectorController::class, 'claim'], false);
$router->add('POST', '/api/collector/batch', [CollectorController::class, 'batch'], false);
$router->add('POST', '/api/collector/finish', [CollectorController::class, 'finish'], false);
$router->add('GET', '/api/collector/status', [CollectorController::class, 'status'], false);

$router->add('GET', '/register', [AuthController::class, 'showRegister'], false);
$router->add('POST', '/register', [AuthController::class, 'register'], false);
$router->add('POST', '/auth/google', [AuthController::class, 'google'], false);
$router->add('GET', '/Callback', [AuthController::class, 'googleCallback'], false);
$router->add('GET', '/auth/google/callback', [AuthController::class, 'googleCallback'], false);
$router->add('GET', '/onboarding', [OnboardingController::class, 'index']);
$router->add('POST', '/api/onboarding', [OnboardingController::class, 'save']);
$router->add('POST', '/api/onboarding/skip', [OnboardingController::class, 'skip']);
$router->add('GET', '/plans', [BillingController::class, 'index']);
$router->add('POST', '/api/billing/promo', [BillingController::class, 'redeem']);
$router->add('POST', '/api/billing/mpesa', [BillingController::class, 'start']);
$router->add('POST', '/api/billing/payments/{id}/check', [BillingController::class, 'check']);
$router->add('POST', '/api/billing/mpesa/callback', [BillingController::class, 'callback'], false);
$router->add('GET', '/cv-review', [OnboardingController::class, 'review']);
$router->add('POST', '/api/cv/grade', [OnboardingController::class, 'grade']);
$router->add('POST', '/api/cv/improve', [OnboardingController::class, 'advice']);
$router->add('GET', '/login', [AuthController::class, 'show'], false);
$router->add('POST', '/login', [AuthController::class, 'login'], false);
$router->add('POST', '/auth/continue', [AuthController::class, 'advance'], false);
$router->add('GET', '/api/auth/csrf', [AuthController::class, 'csrf'], false);
$router->add('POST', '/logout', [AuthController::class, 'logout']);
$router->add('GET', '/', [DashboardController::class, 'index']);
$router->add('GET', '/discover', [JobController::class, 'index']);
$router->add('GET', '/jobs', [JobController::class, 'index']);
$router->add('GET', '/jobs/add', [JobController::class, 'add']);
$router->add('GET', '/jobs/{id}', [JobController::class, 'show']);
$router->add('GET', '/jobs/{id}/edit', [JobController::class, 'edit']);
$router->add('POST', '/api/jobs/{id}', [JobController::class, 'update']);
$router->add('GET', '/profile', [ProfileController::class, 'index']);
$router->add('GET', '/applications', [ApplicationController::class, 'index']);
$router->add('GET', '/applications/{id}', [ApplicationController::class, 'show']);
$router->add('GET', '/documents', [DocumentController::class, 'index']);
$router->add('GET', '/documents/{id}', [DocumentController::class, 'show']);
$router->add('GET', '/documents/{id}/download', [DocumentController::class, 'download']);
$router->add('GET', '/inbox', [InboxController::class, 'index']);
$router->add('GET', '/activity', [ActivityController::class, 'index']);
$router->add('GET', '/settings', [SettingsController::class, 'index']);
$router->add('GET', '/oauth/google/callback', [InboxController::class, 'callback']);

$router->add('POST', '/api/jobs', [JobController::class, 'create']);
$router->add('POST', '/api/jobs/extract', [JobController::class, 'extract']);
$router->add('POST', '/api/jobs/{id}/analyse', [JobController::class, 'analyse']);
$router->add('POST', '/api/jobs/{id}/apply', [JobController::class, 'apply']);
$router->add('POST', '/api/jobs/{id}/status', [JobController::class, 'status']);
$router->add('POST', '/api/jobs/{id}/verify', [JobController::class, 'verify']);
$router->add('POST', '/api/jobs/{id}/assist', [JobController::class, 'assist']);
$router->add('POST', '/api/profile', [ProfileController::class, 'save']);
$router->add('POST', '/api/profile/export', [ProfileController::class, 'export']);
$router->add('POST', '/api/documents', [DocumentController::class, 'upload']);
$router->add('POST', '/api/documents/uploads', [DocumentController::class, 'beginUpload']);
$router->add('POST', '/api/documents/uploads/complete', [
    DocumentController::class,
    'completeUpload',
]);
$router->add('POST', '/api/documents/{id}/export', [DocumentController::class, 'export']);
$router->add('POST', '/api/documents/{id}/preview', [DocumentController::class, 'preview']);
$router->add('POST', '/api/documents/{id}/rename', [DocumentController::class, 'rename']);
$router->add('POST', '/api/documents/{id}/favorite', [DocumentController::class, 'favorite']);
$router->add('POST', '/api/documents/{id}/delete', [DocumentController::class, 'delete']);
$router->add('POST', '/api/documents/{id}/master', [DocumentController::class, 'master']);
$router->add('POST', '/api/documents/{id}/profile', [DocumentController::class, 'extractProfile']);
$router->add('POST', '/api/applications/{id}', [ApplicationController::class, 'save']);
$router->add('POST', '/api/applications/{id}/interview', [
    ApplicationController::class,
    'interview',
]);
$router->add('POST', '/api/applications/{id}/prepare', [ApplicationController::class, 'prepare']);
$router->add('POST', '/api/applications/{id}/status', [ApplicationController::class, 'status']);
$router->add('POST', '/api/applications/{id}/send', [ApplicationController::class, 'send']);
$router->add('POST', '/api/applications/{id}/letter', [ApplicationController::class, 'letter']);
$router->add('POST', '/api/settings', [SettingsController::class, 'save']);
$router->add('POST', '/api/preferences', [SettingsController::class, 'preferences']);
$router->add('POST', '/api/ai/connect', [SettingsController::class, 'connectAi']);
$router->add('POST', '/api/providers', [SettingsController::class, 'provider']);
$router->add('POST', '/api/providers/{id}/test', [SettingsController::class, 'testProvider']);
$router->add('POST', '/api/sources/presets', [SettingsController::class, 'sourcePresets']);
$router->add('POST', '/api/sources', [SettingsController::class, 'source']);
$router->add('POST', '/api/sources/{id}', [SettingsController::class, 'sourceAction']);
$router->add('POST', '/api/security/password', [SettingsController::class, 'password']);
$router->add('POST', '/api/security/sessions/revoke', [
    SettingsController::class,
    'revokeSessions',
]);
$router->add('POST', '/gmail/connect', [InboxController::class, 'connect']);
$router->add('POST', '/api/gmail/disconnect', [InboxController::class, 'disconnect']);
$router->add('POST', '/api/gmail/test', [InboxController::class, 'test']);
$router->add('POST', '/api/gmail/sync', [InboxController::class, 'sync']);
$router->add('POST', '/api/automation/search', [AutomationController::class, 'search']);
$router->add('GET', '/api/tasks/{id}', [AutomationController::class, 'task']);
$router->add('GET', '/api/notifications', [ActivityController::class, 'notifications']);
$router->add('POST', '/api/notifications/{id}/read', [ActivityController::class, 'read']);
