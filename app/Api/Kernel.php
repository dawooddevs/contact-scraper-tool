<?php

declare(strict_types=1);

namespace App\Api;

use App\Api\Controllers\AuthController;
use App\Api\Controllers\CampaignController;
use App\Api\Controllers\DashboardController;
use App\Api\Controllers\LeadController;
use App\Api\Controllers\SettingsController;
use App\Api\Controllers\SourceController;
use App\Api\Controllers\SystemController;
use App\Api\Controllers\WorkerController;
use App\Core\App;
use App\Core\Auth;
use App\Core\Migrator;
use PDOException;
use Throwable;

/**
 * JSON API front controller: /api.php?r=<route>
 */
final class Kernel
{
    /**
     * route => [controller, method, http method, requires auth, keeps session open]
     */
    private const ROUTES = [
        'auth.me' => [AuthController::class, 'me', 'GET', false, true],
        'auth.login' => [AuthController::class, 'login', 'POST', false, true],
        'auth.logout' => [AuthController::class, 'logout', 'POST', true, true],
        'auth.profile' => [AuthController::class, 'profile', 'POST', true, false],

        'dashboard.summary' => [DashboardController::class, 'summary', 'GET', true, false],

        'campaigns.list' => [CampaignController::class, 'list', 'GET', true, false],
        'campaigns.get' => [CampaignController::class, 'get', 'GET', true, false],
        'campaigns.live' => [CampaignController::class, 'live', 'GET', true, false],
        'campaigns.logs' => [CampaignController::class, 'logs', 'GET', true, false],
        'campaigns.create' => [CampaignController::class, 'create', 'POST', true, false],
        'campaigns.update' => [CampaignController::class, 'update', 'POST', true, false],
        'campaigns.action' => [CampaignController::class, 'action', 'POST', true, false],
        'campaigns.delete' => [CampaignController::class, 'delete', 'POST', true, false],

        'leads.list' => [LeadController::class, 'list', 'GET', true, false],
        'leads.get' => [LeadController::class, 'get', 'GET', true, false],
        'leads.update' => [LeadController::class, 'update', 'POST', true, false],
        'leads.bulk' => [LeadController::class, 'bulk', 'POST', true, false],
        'leads.export' => [LeadController::class, 'export', 'POST', true, false],

        'sources.list' => [SourceController::class, 'list', 'GET', true, false],
        'sources.update' => [SourceController::class, 'update', 'POST', true, false],
        'sources.test' => [SourceController::class, 'test', 'POST', true, false],

        'settings.get' => [SettingsController::class, 'get', 'GET', true, false],
        'settings.update' => [SettingsController::class, 'update', 'POST', true, false],
        'settings.test_connection' => [SettingsController::class, 'testConnection', 'POST', true, false],
        'settings.maintenance' => [SettingsController::class, 'maintenance', 'POST', true, false],

        'worker.tick' => [WorkerController::class, 'tick', 'POST', true, false],
        'worker.status' => [WorkerController::class, 'status', 'GET', true, false],

        'system.info' => [SystemController::class, 'info', 'GET', true, false],
    ];

    public static function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');

        $authenticated = false;
        try {
            if (!App::isInstalled()) {
                throw new ApiException('Scrap is not installed yet.', 503, [], 'not_installed');
            }

            $route = (string) ($_GET['r'] ?? '');
            $def = self::ROUTES[$route] ?? null;
            if ($def === null) {
                throw new ApiException('Unknown API route.', 404, [], 'not_found');
            }
            [$controller, $method, $httpMethod, $requiresAuth, $keepSession] = $def;

            $request = Request::capture();
            if ($request->method !== $httpMethod) {
                throw new ApiException('Method not allowed.', 405, [], 'method_not_allowed');
            }

            if (Migrator::needsMigration()) {
                Migrator::migrate();
            }

            Auth::startSession();
            $user = Auth::user();
            $authenticated = $user !== null;

            if ($httpMethod === 'POST' && !Auth::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
                throw new ApiException('Your session token expired. Please retry.', 419, [], 'csrf');
            }
            if ($requiresAuth && !$authenticated) {
                throw new ApiException('Please sign in to continue.', 401, [], 'unauthenticated');
            }
            if (!$keepSession) {
                Auth::closeSession();
            }

            $result = (new $controller())->{$method}($request, $user);

            if ($result instanceof FileResponse) {
                header_remove('Content-Type');
                $result->send();
                return;
            }

            self::json(['ok' => true, 'data' => $result]);
        } catch (ApiException $e) {
            self::json([
                'ok' => false,
                'error' => ['message' => $e->getMessage(), 'code' => $e->errorCode, 'fields' => (object) $e->fields],
            ], $e->status);
        } catch (PDOException $e) {
            App::logException($e);
            $message = 'Database error. Check storage/logs/app.log for details.';
            if ($authenticated) {
                $message = 'Database error: ' . $e->getMessage();
            }
            self::json(['ok' => false, 'error' => ['message' => $message, 'code' => 'db_error', 'fields' => (object) []]], 500);
        } catch (Throwable $e) {
            App::logException($e);
            $message = $authenticated ? 'Unexpected error: ' . $e->getMessage() : 'Unexpected server error.';
            self::json(['ok' => false, 'error' => ['message' => $message, 'code' => 'server_error', 'fields' => (object) []]], 500);
        }
    }

    public static function json(array $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
