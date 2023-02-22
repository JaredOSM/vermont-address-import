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
