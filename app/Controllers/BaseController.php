<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * BaseController provides a convenient place for loading components
 * and performing functions that are needed by all your controllers.
 *
 * Extend this class in any new controllers:
 * ```
 *     class Home extends BaseController
 * ```
 *
 * For security, be sure to declare any new methods as protected or private.
 */
abstract class BaseController extends Controller
{
  /**
   * Global helpers available to all controllers.
   *
   * @var array<int, string>
   */
    protected $helpers = ['form', 'url'];

    /**
     * Be sure to declare properties for any property fetch you initialized.
     * The creation of dynamic property is deprecated in PHP 8.2.
     */

    // protected $session;

    /**
     * Render a page with shared defaults and role-aware navigation.
     *
     * @param array<string, mixed> $page
     */
    protected function renderPage(string $view, array $page = []): string
    {
        try {
          $isLoggedIn = auth()->loggedIn();
          $isTaxonGroupManager = $isLoggedIn && auth()->user() !== null && auth()->user()->inGroup('admin', 'manager');
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $exception) {
          $isLoggedIn = false;
          $isTaxonGroupManager = false;
        }

        $defaults = [
            'siteName' => 'TanHub',
            'pageTitle' => 'TanHub',
            'metaDescription' => 'Centralised wildlife observation reporting hub.',
            'tagline' => 'Centralised wildlife observation reporting hub.',
            'bodyClass' => 'app-shell',
            'navItems' => [
                [
                    'label' => 'Home',
                    'url' => site_url('/'),
                    'path' => '',
                    'style' => 'link',
                ],
                [
                  'label' => 'Taxon groups',
                  'url' => site_url('taxon-groups'),
                  'path' => 'taxon-groups',
                  'style' => 'link',
                ],
                [
                  'label' => 'Orders',
                  'url' => site_url('orders'),
                  'path' => 'orders',
                  'style' => 'link',
                ],
                [
                  'label' => 'Superfamilies',
                  'url' => site_url('superfamilies'),
                  'path' => 'superfamilies',
                  'style' => 'link',
                ],
                [
                  'label' => 'Families',
                  'url' => site_url('families'),
                  'path' => 'families',
                  'style' => 'link',
                ],
                [
                  'label' => 'Recording schemes',
                  'url' => site_url('recording-schemes'),
                  'path' => 'recording-schemes',
                  'style' => 'link',
                ],
                [
                    'label' => 'Login',
                    'url' => site_url('login'),
                    'path' => 'login',
                    'style' => 'link',
                ],
                [
                    'label' => 'Logout',
                    'url' => site_url('logout'),
                    'path' => 'logout',
                    'style' => 'link',
                ],
                [
                    'label' => 'Register',
                    'url' => site_url('register'),
                    'path' => 'register',
                    'style' => 'button',
                ],
                [
                    'label' => 'Docs',
                    'url' => 'https://codeigniter.com/user_guide/',
                    'style' => 'outline',
                    'external' => true,
                ],
            ],
            'features' => [
                ['value' => 'NBN Atlas', 'label' => 'Data available from the NBN Atlas'],
                ['value' => 'iRecord', 'label' => 'Data available from iRecord'],
                ['value' => 'Reporting API', 'label' => 'Combined data available via the Reporting API'],
            ],
            'footer' => [
                'headline' => 'TanHub',
                'copy' => 'Built with CodeIgniter ' . \CodeIgniter\CodeIgniter::CI_VERSION . ' and Bootstrap 5.',
            ],
            'year' => date('Y'),
        ];
        if ($isLoggedIn) {
          // Hide login and register for logged-in users, since they are not relevant.
          $defaults['navItems'] = array_filter($defaults['navItems'], function ($item) {
            return ! isset($item['path']) || ! in_array($item['path'], ['login', 'register']);
          });

          if (! $isTaxonGroupManager) {
            $defaults['navItems'] = array_filter($defaults['navItems'], function ($item) {
              return ! isset($item['path']) || ! in_array($item['path'], ['taxon-groups', 'orders', 'superfamilies', 'families', 'recording-schemes']);
            });
          }
        }
        else {
          // Hide logout for guests, since it is not relevant.
          $defaults['navItems'] = array_filter($defaults['navItems'], function ($item) {
            return ! isset($item['path']) || ! in_array($item['path'], ['logout', 'taxon-groups', 'orders', 'superfamilies', 'families', 'recording-schemes']);
          });
        }

        return view($view, ['page' => array_replace($defaults, $page)]);
    }

    /**
     * @return void
     */
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        // Load here all helpers you want to be available in your controllers that extend BaseController.
        // Caution: Do not put the this below the parent::initController() call below.
        // $this->helpers = ['form', 'url'];

        // Caution: Do not edit this line.
        parent::initController($request, $response, $logger);

        // Preload any models, libraries, etc, here.
        // $this->session = service('session');
    }
}
