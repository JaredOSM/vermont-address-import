#!/usr/bin/env php
<?php

$print_errors_at_end = false;
// tab, osm, or geojson
// (note: when using tab outout, addresses that are missing a house number are outputted
// so they can be reviewed. They are not included in the osm output type.)
// geojson was quickly hacked on as a way of filtering out bad features from the original geojson file.
$output_type = "osm";

$help = "

". $argv[0] . " [-hv] [--help] [--verbose] [--output-type=osm|tab|geojson] <file.geojson>

  -h --help           Show this help
  -v --verbose        Print errors at the end.
  --output-type       Format of the output, default is osm.

  <file.geojson>      The input geojson file.

";

#options
$options = getopt("h", ["help", "output-type::"], $reset_index);
if ($options === FALSE || isset($options["h"]) || isset($options["help"])) {
  fwrite(STDERR, $help);
  exit(1);
}
if (isset($options["output-type"])) {
  if (!in_array($options["output-type"], ["osm", "tab", "geojson"])) {
    fwrite(STDERR, "Invalid output type: '".$options["output-type"]."'. Must be one of osm, tab, geojson.");
    fwrite(STDERR, $help);
    exit(2);
  }
  $output_type = $options["output-type"];
}
if (isset($options['v']) || isset($options['verbose'])) {
  $print_errors_at_end = true;
}

# file
$pos_args = array_slice($argv, $reset_index);
if (!count($pos_args)) {
  fwrite(STDERR, "You must specify an input file.");
  fwrite(STDERR, $help);
  exit(2);
}
$file = $pos_args[0];
if (!file_exists($file)) {
  fwrite(STDERR, "File $file does not exist.");
  fwrite(STDERR, $help);
  exit(3);
}
if (!is_readable($file)) {
  fwrite(STDERR, "File $file is not readable.");
  fwrite(STDERR, $help);
  exit(3);
}

// Post processing steps
// 1. search the output for the string "error" to deal with any issues
// 2. look for streets that have non-trivial capitalization or punctuation
//  a. streets that start with Mc
//  b. streets that have apostrophes (eg. O'Niel)

// keep track of the exclude list in a Google sheet and expose it as a JSON feed
$exclude_addresses_url = 'https://opensheet.elk.sh/1KBFFDpwLjOhRtCZCuxOd4D4ozBBaxOWhnw0Koe_25Tc/e911+Address+Point+do+not+import+list';
$exclude_addresses = json_decode(file_get_contents($exclude_addresses_url), true);
$excluded_output = array(); // store the records we skipped for logging at the end

///////////////////////////////////////////////////////

$data = json_decode(file_get_contents($file), true);
if (is_null($data)) {
  fwrite(STDERR, "Failed decoding JSON from $file");
  fwrite(STDERR, $help);
  exit(4);
}

$node_id = -100;
$all_errors = array();

$output = output_header($output_type);

