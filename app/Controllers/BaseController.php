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
     * @return string
     */
    protected function renderPage(string $view, array $page = []): string
    {
        try {
            $isLoggedIn = auth()->loggedIn();
            $isAdmin = $isLoggedIn && auth()->user() !== null && auth()->user()->inGroup('admin');
            $isStaff = $isLoggedIn && auth()->user() !== null && auth()->user()->inGroup('admin', 'manager');
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $exception) {
            $isLoggedIn = false;
            $isAdmin = false;
            $isStaff = false;
        }

        $defaults = [
            'siteName' => 'TanHub',
            'pageTitle' => 'TanHub',
            'metaDescription' => 'Centralised wildlife observation reporting hub.',
            'tagline' => 'Centralised wildlife observation reporting hub.',
            'bodyClass' => 'app-shell',
            'navItems' => $this->buildNavItems($isLoggedIn, $isStaff, $isAdmin),
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

        return view($view, ['page' => array_replace($defaults, $page)]);
    }

    /**
     * Build the primary site navigation for the current user context.
     *
     * @param bool $isLoggedIn Whether the current visitor is authenticated.
     * @param bool $isStaff Whether the current user is a staff member.
     * @param bool $isAdmin Whether the current user is an admin.
     * @return array<int, array<string, mixed>>
     */
    private function buildNavItems(bool $isLoggedIn, bool $isStaff, bool $isAdmin): array
    {
        $navItems = [
            $this->navLink('Home', site_url('/'), '', 'link'),
        ];

        if ($isStaff) {
            $navItems[] = $this->navDropdown('Lookups', [
                $this->navLink('Data sources', site_url('data-sources'), 'data-sources'),
                $this->navLink('Geographic regions', site_url('geographic-regions'), 'geographic-regions'),
                $this->navLink('Recording schemes', site_url('recording-schemes'), 'recording-schemes'),
            ]);
            $navItems[] = $this->navDropdown('Taxonomy', [
                $this->navLink('Taxon groups', site_url('taxon-groups'), 'taxon-groups'),
                $this->navLink('Taxon ranks', site_url('taxon-ranks'), 'taxon-ranks'),
                $this->navLink('Taxa', site_url('taxa'), 'taxa'),
            ]);
            $navItems[] = $this->navLink('Occurrences', site_url('occurrences'), 'occurrences', 'link');
            $navItems[] = $this->navLink('Imports', site_url('imports'), 'imports', 'link');
        }

        if ($isAdmin) {
            $navItems[] = $this->navLink('Users', site_url('users'), 'users', 'link');
        }

        $navItems[] = $this->navLink($isLoggedIn ? 'Logout' : 'Login', site_url($isLoggedIn ? 'logout' : 'login'), $isLoggedIn ? 'logout' : 'login', 'link');
        $navItems[] = [
            'label' => 'Docs',
            'url' => 'https://codeigniter.com/user_guide/',
            'style' => 'outline',
            'external' => true,
        ];

        return $navItems;
    }

    /**
     * Create a simple navigation link definition.
     *
     * @param string $label Display label.
     * @param string $url Destination URL.
     * @param string $path Route path used for active state.
     * @param string $style Visual style token.
     * @return array<string, mixed>
     */
    private function navLink(string $label, string $url, string $path, string $style = 'link'): array
    {
        return [
            'label' => $label,
            'url' => $url,
            'path' => $path,
            'style' => $style,
        ];
    }

    /**
     * Create a dropdown navigation definition.
     *
     * @param string $label Display label.
     * @param array<int, array<string, mixed>> $items Child navigation items.
     * @return array<string, mixed>
     */
    private function navDropdown(string $label, array $items): array
    {
        return [
            'label' => $label,
            'style' => 'dropdown',
            'items' => $items,
        ];
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
