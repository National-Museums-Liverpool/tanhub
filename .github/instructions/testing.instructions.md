---
name: 'Tanhub testing conventions'
description: 'Testing conventions for the Tanhub project.'
applyTo: 'tests/**/*.php'
---
# Testing conventions

- MUST: Use PHPUnit for automated tests.
- MUST: Add or update tests when changing behaviour, where practical.
- MUST: Prefer focused unit tests for isolated logic and feature tests for user-facing or
  cross-component behaviour.
- MUST: Run the narrowest relevant test suite after making changes.
- MUST: Run the full test suite when changing shared behaviour, integration boundaries, or test
  infrastructure.
- MUST: Keep tests deterministic and independent of external services.
- SHOULD: Give tests descriptive names that explain the behaviour they verify.
- SHOULD: Cover successful, invalid, boundary, and failure cases when they are relevant to the
  changed behaviour.
