# To do list

## Priority 1
[x] Populate grid square stats counts fields
[] warehouse reports into GitHub
[x] taxa.rarity_group_name default
[] test on live warehouse (recording schemes, conservation status)
[] populate geographic_regions_occurrences.

## Priority 2
[] Taxon stats table population spark command
[] Taxon year stats table population spark command
[] check the API auth endpoints all work
[] Re-organise the menu UI
[] Should occurrence data include grid centre lat/lon/east/north?
[] Installation notes about required warehouse reports.
[] Logo, favicon

## Priority 3
[] NBN Atlas import
[] NBN Atlas import - consider how this works with Indicia location filtering.
[] Indicia import - drop low-resolution occurrence data?
[] occurrence blocking UI and check applied to REST API
[] taxa blocking UI and check applied to REST API (taxa, occurrences & all related endpoints)
[] taxa.id_difficulty check
[] occurrence filtering on geographic_region
[] document import tracking tables
[] populating occurrences - might need to calculate grid ref from lat long if the output sref system<>'OSGB'
[] attach photos to taxa
[] Re-organise CSS as structures SASS or similar (grunt build?)
[] Assert species in taxonRanks on installation.

DONE
[x] Grid square stats table population spark command
[x] Populate grid square stats counts fields
[x] Allow Spark commands to be run from the UI.
[x] Allow Spark commands to be run from Cron.
[x] Summary of data contents and Spark tasks page.
[x] User admin UI.
[x] REST API JWT login as user which removes rate limits.
[x] Ensure that occurrences 2km grid square is calculated correctly on import.