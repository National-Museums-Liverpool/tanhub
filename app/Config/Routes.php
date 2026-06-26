<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');
$routes->match(['get', 'post'], 'update', 'Update::index');
$routes->match(['get', 'post'], 'setup-admin-user', 'SetupAdminUser::index');
$routes->get('taxon-groups', 'TaxonGroups::index', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('taxon-groups/(:num)/edit', 'TaxonGroups::edit/$1', ['filter' => ['session', 'group:admin,manager']]);
$routes->post('taxon-groups/(:num)/edit', 'TaxonGroups::update/$1', ['filter' => ['session', 'group:admin,manager']]);


service('auth')->routes($routes);
