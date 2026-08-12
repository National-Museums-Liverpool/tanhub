# To do list

## Priority 1
[] Might need to document that the NBN import only supports Watsonian Vice County filtering.
[] Document that NBN import filtering excludes queried records.
[] Ensure that rank Species sensu lato are treated as species, other taxa above species roll up to genus.
[] Roll up intermediate taxon ranks to the one above on both occurrences imports.
[] Add taxon rank to occurrence table, imports and views
[x] the filter on raw_speciesGroup for NBN Atlas import is probably wrong and needs amending
[] check that taxa table has a parent_id and that this skips unsupported ranks correctly.

## Priority 2

## Future ideas
[] Rationalise filtering on taxon group (Indicia) vs higher taxa (NBN)
[] Better UI for showing action or progress on import page

## Notes for NBN Import
- Coordinate uncertainty filter
- Public data only
- If an Indicia record, do insert but not update and set correct unique key format
- taxon groups filter
- regions filter

DONE
[x] Taxon stats table population spark command
[x] Taxon year stats table population spark command
[x] rarity score for grid squares
[x] Grid square stats table population spark command
[x] Populate grid square stats counts fields
[x] Allow Spark commands to be run from the UI.
[x] Allow Spark commands to be run from Cron.
[x] Summary of data contents and Spark tasks page.
[x] User admin UI.
[x] REST API JWT login as user which removes rate limits.
[x] Ensure that occurrences 2km grid square is calculated correctly on import.
[x] confirm that the links from taxa to recording schemes work and are clear
[x] Logo, favicon
[x] Re-organise the menu UI
[x] Should occurrence data include grid centre lat/lon/east/north?
[x] taxon photos
[x] Fast filter on occurrences higher geo region and/or taxon_identifier in API.
[x] rarity category for taxa
[x] populate geographic_regions_occurrences.
[x] Populate grid square stats counts fields
[x] warehouse reports into GitHub
[x] taxa.rarity_group_name default
[x] test on live warehouse (recording schemes, conservation status)
[x] grid square stats counts importer shows odd skipped info.
[x] ratity categories importer shows odd skipped info.
[x] occurrence blocking UI and check applied to REST API
[x] taxa blocking UI and check applied to REST API (taxa, occurrences & all related endpoints)
[x] attach photos to taxa
[x] Route to /update if not yet installed
[x] Route to /setup-admin-user after update if not yet set up.
[x] taxa.id_difficulty check
[x] occurrence filtering on geographic_region
[x] import_task_queue.task_key should be named same as import_offsets and import_runs source_key?
[x] import_task_queue.message => unnecessary if queue task discarded when done?
[x] check the API auth endpoints all work
[x] Summary data on home page.
[x] Re-organise CSS as structures SASS or similar (grunt build?)
[x] Assert species in taxonRanks on installation.
[x] Installation notes about required warehouse reports
[x] warehouse reports into tanhub GitHub
[x] Indicia import - drop low-resolution occurrence data?
[x] Force occurrences fetch to blurred, non-confidential released data only.
[x] populating occurrences - might need to calculate grid ref from lat long if the output sref system<>'OSGB'
[x] NBN Atlas import
[x] NBN Atlas import - consider how this works with Indicia location filtering. fq=cl254:Dorset.