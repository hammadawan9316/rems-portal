<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');
$routes->setAutoRoute(true); // optional but helpful for testing

// Catch API preflight requests so browsers don't get a route-level 404 on OPTIONS.
$routes->options('api/(.*)', static function () {
    return service('response')->setStatusCode(204);
});

$routes->group('api/', ['namespace' => 'App\Controllers\Api'], function ($routes) {
    // Public auth routes
    $routes->post('auth/register', 'AuthenticationController::register');
    $routes->post('auth/login', 'AuthenticationController::login');
    $routes->post('auth/forgot-password', 'AuthenticationController::forgotPassword');
    $routes->post('auth/reset-password', 'AuthenticationController::resetPassword');

    // Protected auth routes
    $routes->group('auth', ['filter' => 'jwtAuth'], function ($routes) {
        $routes->post('refresh', 'AuthenticationController::refresh');
        $routes->post('change-password', 'AuthenticationController::changePassword');
        $routes->get('me', 'AuthenticationController::getCurrentUser');
        $routes->post('logout', 'AuthenticationController::logout');
    });

    // Public project intake routes
    $routes->get('projects/files/(:segment)', 'ProjectIntakeController::downloadFile/$1');

    // Public category and service routes
    $routes->get('categories', 'CategoryController::index');
    $routes->get('categories/(:num)', 'CategoryController::show/$1');
    $routes->get('services', 'ServiceController::index');
    $routes->get('services/(:num)', 'ServiceController::show/$1');
    $routes->get('services/category/(:num)', 'ServiceController::byCategory/$1');

    // Admin-only category routes
    $routes->group('', ['filter' => 'jwtAuth'], function ($routes) {
        $routes->group('', ['filter' => 'roleAccess:admin'], function ($routes) {
            $routes->post('categories', 'CategoryController::store');
            $routes->post('categories/(:num)', 'CategoryController::update/$1');
            $routes->patch('categories/(:num)', 'CategoryController::update/$1');
            $routes->delete('categories/(:num)', 'CategoryController::delete/$1');

            $routes->post('services', 'ServiceController::store');
            $routes->post('services/(:num)', 'ServiceController::update/$1');
            $routes->patch('services/(:num)', 'ServiceController::update/$1');
            $routes->delete('services/(:num)', 'ServiceController::delete/$1');
            $routes->post('customers', 'CustomerController::create');
            $routes->get('customers', 'CustomerController::index');
            $routes->get('customers/(:num)', 'CustomerController::show/$1');
            $routes->post('customers/(:num)', 'CustomerController::update/$1');
            $routes->patch('customers/(:num)', 'CustomerController::update/$1');
            $routes->delete('customers/(:num)', 'CustomerController::delete/$1');
            $routes->get('contracts', 'ContractController::index');
            $routes->post('contracts', 'ContractController::store');
            $routes->get('contracts/(:num)', 'ContractController::show/$1');
            $routes->post('contracts/(:num)', 'ContractController::update/$1');
            $routes->patch('contracts/(:num)', 'ContractController::update/$1');
            $routes->delete('contracts/(:num)', 'ContractController::delete/$1');
            $routes->get('clauses', 'ContractController::clauses');
            $routes->post('clauses', 'ContractController::storeClause');
            $routes->post('clauses/(:num)', 'ContractController::updateClause/$1');
            $routes->patch('clauses/(:num)', 'ContractController::updateClause/$1');
            $routes->delete('clauses/(:num)', 'ContractController::deleteClause/$1');
            $routes->get('quotations_contract/(:num)', 'QuotationContractController::showByQuotation/$1');
            $routes->post('quotations_contract/(:num)', 'QuotationContractController::assignToQuotation/$1');
            $routes->post('quotations_contract_clauses/(:num)', 'QuotationContractController::updateClauses/$1');
            $routes->patch('quotations_contract_clauses/(:num)', 'QuotationContractController::updateClauses/$1');
            $routes->patch('quotations_contract_signatures/(:num)', 'QuotationContractController::updateSignatures/$1');
            $routes->post('quotations_contract_signatures/(:num)', 'QuotationContractController::updateSignatures/$1');
            $routes->get('quotations', 'QuotationController::index');
            $routes->get('quotations/requested', 'QuotationController::requested');
            $routes->post('quotations', 'QuotationController::store');
            $routes->post('quotations/submit', 'QuotationController::submit');
            $routes->post('quotations/(:num)', 'QuotationController::update/$1');
            $routes->patch('quotations/(:num)', 'QuotationController::update/$1');
            $routes->get('quotations/(:num)', 'QuotationController::show/$1');
            $routes->get('customers/(:num)/quotations', 'QuotationController::byCustomer/$1');
            $routes->get('customers/(:num)/quotations/requested', 'QuotationController::requestedByCustomer/$1');
        });
    });

    // Project intake routes
    $routes->group('projects', ['filter' => 'jwtAuth'], function ($routes) {
        $routes->post('submit', 'ProjectIntakeController::submit');
    });
});