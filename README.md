# vermont-address-import

Scripts and data for importing VCGI addresses into OpenStreetMap.

Project proposal here: https://wiki.openstreetmap.org/wiki/VCGI_E911_address_points_import

## Usage

Regenerate all draft data files:
```
./generate_all.php -v
```

Generate a single data file:
```
./generate_osm_file_from_e911_geojson.php <file>
```
