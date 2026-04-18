<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');
$routes->setAutoRoute(true); // optional but helpful for testing
$routes->group('api/', ['namespace' => 'App\Controllers\Api'], function ($routes) {
    // Public auth routes
    $routes->post('auth/register', 'AuthenticationController::register');
    $routes->post('auth/login', 'AuthenticationController::login');
    $routes->post('auth/forgot-password', 'AuthenticationController::forgotPassword');
    $routes->post('auth/reset-password', 'AuthenticationController::resetPassword');
    $routes->get('projects/files/(:segment)', 'ProjectIntakeController::downloadFile/$1');

    // Protected auth routes
    $routes->group('auth', ['filter' => 'jwtAuth'], function ($routes) {
        $routes->post('refresh', 'AuthenticationController::refresh');
        $routes->post('change-password', 'AuthenticationController::changePassword');
        $routes->get('me', 'AuthenticationController::getCurrentUser');
        $routes->post('logout', 'AuthenticationController::logout');
    });

    // Project intake routes
    $routes->group('projects', ['filter' => 'jwtAuth'], function ($routes) {
        $routes->post('submit', 'ProjectIntakeController::submit');
    });
});