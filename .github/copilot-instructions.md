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
- MUST: When adding, removing, or changing a database table, column, index, constraint, or
	relationship in app/Database/Migrations, update docs/database.md in the same change. Document
	the field or constraint's purpose, lifecycle, and any database-driver-specific implementation.

# Documentation for new developers

- Document new or materially changed code for a developer unfamiliar with this project. Explain its
  responsibility and important domain assumptions.
- Use PHPDoc for PHP classes, methods and functions, including private helpers. Use JSDoc for
  JavaScript modules and meaningful helper functions; explain non-trivial event handlers and
	asynchronous state transitions.
- Document parameter meaning, return values and significant failure modes. Describe structured
  array/object shapes where their fields form a contract.
- Explain WHY non-obvious code exists: business rules, query-performance decisions, compatibility
  constraints, deduplication, security boundaries, race prevention and fallback behaviour.
- Document any functions with comments in the code which explain the purpose, behavior, and any
  non-obvious implementation details, unless the code is self-explanatory.
- Document the supplied data and responsibilities of shared view partials when these cannot be
  understood from the template alone.
- Keep comments accurate when behaviour changes. Link to related project documentation rather than
  duplicating lengthy explanations.
- Avoid comments that merely restate obvious code. Prefer a concise explanation of an invariant or
  decision over narrating every line.
- Before completing a change, review all changed code, including new files, for documentation gaps
  and stale comments—not just missing docblocks.

# Theme

- Use Bootstrap 5 for all front-end components and styling.

# CI Testing

- All code should be covered by unit tests where possible. Use PHPUnit for testing.