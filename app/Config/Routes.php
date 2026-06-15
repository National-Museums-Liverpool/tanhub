<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');
$routes->match(['get', 'post'], 'update', 'Update::index');
$routes->match(['get', 'post'], 'setup-admin-user', 'SetupAdminUser::index');


service('auth')->routes($routes);