foreach($data['features'] as $feature) {
    $feature_errors = array();

    if(!empty($feature['properties']['ESITEID'])) {
        $esiteid = $feature['properties']['ESITEID'];
    } else {
        $esiteid = NULL;
        $feature_errors[] = "ESITEID value is empty";
    }

    if(!empty($feature['properties']['GPSX'])) {
        $long = $feature['properties']['GPSX'];
    } else {
        $long = NULL;
        $feature_errors[] = "GPSX value is empty (esiteid: " . $esiteid . ")";
    }

    if(!empty($feature['properties']['GPSY'])) {
        $lat = $feature['properties']['GPSY'];
    } else {
        $lat = NULL;
        $feature_errors[] = "GPSY value is empty (esiteid: " . $esiteid . ")";
    }

    if(!empty($feature['properties']['TOWNNAME'])) {
        $town_name = ucwords(strtolower($feature['properties']['TOWNNAME']));
    } else {
        $town_name = NULL;
        $feature_errors[] = "TOWNNAME value is empty (esiteid: " . $esiteid . ")";
    }

    // Most addresses will not use addr:unit, only ones with a numeric HOUSE_NUMBERSUFFIX.
    $unit = NULL;

    // confirm that the HOUSE_NUMBER is not empty, is a number greater than zero
    // VCGI contains lots of entries with a house number of "0"
    if(!empty($feature['properties']['HOUSE_NUMBER']) && is_numeric($feature['properties']['HOUSE_NUMBER']) && $feature['properties']['HOUSE_NUMBER'] > 0) {
        $house_number = $feature['properties']['HOUSE_NUMBER'];

        // check for prefix on house number (eg. esiteid 757868)
        if(!empty($feature['properties']['HOUSE_NUMBERPREFIX'])) {
            $prefix = trim($feature['properties']['HOUSE_NUMBERPREFIX']);
            // Don't use spaces to concatenate alpha-only prefixes.
            if (preg_match('/^[A-Z]+$/', $prefix)) {
                $house_number = $prefix . $house_number;
            }
            // If a non-alpha prefix is found, include a space to avoid merging
            // numbers.
            else {
                $house_number = $prefix . " " . $house_number;
            }
        }

        // check for suffix on house number (eg. esiteid 154277)
        if(!empty($feature['properties']['HOUSE_NUMBERSUFFIX'])) {
            $suffix = trim($feature['properties']['HOUSE_NUMBERSUFFIX']);
            // Don't use spaces to concatenate alpha-only suffix.
            if (preg_match('/^[A-Z]+$/i', $suffix)) {
                $house_number = $house_number . $suffix;
            }
            // Plain numbers should go in addr:unit
            elseif (preg_match('/^\d+$/', $suffix)) {
              $unit = $suffix;
            }
            // Unit ranges should go in addr:unit
            elseif (preg_match('/^(UNITS)?(\d+-\d+)$/', $suffix, $unit_matches)) {
              $unit = $unit_matches[2];
            }
            // Any other cases, like "1/2", concatenate with a space.
            else {
                $house_number = $house_number . " " . $suffix;
            }
        }

    } else {
        $house_number = NULL;
        $feature_errors[] = "HOUSE_NUMBER is invalid: " . $feature['properties']['HOUSE_NUMBER'] . " (esiteid: " . $esiteid . ")";
    }

    if(!empty($feature['properties']['SN'])) {
        $street = build_street_name($feature['properties']);
    } else {
        $street = NULL;
        $feature_errors[] = "SN (street name) value is empty: (esiteid: " . $esiteid . ")";
    }

    if(!empty($feature['properties']['ZIP'])) {
        $zip_code = $feature['properties']['ZIP'];
    } else {
        $zip_code = NULL;
        $feature_errors[] = "ZIP value is empty (esiteid: " . $esiteid . ")";
    }

    $all_errors[] = $feature_errors;

    // search the esiteid in the exclude list.
    // if it is found in the exclude list, don't output it
    $key = array_search($esiteid, array_column($exclude_addresses, 'esiteid'));
    if($key === false) {    // the esiteid was NOT found in the exclude list

        // if we don't have any errors in our data
        if(count($feature_errors) == 0 && $output_type == "osm") {

            // leaving out timestamp from node: timestamp='2022-09-12T01:50:00Z'
            $output .= "  <node id='" . $node_id . "' visible='true' lat='" . $lat . "' lon='" . $long . "'>\n";
            $output .= "    <tag k='addr:city' v='" . $town_name . "' />\n";
            $output .= "    <tag k='addr:housenumber' v='" . $house_number . "' />\n";
            if (!empty($unit)) {
                $output .= "    <tag k='addr:unit' v='" . $unit . "' />\n";
            }
            $output .= "    <tag k='addr:street' v='" . $street . "' />\n";
            // ZIP codes in E911 may not be correct.
            // $output .= "    <tag k='addr:postcode' v='" . $zip_code . "' />\n";
            $output .= "    <tag k='addr:state' v='VT' />\n";
            $output .= "    <tag k='ref:vcgi:esiteid' v='" . $esiteid . "' />\n";
            // use this tag in the changeset tags instead of node tag
            // $output .= "    <tag k='source' v='VCGI/E911_address_points' />\n";
            $output .= "  </node>\n";


        } elseif($output_type == "tab") {
            $output .= $node_id . "\t" . $lat . "\t" . $long . "\t";
            $output .= $town_name . "\t";
            $output .= $house_number . "\t";
            $output .= $unit . "\t";
            $output .= $street . "\t";
            $output .= $zip_code . "\t";
            $output .= $esiteid . "\n";

        } elseif(count($feature_errors) == 0 && $output_type == "geojson") {

            $coordinates = array($long, $lat);
            $properties = array("house_number" => strval($house_number), "unit" => strval($unit), "street" => $street, "city" => $town_name, "state" => "VT", "esiteid" => strval($esiteid));
            $geometry = array("type" => "Point", "coordinates" => $coordinates);
            $feature = array("type" => "Feature", "properties" => $properties, "geometry" => $geometry);


            // todo: geojson output is a hack
            // this adds an extraneous comma to the last feature that needs to be removed
            // but not sure it is worth reworking
            $output .= json_encode($feature) . ",\n";

        }
    } else {
        // print "address on exclude list.  esiteid: " . $esiteid . "\n";
        $excluded_output[] = $esiteid;
    }
    $node_id--;
    unset($feature_errors);
}

