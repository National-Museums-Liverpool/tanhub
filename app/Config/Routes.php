<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');
$routes->match(['GET', 'POST'], 'update', 'Update::index');
$routes->match(['GET', 'POST'], 'setup-admin-user', 'SetupAdminUser::index');
$routes->get('taxon-groups', 'TaxonGroups::index', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('taxon-groups/(:num)', 'TaxonGroups::details/$1', ['filter' => ['session', 'group:admin,manager']]);
$routes->post('taxon-groups/(:num)', 'TaxonGroups::update/$1', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('taxon-ranks', 'TaxonRanks::index', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('taxon-ranks/(:num)', 'TaxonRanks::details/$1', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('taxa', 'Taxa::index', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('taxa/(:num)', 'Taxa::details/$1', ['filter' => ['session', 'group:admin,manager']]);
$routes->post('taxa/(:num)', 'Taxa::update/$1', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('recording-schemes', 'RecordingSchemes::index', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('recording-schemes/(:num)', 'RecordingSchemes::details/$1', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('geographic-regions', 'GeographicRegions::index', ['filter' => ['session', 'group:admin,manager']]);
$routes->get('geographic-regions/(:num)', 'GeographicRegions::details/$1', ['filter' => ['session', 'group:admin,manager']]);

$routes->group('api/v1', ['filter' => 'api-rate-limit'], static function ($routes): void {
	$routes->post('auth/token', 'Api\\V1\\AuthTokens::token');
	$routes->post('auth/token/refresh', 'Api\\V1\\AuthTokens::refresh');
	$routes->post('auth/token/revoke', 'Api\\V1\\AuthTokens::revoke');

	$routes->get('data-sources', 'Api\\V1\\DataSources::index');
	$routes->get('data-sources/(:segment)', 'Api\\V1\\DataSources::show/$1');

	$routes->get('geographic-regions', 'Api\\V1\\GeographicRegions::index');
	$routes->get('geographic-regions/(:segment)', 'Api\\V1\\GeographicRegions::show/$1');

	$routes->get('grid-square-stats', 'Api\\V1\\GridSquareStats::index');
	$routes->get('grid-square-stats/(:segment)', 'Api\\V1\\GridSquareStats::show/$1');

	$routes->get('occurrences', 'Api\\V1\\Occurrences::index');
	$routes->get('occurrences/(:segment)', 'Api\\V1\\Occurrences::show/$1');

	$routes->get('recording-schemes', 'Api\\V1\\RecordingSchemes::index');
	$routes->get('recording-schemes/(:segment)', 'Api\\V1\\RecordingSchemes::show/$1');

	$routes->get('taxa', 'Api\\V1\\Taxa::index');
	$routes->get('taxa/(:segment)', 'Api\\V1\\Taxa::show/$1');

	$routes->get('taxon-names', 'Api\\V1\\TaxonNames::index');
	$routes->get('taxon-names/(:segment)', 'Api\\V1\\TaxonNames::show/$1');

	$routes->get('taxon-groups', 'Api\\V1\\TaxonGroups::index');
	$routes->get('taxon-groups/(:segment)', 'Api\\V1\\TaxonGroups::show/$1');

	$routes->get('taxon-ranks', 'Api\\V1\\TaxonRanks::index');
	$routes->get('taxon-ranks/(:segment)', 'Api\\V1\\TaxonRanks::show/$1');

	$routes->get('taxon-stats', 'Api\\V1\\TaxonStats::index');
	$routes->get('taxon-stats/(:segment)', 'Api\\V1\\TaxonStats::show/$1');

	$routes->get('taxon-year-stats', 'Api\\V1\\TaxonYearStats::index');
	$routes->get('taxon-year-stats/(:segment)', 'Api\\V1\\TaxonYearStats::show/$1');
});


service('auth')->routes($routes);
