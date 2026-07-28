# Frontend styling (SCSS)

This project keeps a single browser-facing stylesheet at `public/css/site.css`.
That file is generated from SCSS sources and should not be edited directly.

## Goals

- keep styles easy to find and update
- isolate Bootstrap overrides from app-specific components
- preserve a single compiled CSS output for deployment simplicity
- use CSS custom properties for stable design tokens

## Source and output

- Entry point: `assets/scss/site.scss`
- Output: `public/css/site.css`

The layout template loads Bootstrap from CDN and then loads `public/css/site.css`.

## Directory structure

```text
assets/scss/
  site.scss
  abstracts/
    _index.scss
    _tokens.scss
    _mixins.scss
  vendors/
    _bootstrap-overrides.scss
  base/
    _index.scss
    _base.scss
  layout/
    _index.scss
    _shell.scss
  partials/
    _index.scss
    _surface-panels.scss
    _content-text.scss
  components/
    _index.scss
    _buttons.scss
    _cards.scss
    _taxon-media.scss
  features/
    _index.scss
    _sections.scss
    _auth.scss
  utilities/
    _index.scss
    _responsive.scss
```

## Build commands

Install build dependencies:

```bash
npm install
```

Build CSS once:

```bash
npm run css:build
```

Watch SCSS and rebuild on change:

```bash
npm run css:watch
```

Build a compressed production stylesheet:

```bash
npm run css:build:prod
```

## Styling conventions

1. Keep CSS custom properties in `assets/scss/abstracts/_tokens.scss`.
2. Put Bootstrap class overrides only in
   `assets/scss/vendors/_bootstrap-overrides.scss`.
3. Place reusable UI patterns in `assets/scss/components`.
4. Place page or workflow-specific styling in `assets/scss/features`.
5. Keep cross-cutting shared snippets in `assets/scss/partials`.
6. Add responsive rules in the owning partial where practical; use
   `assets/scss/utilities/_responsive.scss` for shared breakpoints.

## Typical change workflow

1. Edit or add SCSS partials in `assets/scss`.
2. Run `npm run css:build`.
3. Review generated changes in `public/css/site.css`.
4. Commit both SCSS source updates and the generated CSS output.