$output .= output_footer($output_type);
print $output;

if($print_errors_at_end) {
    fwrite(STDERR, "\n----------ERRORS----------\n");
    if(count($all_errors) > 0) {
        $i = 1;
        foreach($all_errors as $feature_errors) {
            foreach($feature_errors as $error_item) {
                fwrite(STDERR, $i . " " . $error_item . "\n");
                $i++;
            }

        }
    } else {
        fwrite(STDERR, "no errors\n");
    }
    // show esiteids that were found on the exclude list
    if(count($excluded_output) > 0) {
        fwrite(STDERR, "\n----------EXCLUDED ESITEIDS----------\n");

        foreach($excluded_output as $excluded_esiteid) {
            fwrite(STDERR, "Excluded esiteid: " . $excluded_esiteid . "\n");
        }
    }
}

///////////////////////////////////////////
//
// Functions
//
///////////////////////////////////////////


function output_header($output_type) {
    if($output_type == "osm") {
        $header = "<?xml version='1.0' encoding='UTF-8'?>\n<osm version='0.6' generator='JOSM'>\n";
    } elseif($output_type == "geojson") {
        $header = "{\"type\": \"FeatureCollection\", \"features\": [\n";
    } else {
        $header = "";
    }

    return $header;
}

function output_footer($output_type) {
    if($output_type == "osm") {
        $footer = "</osm>\n";
    } elseif($output_type == "geojson") {
        $footer = "]}\n";
    } else {
        $footer = "";
    }

    return $footer;
}

function build_street_name($feature_properties) {

    $final_street_name = "";

    // Prefix Direction
    if(!empty($feature_properties['PD'])) {

        $prefix_direction = trim($feature_properties['PD']);
        if(!empty($prefix_direction)) {
            $prefix_direction = expand_direction($feature_properties['PD']);
            $final_street_name .= $prefix_direction . " ";
        }
    }

    // Street Name
    if(!empty($feature_properties['SN'])) {

        $street_base_name = trim($feature_properties['SN']);

        if(!empty($street_base_name)) {
            $street_base_name = normalize_street_base_name($street_base_name);

            $final_street_name .= $street_base_name . " ";
        }
    }

    // Street Type
    if(!empty($feature_properties['ST'])) {

        $street_suffix = trim($feature_properties['ST']);

        if(!empty($street_suffix)) {
            $street_suffix = expand_street_name_suffix($street_suffix);

            $final_street_name .= $street_suffix . " ";
        }
    }

    // suffix direction
    if(!empty($feature_properties['SD'])) {

        $suffix_direction = trim($feature_properties['SD']);

        if(!empty($suffix_direction)) {
            $suffix_direction = expand_direction($suffix_direction);

            $final_street_name .= $suffix_direction;
        }
    }

    $final_street_name = trim($final_street_name);

    return $final_street_name;
}

/* PD field from VCGI is one of:
E, N, S, SE, W
*/
function expand_direction($prefix_direction) {

    $prefix_direction = trim($prefix_direction);

    if(!empty($prefix_direction)) {
        switch ($prefix_direction) {
            case 'E':
                $expanded_prefix_direction = "East";
                break;
            case 'N':
                $expanded_prefix_direction = "North";
                break;
            case 'S':
                $expanded_prefix_direction = "South";
                break;
            case 'SE':
                $expanded_prefix_direction = "Southeast";
                break;
            case 'W':
                $expanded_prefix_direction = "West";
                break;
            default:
                $expanded_prefix_direction = "error 100";
                break;
        }
    } else {
        $expanded_prefix_direction = NULL;
    }

    return $expanded_prefix_direction;
}

