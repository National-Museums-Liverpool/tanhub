<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');
$routes->match(['get', 'post'], 'update', 'Update::index');
$routes->match(['get', 'post'], 'setup-admin-user', 'SetupAdminUser::index');
$routes->get('taxon-groups', 'TaxonGroups::index', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('taxon-groups/(:num)/edit', 'TaxonGroups::edit/$1', ['filter' => ['session', 'group:admin,manager']]);
$routes->post('taxon-groups/(:num)/edit', 'TaxonGroups::update/$1', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('orders', 'Orders::index', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('orders/(:num)', 'Orders::show/$1', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('superfamilies', 'Superfamilies::index', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('superfamilies/(:num)', 'Superfamilies::show/$1', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('families', 'Families::index', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('families/(:num)', 'Families::show/$1', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('recording-schemes', 'RecordingSchemes::index', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('recording-schemes/(:num)', 'RecordingSchemes::show/$1', ['filter' => ['session', 'group:admin,manager']]);


service('auth')->routes($routes);
