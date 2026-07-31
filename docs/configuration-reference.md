# Configuration reference

This page lists Tanhub-specific environment settings. Copy `env` to `.env` and
uncomment only the settings you need to override. See [Installation](installation.md)
for the first-run sequence and [Administration](administration.md) for media
maintenance settings.

## Application and database

- `CI_ENVIRONMENT`: set to `production` in production environments so detailed
  error output is not displayed to visitors.
- `app.baseURL`: public base URL for the application.
- `app.forceGlobalSecureRequests`: set to `true` to require HTTPS.
- `database.default.hostname`: database server hostname; defaults to `localhost`
  in the supplied environment template.
- `database.default.database`: database name.
- `database.default.username`: database username.
- `database.default.password`: database password.
- `database.default.DBDriver`: database driver, normally `MySQLi`.
- `database.default.port`: database server port, normally `3306`.
- `Config\Email.fromEmail`: sender address for application email.
- `Config\Email.fromName`: sender display name for application email.

## Indicia import connection

These settings provide the Indicia REST client connection. They are required
when importing Indicia taxonomy or occurrence data.

- `Config\Import.indiciaWarehouseUrl`: warehouse base URL, without a trailing
  slash or `index.php`.
- `Config\Import.indiciaTaxonListId`: Indicia taxon-list identifier containing
  the required taxonomy.
- `Config\Import.indiciaProjId`: REST client project identifier.
- `Config\Import.indiciaUsername`: REST client username.
- `Config\Import.indiciaSecret`: REST client secret.
- `Config\Import.indiciaOccurrencesEsEndpoint`: configured occurrence
  Elasticsearch endpoint; defaults to `es` in the template.

## Import scope

These values control the data imported by `Config\Import`. Changing taxonomic
rank, group, or region settings after an initial import requires careful data
refresh planning.

- `import.taxonRanks`: comma-separated reporting ranks. The default is `Order`,
  `Superfamily`, `Family`, `Genus`, and `Species`. It must include `Species`.
- `import.taxonGroups`: CSV list of taxon groups to import. Use CSV quoting for
  a group name that contains a comma.
- `import.geographicRegions`: CSV list of Indicia location names to import.
- `import.geographicRegionLocationType`: Indicia location type used to identify
  the configured regions; the default is `Vice County`.

These scope values are also applied to NBN Atlas occurrence imports:

- `import.geographicRegions` builds a `cl254` filter
- `import.taxonRanks` builds a `taxonRank` filter
- `import.nbnApiFilterQuery` appends custom `fq` clauses to NBN requests

`import.nbnApiFilterQuery` supports either a single clause (for example `kingdom:Animalia`) or
a full repeated-fq fragment (for example
`fq=kingdom:Animalia&fq=-phylum:Chordata&fq=-order:Lepidoptera`).

Example `.env` setting:

```dotenv
import.nbnApiFilterQuery = 'fq=kingdom:Animalia&fq=-phylum:Chordata&fq=-order:Lepidoptera'
```

NBN imports also always exclude unresolved assertion codes `50005`, `50006`, and `50001`.

NBN occurrence imports use `https://records-ws.nbnatlas.org/occurrences/search`.

See [Import](import.md) for the required import order and source behaviour.

## Taxon media

All taxon media is stored below `writable/uploads`.

- `taxonMedia.uploadSubdirectory`: relative upload directory; defaults to
  `taxon-media`.
- `taxonMedia.maxUploadBytes`: maximum accepted upload size in bytes; defaults
  to `10485760` (10 MiB).
- `taxonMedia.maxOriginalWidth`: maximum stored-original width in pixels. A
  value of `0` disables width-based downscaling.
- `taxonMedia.maxOriginalHeight`: maximum stored-original height in pixels. A
  value of `0` disables height-based downscaling.
- `taxonMedia.allowedMimeTypes`: comma-separated allowed MIME types. The
  default is `image/jpeg,image/png,image/gif,image/webp`.
- `taxonMedia.autoReorient`: whether to apply EXIF orientation transforms;
  defaults to `true`.

The supported configured variants are `thumbnail` and `large`. Each supports:

- `taxonMedia.variants.<variant>.width`: positive width in pixels.
- `taxonMedia.variants.<variant>.height`: positive height in pixels.
- `taxonMedia.variants.<variant>.mode`: image scaling mode.
- `taxonMedia.variants.<variant>.quality`: image quality from `1` to `100`.

Defaults are `320x320`, `fit`, and `85` for `thumbnail`; and `1400x1400`,
`contain`, and `90` for `large`.

## Derived rarity statistics

- `rarity.squareWeight`: weight assigned to distinct active 2km squares;
  defaults to `1.0`.
- `rarity.occurrenceWeight`: weight assigned to active occurrences; defaults
  to `1.0`.

See [derived taxon rarity categories](import.md#derived-taxon-rarity-categories)
for how these weights are applied.

## API access

- `api.rateLimitAnonymousCapacity`: anonymous requests allowed in each rate-limit
  window; defaults to `20`.
- `api.rateLimitAnonymousSeconds`: anonymous rate-limit window in seconds;
  defaults to `20`.
- `api.rateLimitAuthenticatedCapacity`: authenticated requests allowed in each
  rate-limit window; defaults to `60`.
- `api.rateLimitAuthenticatedSeconds`: authenticated rate-limit window in
  seconds; defaults to `20`.

An empty, non-numeric, or non-positive rate-limit value falls back to its
default. See [API reference](api.md#3-rate-limiting) for client-visible behaviour.

## Cross-origin requests

- `CORS_ALLOWED_ORIGINS`: comma-separated browser origins allowed to call the
  API.
- `CORS_ALLOWED_ORIGINS_PATTERNS`: comma-separated regular-expression patterns
  matching allowed origins.
- `CORS_SUPPORTS_CREDENTIALS`: whether cross-origin requests may include
  credentials; defaults to `false`.
- `CORS_ALLOWED_HEADERS`: comma-separated request headers allowed by CORS. The
  default is `Origin,Content-Type,Accept,Authorization`.

Use exact origins where possible. See [Installation](installation.md#3-api-configuration)
for examples.