function normalize_street_base_name($street_name) {

    // todo: deal with street names with apostrophes (eg. O'Neil)

    $street_name_title_cased = ucwords(strtolower(trim($street_name)));

    // If street name starts with "Vt " replace with "Vermont "
    if (preg_match('/^Vt (.+)/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = "Vermont " . $matches[1];

        // Make sure any trailing letters like in "22A" are capitalized.
        if (preg_match('/^(.+\d+)([a-z]+)$/i', $street_name_title_cased, $matches)) {
            $street_name_title_cased = $matches[1].strtoupper($matches[2]);
        }
    }

    // If street name starts with Mc, fix it so next letter is also uppercase.
    // todo: might be exceptions to this rule
    if (strpos($street_name_title_cased, 'Mc') === 0) {
        $street_name_title_cased = 'Mc' . ucwords(substr($street_name_title_cased, 2, strlen($street_name_title_cased)));
    }

    // OSM shows "U.S. Route #" where was e911 has US Route 5
    if(preg_match('/us route (.+)/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = "U.S. Route " . $matches[1];
    }

    // VCGI data uses "NFR", which should be expanded to "National Forest Road"
    if(preg_match('/nfr (.+)/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = "National Forest Road " . $matches[1];
    }

    // expand when hwy is in the middle of the street name (eg. Town Hwy 11)
    // originally found in Granville
    if(preg_match('/town hwy (.+)/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = "Town Highway " . $matches[1];
    }

    // Hubbardton has a street called LHCS that needs to be all caps
    if(preg_match('/^lhcs(.*)/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = "LHCS" . $matches[1];
    }

    // Hubbardton has a street called "SFH"... not sure what it stands for (State Forest ?), but capitlizing it
    if(preg_match('/^sfh/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = "SFH";
    }

    // Middlebury has a street name HMKL that should be capitalized. (esiteid: 155140)
    if(preg_match('/^hmkl/i', $street_name_title_cased)) {
        $street_name_title_cased = "HMKL";
    }

    // Brookfield has a street with "EXT" in the ST (street type) field, which causes
    // Rd and Ln to be put at the end of the SN (street name) field, so we need to expand the street name abbreviation as well
    if(preg_match('/(.+) (Ave|Dr|Ln|Rd|St)$/i', $street_name_title_cased, $matches)) {
        $expanded_suffix = expand_street_name_suffix($matches[2]);
        $street_name_title_cased = $matches[1] . " " . $expanded_suffix;
    }

    // Brookfield has a street that starts with "Dr".  Expand to "Doctor"
    if(preg_match('/^Dr (.+)/i', $street_name_title_cased, $matches)) {
        $street_name_title_cased = "Doctor " . $matches[1];
    }

    return $street_name_title_cased;
}

// street name suffix (eg. Avenue) are abbreviated in VCGI data
function expand_street_name_suffix($street_name_suffix) {

    $street_name_suffix = strtolower(trim($street_name_suffix));

    // list from https://github.com/blackboxlogic/OsmTagsTranslator/blob/master/OsmTagsTranslator/Lookups/StreetSuffixes.json
    $street_suffixes = array("allee" => "Alley",
                            "alley" => "Alley",
                            "ally" => "Alley",
                            "aly" => "Alley",
                            "anex" => "Anex",
                            "annex" => "Anex",
                            "annx" => "Anex",
                            "anx" => "Anex",
                            "arc" => "Arcade",
                            "arcade" => "Arcade",
                            "av" => "Avenue",
                            "ave" => "Avenue",
                            "aven" => "Avenue",
                            "avenu" => "Avenue",
                            "avenue" => "Avenue",
                            "avn" => "Avenue",
                            "avnue" => "Avenue",
                            "bayoo" => "Bayou",
                            "bayou" => "Bayou",
                            "bch" => "Beach",
                            "beach" => "Beach",
                            "bend" => "Bend",
                            "blf" => "Bluff",
                            "blfs" => "Bluffs",
                            "bluf" => "Bluff",
                            "bluff" => "Bluff",
                            "bluffs" => "Bluffs",
                            "blvd" => "Boulevard",
                            "bnd" => "Bend",
                            "bot" => "Bottom",
                            "bottm" => "Bottom",
                            "bottom" => "Bottom",
                            "boul" => "Boulevard",
                            "boulevard" => "Boulevard",
                            "boulv" => "Boulevard",
                            "br" => "Branch",
                            "branch" => "Branch",
                            "brdge" => "Bridge",
                            "brg" => "Bridge",
                            "bridge" => "Bridge",
                            "brk" => "Brook",
                            "brnch" => "Branch",
                            "brook" => "Brook",
                            "brooks" => "Brooks",
                            "btm" => "Bottom",
                            "burg" => "Burg",
                            "burgs" => "Burgs",
                            "byp" => "Bypass",
                            "bypa" => "Bypass",
                            "bypas" => "Bypass",
                            "bypass" => "Bypass",
                            "byps" => "Bypass",
                            "camp" => "Camp",
                            "canyn" => "Canyon",
                            "canyon" => "Canyon",
                            "cape" => "Cape",
                            "causeway" => "Causeway",
                            "causwa" => "Causeway",
                            "cen" => "Center",
                            "cent" => "Center",
                            "center" => "Center",
                            "centers" => "Centers",
                            "centr" => "Center",
                            "centre" => "Center",
                            "cir" => "Circle",
                            "circ" => "Circle",
                            "cirs" => "Circles",
                            "circl" => "Circle",
                            "circle" => "Circle",
                            "circles" => "Circles",
                            "clb" => "Club",
                            "clf" => "Cliff",
                            "clfs" => "Cliffs",
                            "cliff" => "Cliff",
                            "cliffs" => "Cliffs",
                            "club" => "Club",
                            "cmn" => "Common",
                            "cmns" => "Commons",
                            "cmp" => "Camp",
                            "cnter" => "Center",
                            "cntr" => "Center",
                            "cnyn" => "Canyon",
                            "common" => "Common",
                            "commons" => "Commons",
                            "cor" => "Corner",
                            "corner" => "Corner",
                            "corners" => "Corners",
                            "cors" => "Corners",
                            "course" => "Course",
                            "court" => "Court",
                            "courts" => "Courts",
                            "cove" => "Cove",
                            "coves" => "Coves",
                            "cp" => "Camp",
                            "cpe" => "Cape",
                            "crcl" => "Circle",
                            "crcle" => "Circle",
                            "creek" => "Creek",
                            "cres" => "Crescent",
                            "crescent" => "Crescent",
                            "crest" => "Crest",
                            "crk" => "Creek",
                            "crossing" => "Crossing",
                            "crossroad" => "Crossroad",
                            "crossroads" => "Crossroads",
                            "crse" => "Course",
                            "crsent" => "Crescent",
                            "crsnt" => "Crescent",
                            "crssng" => "Crossing",
                            "crst" => "Crest",
                            "cswy" => "Causeway",
                            "ct" => "Court",
                            "ctr" => "Center",
                            "cts" => "Courts",
                            "curv" => "Curve",
                            "curve" => "Curve",
                            "cv" => "Cove",
                            "dale" => "Dale",
                            "dam" => "Dam",
                            "div" => "Divide",
                            "divide" => "Divide",
                            "dl" => "Dale",
                            "dm" => "Dam",
                            "dr" => "Drive",
                            "driv" => "Drive",
                            "drive" => "Drive",
                            "drives" => "Drives",
                            "drv" => "Drive",
                            "dv" => "Divide",
                            "dvd" => "Divide",
                            "est" => "Estate",
                            "estate" => "Estate",
                            "estates" => "Estates",
                            "ests" => "Estates",
                            "exp" => "Expressway",
                            "expr" => "Expressway",
                            "express" => "Expressway",
                            "expressway" => "Expressway",
                            "expw" => "Expressway",
                            "expy" => "Expressway",
                            "ext" => "Extension",
                            "extension" => "Extension",
                            "extn" => "Extension",
                            "extnsn" => "Extension",
                            "exts" => "Extensions",
                            "fall" => "Fall",
                            "falls" => "Falls",
                            "ferry" => "Ferry",
                            "field" => "Field",
                            "fields" => "Fields",
                            "flat" => "Flat",
                            "flats" => "Flats",
                            "fld" => "Field",
                            "flds" => "Fields",
                            "fls" => "Falls",
                            "flt" => "Flat",
                            "flts" => "Flats",
                            "ford" => "Ford",
                            "fords" => "Fords",
                            "forest" => "Forest",
                            "forests" => "Forest",
                            "forg" => "Forge",
                            "forge" => "Forge",
                            "forges" => "Forges",
                            "fork" => "Fork",
                            "forks" => "Forks",
                            "fort" => "Fort",
                            "frd" => "Ford",
                            "freeway" => "Freeway",
                            "freewy" => "Freeway",
                            "frg" => "Forge",
                            "frk" => "Fork",
                            "frks" => "Forks",
                            "frry" => "Ferry",
                            "frst" => "Forest",
                            "frt" => "Fort",
                            "frway" => "Freeway",
                            "frwy" => "Freeway",
                            "fry" => "Ferry",
                            "ft" => "Fort",
                            "fwy" => "Freeway",
                            "garden" => "Garden",
                            "gardens" => "Gardens",
                            "gardn" => "Garden",
                            "gateway" => "Gateway",
                            "gatewy" => "Gateway",
                            "gatway" => "Gateway",
                            "gdn" => "Garden",
                            "gdns" => "Gardens",
                            "glen" => "Glen",
                            "glens" => "Glens",
                            "gln" => "Glen",
                            "grden" => "Garden",
                            "grdn" => "Garden",
                            "grdns" => "Gardens",
                            "green" => "Green",
                            "greens" => "Greens",
                            "grn" => "Green",
                            "grov" => "Grove",
                            "grove" => "Grove",
                            "groves" => "Groves",
                            "grv" => "Grove",
                            "gtway" => "Gateway",
                            "gtwy" => "Gateway",
                            "harb" => "Harbor",
                            "harbor" => "Harbor",
                            "harbors" => "Harbors",
                            "harbr" => "Harbor",
                            "haven" => "Haven",
                            "hbr" => "Harbor",
                            "highway" => "Highway",
                            "highwy" => "Highway",
                            "hill" => "Hill",
                            "hills" => "Hills",
                            "hiway" => "Highway",
                            "hiwy" => "Highway",
                            "hl" => "Hill",
                            "hllw" => "Hollow",
                            "hls" => "Hills",
                            "hollow" => "Hollow",
                            "hollows" => "Hollow",
                            "holw" => "Hollow",
                            "holws" => "Hollow",
                            "hrbor" => "Harbor",
                            "ht" => "Heights",
                            "hts" => "Heights",
                            "hvn" => "Haven",
                            "hway" => "Highway",
                            "hwy" => "Highway",
                            "inlt" => "Inlet",
                            "is" => "Island",
                            "island" => "Island",
                            "islands" => "Islands",
                            "isle" => "Isle",
                            "isles" => "Isle",
                            "islnd" => "Island",
                            "islnds" => "Islands",
                            "iss" => "Islands",
                            "jct" => "Junction",
                            "jction" => "Junction",
                            "jctn" => "Junction",
                            "jctns" => "Junctions",
                            "jcts" => "Junctions",
                            "junction" => "Junction",
                            "junctions" => "Junctions",
                            "junctn" => "Junction",
                            "juncton" => "Junction",
                            "key" => "Key",
                            "keys" => "Keys",
                            "knl" => "Knoll",
                            "knls" => "Knolls",
                            "knol" => "Knoll",
                            "knoll" => "Knoll",
                            "knolls" => "Knolls",
                            "ky" => "Key",
                            "kys" => "Keys",
                            "lake" => "Lake",
                            "lakes" => "Lakes",
                            "land" => "Land",
                            "landing" => "Landing",
                            "lane" => "Lane",
                            "lck" => "Lock",
                            "lcks" => "Locks",
                            "ldg" => "Lodge",
                            "ldge" => "Lodge",
                            "lf" => "Loaf",
                            "lgt" => "Light",
                            "light" => "Light",
                            "lights" => "Lights",
                            "lk" => "Lake",
                            "lks" => "Lakes",
                            "ln" => "Lane",
                            "lndg" => "Landing",
                            "lndng" => "Landing",
                            "loaf" => "Loaf",
                            "lock" => "Lock",
                            "locks" => "Locks",
                            "lodg" => "Lodge",
                            "lodge" => "Lodge",
                            "loop" => "Loop",
                            "loops" => "Loop",
                            "mall" => "Mall",
                            "manor" => "Manor",
                            "manors" => "Manors",
                            "mdw" => "Meadows",
                            "mdws" => "Meadows",
                            "meadow" => "Meadow",
                            "meadows" => "Meadows",
                            "medows" => "Meadows",
                            "mews" => "Mews",
                            "mill" => "Mill",
                            "mills" => "Mills",
                            "missn" => "Mission",
                            "ml" => "Mill",
                            "mnr" => "Manor",
                            "mnrs" => "Manors",
                            "mnt" => "Mount",
                            "mntain" => "Mountain",
                            "mntn" => "Mountain",
                            "mntns" => "Mountains",
                            "motorway" => "Motorway",
                            "mount" => "Mount",
                            "mountain" => "Mountain",
                            "mountains" => "Mountains",
                            "mountin" => "Mountain",
                            "mssn" => "Mission",
                            "mt" => "Mount",
                            "mtin" => "Mountain",
                            "mtn" => "Mountain",
                            "nck" => "Neck",
                            "neck" => "Neck",
                            "orch" => "Orchard",
                            "orchard" => "Orchard",
                            "orchrd" => "Orchard",
                            "oval" => "Oval",
                            "overpass" => "Overpass",
                            "ovl" => "Oval",
                            "park" => "Park",
                            "parks" => "Parks",
                            "parkway" => "Parkway",
                            "parkways" => "Parkways",
                            "parkwy" => "Parkway",
                            "pass" => "Pass",
                            "passage" => "Passage",
                            "path" => "Path",
                            "paths" => "Path",
                            "pd" => "Pond",
                            "pike" => "Pike",
                            "pikes" => "Pike",
                            "pine" => "Pine",
                            "pines" => "Pines",
                            "pkway" => "Parkway",
                            "pkwy" => "Parkway",
                            "pkwys" => "Parkways",
                            "pky" => "Parkway",
                            "pl" => "Place",
                            "plain" => "Plain",
                            "plains" => "Plains",
                            "plaza" => "Plaza",
                            "pln" => "Plain",
                            "plns" => "Plains",
                            "plz" => "Plaza",
                            "plza" => "Plaza",
                            "pnes" => "Pines",
                            "point" => "Point",
                            "points" => "Points",
                            "port" => "Port",
                            "ports" => "Ports",
                            "pr" => "Prairie",
                            "prairie" => "Prairie",
                            "prk" => "Park",
                            "prr" => "Prairie",
                            "prt" => "Port",
                            "prts" => "Ports",
                            "psge" => "Passage",
                            "pt" => "Point",
                            "pts" => "Points",
                            "rad" => "Radial",
                            "radial" => "Radial",
                            "radiel" => "Radial",
                            "radl" => "Radial",
                            "ramp" => "Ramp",
                            "ranch" => "Ranch",
                            "ranches" => "Ranch",
                            "rapid" => "Rapid",
                            "rapids" => "Rapids",
                            "rd" => "Road",
                            "rdg" => "Ridge",
                            "rdge" => "Ridge",
                            "rdgs" => "Ridges",
                            "rds" => "Roads",
                            "rest" => "Rest",
                            "ridge" => "Ridge",
                            "ridges" => "Ridges",
                            "riv" => "River",
                            "river" => "River",
                            "rivr" => "River",
                            "rnch" => "Ranch",
                            "rnchs" => "Ranch",
                            "road" => "Road",
                            "roads" => "Roads",
                            "route" => "Route",
                            "row" => "Row",
                            "rpd" => "Rapid",
                            "rpds" => "Rapids",
                            "rst" => "Rest",
                            "rue" => "Rue",
                            "run" => "Run",
                            "rvr" => "River",
                            "shl" => "Shoal",
                            "shls" => "Shoals",
                            "shoal" => "Shoal",
                            "shoals" => "Shoals",
                            "shoar" => "Shore",
                            "shoars" => "Shores",
                            "shore" => "Shore",
                            "shores" => "Shores",
                            "shr" => "Shore",
                            "shrs" => "Shores",
                            "skyway" => "Skyway",
                            "smt" => "Summit",
                            "spg" => "Spring",
                            "spgs" => "Springs",
                            "spng" => "Spring",
                            "spngs" => "Springs",
                            "spring" => "Spring",
                            "springs" => "Springs",
                            "sprng" => "Spring",
                            "sprngs" => "Springs",
                            "spur" => "Spur",
                            "spurs" => "Spurs",
                            "sq" => "Square",
                            "sqr" => "Square",
                            "sqre" => "Square",
                            "sqrs" => "Squares",
                            "squ" => "Square",
                            "square" => "Square",
                            "squares" => "Squares",
                            "st" => "Street",
                            "sta" => "Station",
                            "station" => "Station",
                            "statn" => "Station",
                            "stn" => "Station",
                            "str" => "Street",
                            "stra" => "Stravenue",
                            "strav" => "Stravenue",
                            "straven" => "Stravenue",
                            "stravenue" => "Stravenue",
                            "stravn" => "Stravenue",
                            "stream" => "Stream",
                            "street" => "Street",
                            "streets" => "Streets",
                            "streme" => "Stream",
                            "strm" => "Stream",
                            "strt" => "Street",
                            "strvn" => "Stravenue",
                            "strvnue" => "Stravenue",
                            "sumit" => "Summit",
                            "sumitt" => "Summit",
                            "summit" => "Summit",
                            "ter" => "Terrace",
                            "terr" => "Terrace",
                            "terrace" => "Terrace",
                            "throughway" => "Throughway",
                            "tpke" => "Turnpike",
                            "trace" => "Trace",
                            "traces" => "Trace",
                            "track" => "Track",
                            "tracks" => "Track",
                            "trafficway" => "Trafficway",
                            "trail" => "Trail",
                            "trailer" => "Trailer",
                            "trails" => "Trail",
                            "trak" => "Track",
                            "trce" => "Trace",
                            "trk" => "Track",
                            "trks" => "Track",
                            "trl" => "Trail",
                            "trlr" => "Trailer",
                            "trlrs" => "Trailer",
                            "trls" => "Trail",
                            "trnpk" => "Turnpike",
                            "trwy" => "Throughway",
                            "tunel" => "Tunnel",
                            "tunl" => "Tunnel",
                            "tunls" => "Tunnel",
                            "tunnel" => "Tunnel",
                            "tunnels" => "Tunnel",
                            "tunnl" => "Tunnel",
                            "turnpike" => "Turnpike",
                            "turnpk" => "Turnpike",
                            "un" => "Union",
                            "underpass" => "Underpass",
                            "union" => "Union",
                            "unions" => "Unions",
                            "valley" => "Valley",
                            "valleys" => "Valleys",
                            "vally" => "Valley",
                            "vdct" => "Viaduct",
                            "via" => "Viaduct",
                            "viadct" => "Viaduct",
                            "viaduct" => "Viaduct",
                            "view" => "View",
                            "views" => "Views",
                            "vill" => "Village",
                            "villag" => "Village",
                            "village" => "Village",
                            "villages" => "Villages",
                            "ville" => "Ville",
                            "villg" => "Village",
                            "villiage" => "Village",
                            "vis" => "Vista",
                            "vist" => "Vista",
                            "vista" => "Vista",
                            "vl" => "Ville",
                            "vlg" => "Village",
                            "vlgs" => "Villages",
                            "vlly" => "Valley",
                            "vly" => "Valley",
                            "vlys" => "Valleys",
                            "vst" => "Vista",
                            "vsta" => "Vista",
                            "vw" => "View",
                            "vws" => "Views",
                            "walk" => "Walk",
                            "walks" => "Walks",
                            "wall" => "Wall",
                            "way" => "Way",
                            "ways" => "Ways",
                            "well" => "Well",
                            "wells" => "Wells",
                            "wls" => "Wells",
                            "wy" => "Way",
                            "xing" => "Crossing",
                            "xrd" => "Crossroad"
                            );

    if(array_key_exists($street_name_suffix, $street_suffixes)) {
        $expanded_suffix = $street_suffixes[$street_name_suffix];
    } else {
        $expanded_suffix = $street_name_suffix;
    }

    return $expanded_suffix;
}
