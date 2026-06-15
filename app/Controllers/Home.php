<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index(): string
    {
        return $this->renderPage('home', [
            'pageTitle' => 'Home',
            'heroTitle' => 'The Tanyptera Project DB Hub',
            'heroCopy' => 'Providing a centralised reporting service for wildlife observation and species data.',

        ]);
    }
}
