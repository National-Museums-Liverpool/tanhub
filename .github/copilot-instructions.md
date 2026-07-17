---
name: 'Tanhub coding conventions'
description: 'Coding conventions for the Tanhub project designed to ensure code quality and maintainability.'
applyTo: 'app/**'
---
# Project general coding standards

- MUST: Ensure that all code is properly formatted and adheres to the CodeIgniter coding standards (https://github.com/CodeIgniter/coding-standard).
- MUST: Use CodeIgniter and PHP best practices for all code.
- MUST: Ensure that all code is properly documented with PHPDoc comments.
- MUST: Add a PHPDoc block to every new or modified class, method, and function in app/**.
- MUST: Include @param for each parameter and @return for all non-void methods.
- MUST: Include one-line summary describing purpose/behavior.
- NEVER: Leave newly introduced methods undocumented, even for private helpers.
- Endeavour to make code that is clear and easy to read.
- MUST: Use proper escaping of user input to prevent XSS and other security vulnerabilities.
- MUST: wrap documentation markdown files at 100 characters.

# Theme

- Use Bootstrap 5 for all front-end components and styling.

# CI Testing

- All code should be covered by unit tests where possible. Use PHPUnit for testing.