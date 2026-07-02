<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');
$routes->match(['GET', 'POST'], 'update', 'Update::index');
$routes->match(['GET', 'POST'], 'setup-admin-user', 'SetupAdminUser::index');
$routes->get('taxon-groups', 'TaxonGroups::index', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('taxon-groups/(:num)/edit', 'TaxonGroups::edit/$1', ['filter' => ['session', 'group:admin,manager']]);
$routes->post('taxon-groups/(:num)/edit', 'TaxonGroups::update/$1', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('taxon-ranks', 'TaxonRanks::index', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('taxon-ranks/(:num)/edit', 'TaxonRanks::edit/$1', ['filter' => ['session', 'group:admin,manager']]);
$routes->post('taxon-ranks/(:num)/edit', 'TaxonRanks::update/$1', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('taxa', 'Taxa::index', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('taxa/(:num)/edit', 'Taxa::edit/$1', ['filter' => ['session', 'group:admin,manager']]);
$routes->post('taxa/(:num)/edit', 'Taxa::update/$1', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('recording-schemes', 'RecordingSchemes::index', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('recording-schemes/(:num)', 'RecordingSchemes::show/$1', ['filter' => ['session', 'group:admin,manager']]);


service('auth')->routes($routes);
