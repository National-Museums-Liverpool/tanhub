<?php

namespace App\Controllers;

/**
 * Default public-facing homepage controller.
 */
class Home extends BaseController
{
    /**
     * Render the homepage.
     */
    public function index(): string
    {
        return $this->renderPage('home', [
            'pageTitle' => 'Home',
            'heroTitle' => 'The Tanyptera Project DB Hub',
            'heroCopy' => 'Providing a centralised reporting service for wildlife observation and species data.',

        ]);
    }
}
