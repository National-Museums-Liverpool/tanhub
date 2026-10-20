# Configuration reference

## Admin CSV exports

Exports are disabled until all three positive byte limits are configured:

- `exports.fileBudgetBytes`: total private artifact-directory allowance.
- `exports.freeReserveBytes`: minimum free filesystem space remaining after a batch write.
- `exports.stagingBudgetBytes`: maximum staged JSON payload bytes per export. Allow extra database
  quota for rows, indexes and transactional overhead; this is not a measurement of physical DB size.

Processing uses private files under `writable/exports` on one application host.
Other settings:

- `exports.batchSize`: matching-ID capture and CSV-writing batch limit; default `500`.
- `exports.maxActivePerUser`: unfinished jobs allowed per user; default `2`.
- `exports.retentionSeconds`: completed-file lifetime; default `86400`.
- `exports.inactivitySeconds`: unfinished-job lifetime without committed progress; default `86400`.
- `exports.historySeconds`: terminal job history retention; default `604800`.
- `exports.cleanupBatchSize`: staged rows deleted per cleanup job; default `500`.
- `exports.xAccelRedirectPrefix`: optional trusted Nginx internal URL prefix ending in `/`.
  Empty uses bounded PHP streaming. See [Installation](installation.md) for protected configuration.

Start conservatively and measure processing-request duration on your host. Smaller batches bound
PHP work but cannot guarantee that every SQL filter or download meets the hosting timeout.
Capture applies filters before its ID limit and then hydrates only the selected batch. On MySQL,
occurrence ID selection without taxon filters starts with occurrences and uses a primary-key taxon
lookup to avoid a buffered/reordered join sorting the entire occurrence range. Occurrence indexes
remain available for date/source filters. Taxon-filtered queries retain normal optimizer choices.
All numeric settings must be positive. Changing classifications during capture requires creating
a new export. Template version 2 simplifies statistics headings and moves scope to metadata;
existing version 1 exports retain their frozen headings and can still finish.

Copy `env` to `.env`, then uncomment only the settings you need to change. Use this page to check
the available values and defaults. Start with [Installation](installation.md) for first-run setup;
see [Administration](administration.md) for media maintenance.

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
- `import.uiTaskStaleAfter`: legacy timeout retained for configuration compatibility.
  Queue recovery now checks exclusive import-lock ownership instead of elapsed time; this
  setting no longer controls recovery.
- `import.occurrenceRunsPerDerivedRun`: successful occurrence import runs allowed between
  automated derived statistics runs. The default is `2`. A lower number ensures stats data is
  kept updated more frequently, a higher number allows more rapid population or update of
  occurrence raw data.
- `import.incompleteOccurrenceRunsPerDerivedRun`: successful occurrence import runs allowed
  between automated derived statistics runs while either occurrence source is incomplete. The
  default is `10`, allowing incomplete occurrence sources to make faster progress while still
  refreshing derived statistics periodically.
- `import.importLockFile`: local file used to prevent overlapping import processes on
  one host. It defaults to `writable/import.lock`; web-server and CLI users that run
  imports must be able to create and write it.

## Import scope

These values control the data imported by `Config\Import`. Changing taxonomic
rank, group, or region settings after an initial import requires careful data
refresh planning.

- `import.taxonRanks`: comma-separated reporting ranks. The default is `Order`, `Superfamily`,
  `Family`, `Genus`, and `Species`. It must include `Species`. The reports used for import of
  taxonomy from Indicia controls automatic mapping of non-reporting ancestors to the nearest
  configured reporting ancestor.
