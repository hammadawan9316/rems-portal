<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');
$routes->setAutoRoute(true); // optional but helpful for testing
$routes->group('api', function($routes) {
    $routes->post('projects/submit', 'Api\ProjectIntakeController::submit');
});