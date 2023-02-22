# vermont-address-import

Scripts and data for importing VCGI addresses into OpenStreetMap.

Project proposal here: https://wiki.openstreetmap.org/wiki/VCGI_E911_address_points_import

## Usage

### Download fresh E911 data
1. Download the latest [VT Data - E911 Site Locations (address points)](https://geodata.vermont.gov/datasets/VCGI::vt-data-e911-site-locations-address-points-1/about) file as GeoJSON.

2. Extract the full-state GeoJSON file into per-town files
   ```
   ./extract_town_points.php -v VT_Data_-_E911_Site_Locations_\(address_points\).geojson
   ```

### Regenerate all draft data files:
```
./generate_all.php -v
```

Help:
```
Generate new output files in data_files_to_import/draft/ for every
input file in town_e911_address_points/

./generate_all.php [-hv] [--help] [--verbose]

  -h --help           Show this help
  -v --verbose        Print status output.

  <file.geojson>      The input geojson file.

```

### Generate a single data file:
```
./generate_osm_file_from_e911_geojson.php <file>
```

Help:
```
./generate_osm_file_from_e911_geojson.php [-hv] [--help] [--verbose] [--output-type=osm|tab|geojson] <file.geojson>

  -h --help           Show this help
  -v --verbose        Print errors at the end.
  --output-type       Format of the output, default is osm.

  <file.geojson>      The input geojson file.
```


### Conflation

Requires `php-sqlite` (maybe version-specific on your platform, e.g. `php81-sqlite`) and `spatialite`.
Your `php.ini` probably needs the location of `mod_spatialite.so` added. Something like:

    sqlite3.extension_dir = /opt/local/lib/

1. (optional) Download/refresh the existing Vermont address center points from OSM via OverPass.

    ./download_osm_addresses.php

2. (optional) Rebuild the SQLite database holding these address points.

    ./reload_osm_addresses.php

3. Generate bucketed data-sets for a town. This will go through all of the
   addresses in the input file and see if they exist in the local database of OSM
   addresses.

   If there is a perfect match on tags they will be placed in an `osm_match/`
   file, or an `osm_reviews/` file if there are multiple matches or a large offset.

   If there is a likely candidate for the address that has different tags, the
   input will be placed in the `osm_conflict/` file.

   If there isn't any likely candidate for the adddress, it will be placed in a
   `no_osm_match/` file.

    ./conflate_town.php -v data_files_to_import/draft/middlebury_addresses.osm

   The verbose output can be examined to see why the address got categorized the
   way it did.