- `import.taxonRankMappings`: optional JSON object of explicit source-rank to reporting-rank
  exceptions, for example `{"Species aggregate":"Species"}` which causes species aggregates to be
  available for reporting in the same way that species are. Mapping targets must be configured
  reporting ranks; automatic nearest-ancestor mapping is used for ranks not listed here when
  calculating the [reporting taxon](glossary.md#reporting-taxon), but a link to the exact taxon as
  recorded is also preserved.
- `import.taxonGroups`: CSV list of taxon groups to import. Use CSV quoting for
  a group name that contains a comma.
- `import.geographicRegions`: JSON object of installation-time geography groups. See
  [Geographic regions](#geographic-regions). The old CSV format and
  `import.geographicRegionLocationType` are no longer supported. Import CLI commands stop with
  a link to this section when they detect the old settings; update `.env` before retrying.

These scope values are also applied to NBN Atlas occurrence imports. A change affects which records
are eligible, so refresh the relevant occurrence data and derived statistics afterwards:

- `import.geographicRegions` builds one filter per group using its configured `nbnField`
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

### Geographic regions

Configure regions before population. Each group has a stable key, an `indiciaLocationType`,
an NBN layer field (`nbnField`), and a non-empty `locations` list. Each location requires its exact
`irecordName`. Optional `nbnName` overrides that name for NBN only. Names are full, unencoded
strings; the HTTP client URL encodes them. Do not use short LRC IDs as filter values.

Every location must exist in iRecord under the configured type and name, be indexed for
occurrence filtering, and have a usable boundary polygon. A centroid alone is not sufficient.
Location codes, or location IDs when codes are blank, must identify configured regions uniquely.

Example scope combining the default VCs with all four LRCs:

```json
{
  "vice-counties": {
    "indiciaLocationType": "Vice County",
    "nbnField": "cl254",
    "locations": [
      {"irecordName": "Cheshire"},
      {"irecordName": "South Lancashire"},
      {"irecordName": "West Lancashire"}
    ]
  },
  "local-record-centres": {
    "indiciaLocationType": "LRC Boundary",
    "nbnField": "cl1012",
    "locations": [
      {"irecordName": "Lancashire Environmental Records Network"},
      {
        "irecordName": "RECORD - Biodiversity Information Centre for Cheshire, Halton, Warrington & Wirral",
        "nbnName": "RECORD - Biodiversity Information Centre for Cheshire, Halton, Warrington & Wirral."
      },
      {"irecordName": "Greater Manchester Local Record Centre"},
      {"irecordName": "Merseyside BioBank"}
    ]
  }
}
```

Put the JSON on one line, enclosed in single quotes, in `.env`:

```dotenv
import.geographicRegions = '{"vice-counties":{"indiciaLocationType":"Vice County","nbnField":"cl254","locations":[{"irecordName":"Cheshire"},{"irecordName":"South Lancashire"},{"irecordName":"West Lancashire"}]},"local-record-centres":{"indiciaLocationType":"LRC Boundary","nbnField":"cl1012","locations":[{"irecordName":"Lancashire Environmental Records Network"},{"irecordName":"RECORD - Biodiversity Information Centre for Cheshire, Halton, Warrington & Wirral","nbnName":"RECORD - Biodiversity Information Centre for Cheshire, Halton, Warrington & Wirral."},{"irecordName":"Greater Manchester Local Record Centre"},{"irecordName":"Merseyside BioBank"}]}}'
```

The final full stop in the RECORD NBN name is intentional; its iRecord name has no full stop.
Without an override, both sources use `irecordName`. Group keys use lowercase letters, digits
and hyphens, start with a letter, and have at most 29 characters to fit stored progress keys.
Invalid or empty configuration,
unknown properties and duplicate type/name pairs fail explicitly.

The default remains the three VCs; LRCs are included only when configured. Scope changes after
population are not reconciled automatically. For the one-off v1.1.0 upgrade, follow the
[changelog](../CHANGELOG.md#installation-and-upgrade), including the full membership rebuild.

## Staff search presentation

- `taxonSearch.classificationRanks`: comma-separated, ordered reporting ranks shown as
  classification filters and columns on the occurrence and taxa indexes. Defaults to `Family`.
  Use an empty string to hide classification filters, or `Family,Genus` to show both.

This is a presentation subset of `import.taxonRanks`, not an import or schema setting.
Every selected rank needs imported rank metadata and its existing `<rank>_id` columns on both
taxa and occurrences. The default Family filter is omitted with a warning if unavailable.
Other unavailable classification configurations raise an explicit configuration error.
The taxa-only Rank selector offers available configured reporting ranks independently.

```dotenv
taxonSearch.classificationRanks = 'Family,Genus'
```

## Incremental derived statistics

- `taxonStats.taxaPerBatch`: distinct taxon IDs included in one full-rebuild query; defaults to
  `100`.
- `taxonStats.maxTaxaPerRun`: distinct taxon IDs processed per full-rebuild invocation at most;
  defaults to `500`.
- `taxonStats.maxRuntimeSeconds`: maximum full-rebuild runtime per invocation; defaults to `20`
  seconds and is capped five seconds below PHP's `max_execution_time` when PHP has a limit.
- `taxonYearStats.historyYears`: completed years retained by the yearly statistics rebuild; zero
  means all available history.
- `taxonYearStats.yearsPerRun`: number of years processed by one staged rebuild invocation.
- `taxonYearStats.maxScopes`: maximum dirty taxon/year scopes processed by one incremental
  invocation; defaults to `100`.
- `taxonYearStats.maxRuntimeSeconds`: runtime limit for one incremental statistics invocation;
  defaults to `30` seconds.

The first population and reconciliation runs still use the resumable staged rebuild. Once the
aggregate tables contain data, occurrence changes are processed through the dirty-scope queue.

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

- `rarity.gridSquareWeight`: weight assigned to distinct active 2km squares;
  defaults to `1.0`.
- `rarity.occurrenceWeight`: weight assigned to active occurrences; defaults
  to `1.0`.

See [derived taxon rarity categories](import.md#derived-taxon-rarity-categories)
for how these weights are applied.

## Derived frequency trend statistics

- `taxonFrequencyTrend.gridSquareWeight`: weight assigned to the occupied 2km
  square slope; defaults to `1.0`.
- `taxonFrequencyTrend.occurrenceWeight`: weight assigned to the occurrence-count slope; defaults to
  `1.0`.
- `taxonFrequencyTrend.minimumOccupiedYears`: minimum years containing records or occupied squares
  required for a directional trend; defaults to `3`.
- `taxonFrequencyTrend.minimumRSquared`: minimum weighted regression fit required for a directional
  trend; defaults to `0.5`.
- `taxonFrequencyTrend.slopeTolerance`: weighted slopes at or below this absolute value are treated
  as stable; defaults to `0.000001`.
- `taxonFrequencyTrend.recentYearHalfLife`: half-life in years for exponential recent-year
  weighting; defaults to `3.0`. Set to `0` to disable recency weighting.

At least one weight must be greater than zero. See the frequency trend section in
[import.md](import.md#derived-taxon-frequency-trends) for how these weights are applied.

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

Use exact origins where possible. See [Installation](installation.md#api-configuration)
for examples.