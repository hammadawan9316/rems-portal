<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');
$routes->setAutoRoute(true); // optional but helpful for testing
$routes->group('api/', ['namespace' => 'App\Controllers\Api'], function ($routes) {
    $routes->post('projects/submit', 'ProjectIntakeController::submit');
    $routes->get('projects/files/(:segment)', 'ProjectIntakeController::downloadFile/$1');
});