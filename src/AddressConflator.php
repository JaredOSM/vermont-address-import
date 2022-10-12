<?php

class AddressConflator {

  public $nonMatchesDoc;
  public $conflictsDoc;
  public $matchesDoc;
  public $reviewsDoc;

  protected $db;
  protected $verbose;

  public function __construct(SQLite3 $db, $verbose = false) {
    $this->db = $db;
    $this->verbose = $verbose;

    // Output targets
    $osmXmlWrapper = <<<END
<?xml version='1.0' encoding='UTF-8'?>
<osm version='0.6' generator='JOSM'>
</osm>
END;
    $this->nonMatchesDoc = new DOMDocument();
    $this->nonMatchesDoc->loadXML($osmXmlWrapper);
    $this->matchesDoc = new DOMDocument();
    $this->matchesDoc->loadXML($osmXmlWrapper);
    $this->conflictsDoc = new DOMDocument();
    $this->conflictsDoc->loadXML($osmXmlWrapper);
    $this->reviewsDoc = new DOMDocument();
    $this->reviewsDoc->loadXML($osmXmlWrapper);
  }

  public function conflate(DOMDocument $inputDoc) {
    foreach ($inputDoc->documentElement->childNodes as $inputNode) {
      if ($inputNode->nodeName == 'node') {
        $targetDoc = $this->conflateNode($inputNode);
        $this->append($targetDoc, $inputNode);
      }
    }
  }

  /**
   * Conflate a node with entries in the OSM database and return a target bucket.
   *
   * @param DOMElement $inputNode
   *   The node to compare.
   * @return DOMDocument
   *   The target bucket the node should be placed in.
   */
  protected function conflateNode(DOMElement $inputNode) {
    $address = $this->extractAddress($inputNode);

    // Check for an exact address match.
    $lon = $inputNode->getAttribute('lon');
    $lat = $inputNode->getAttribute('lat');
    $exactMatchStmt = $this->db->prepare("SELECT osm_type, id, st_distance(geom,st_point($lon,$lat),0) AS distance FROM addresses WHERE housenumber=:housenumber AND street=:street AND city=:city AND state=:state AND postcode=:postcode");
    $exactMatchStmt->bindValue('housenumber', $address['addr:housenumber']);
    $exactMatchStmt->bindValue('street', $address['addr:street']);
    $exactMatchStmt->bindValue('city', $address['addr:city']);
    $exactMatchStmt->bindValue('state', $address['addr:state']);
    $exactMatchStmt->bindValue('postcode', $address['addr:postcode']);
    $res = $exactMatchStmt->execute();
    $match = $res->fetchArray(SQLITE3_ASSOC);
    if ($match) {
      // Verify that the distance is reasonable, e.g. less than 100m
      if ($match['distance'] > 100) {
        $res->finalize();
        $this->log('offset', $inputNode, "Significant offset - " . $match['distance'] . "m");
        return $this->reviewsDoc;
      }
      // Check for an additional match.
      if ($res->fetchArray(SQLITE3_ASSOC)) {
        // We have multiple targets?
        $res->finalize();
        $this->log('multiple', $inputNode, "Multiple exact matches in OSM.");
        return $this->reviewsDoc;
      }
      // We only have one match and it is close by.
      $res->finalize();
      $this->log('match', $inputNode, "Exact match");
      return $this->matchesDoc;
    }

    // No exact match found
    // Check for nearby variants on housenumber, street, and postcode.
    // print "\n========================\n";
    // var_dump($this->simplifyHouseNumber($address['addr:housenumber']), $this->simplifyStreet($address['addr:street']));
    // print "----------\n";
    $lon = $inputNode->getAttribute('lon');
    $lat = $inputNode->getAttribute('lat');
    $closeAddressStmt = $this->db->prepare("SELECT *, st_distance(geom,st_point($lon,$lat),0) AS distance FROM addresses WHERE distance < 1000");
    $res = $closeAddressStmt->execute();
    while ($nearby = $res->fetchArray(SQLITE3_ASSOC)) {
      // We didn't get a precise match on all fields previously, so if these
      // are a fuzzy match, we have a conflict.
      // var_dump($this->simplifyHouseNumber($nearby['housenumber']), $this->simplifyStreet($nearby['street']));
      if ($this->simplifyHouseNumber($address['addr:housenumber']) == $this->simplifyHouseNumber($nearby['housenumber'])
        && $this->simplifyStreet($address['addr:street']) == $this->simplifyStreet($nearby['street'])
      ) {
        $res->finalize();
        $this->log('conflict', $inputNode, "Fuzzy match to \"" . $nearby['housenumber'] . " " . $nearby['street'] . ", " . $nearby['city'] . ", " . $nearby['state'] . " " . $nearby['postcode'] . '"');
        return $this->conflictsDoc;
      }
    }
    $res->finalize();

    // Check for VERY close addresses of any mixture.
    $lon = $inputNode->getAttribute('lon');
    $lat = $inputNode->getAttribute('lat');
    $closeAddressStmt = $this->db->prepare("SELECT *, st_distance(geom,st_point($lon,$lat),0) AS distance FROM addresses WHERE distance < 5");
    $res = $closeAddressStmt->execute();
    while ($nearby = $res->fetchArray(SQLITE3_ASSOC)) {
      $res->finalize();
      return $this->conflictsDoc;
    }
    $res->finalize();

    // If we haven't found an exact match, a nearby variant spelling, or a very
    // close point. Let's call this a no-match.
    $this->log('no matches', $inputNode, "Not found in OSM");
    return $this->nonMatchesDoc;
  }

  protected function log($category, DOMElement $inputNode, $message) {
    if ($this->verbose) {
      $address = $this->extractAddress($inputNode);
      $entry = $category . ": \"" .  $address['addr:housenumber'] . ' ' . $address['addr:street'] . ', ' . $address['addr:city'] . ', ' . $address['addr:state'] . ' ' . $address['addr:postcode'] . '" ' . $message . "\n";
      print $entry;
      // fwrite(STDERR, $entry);
    }
  }

  protected function extractAddress(DOMElement $inputNode) {
    $result = [];
    foreach ($inputNode->childNodes as $child) {
      if ($child->nodeName == 'tag' && preg_match('/^addr:.+/', $child->getAttribute('k'))) {
        $result[$child->getAttribute('k')] = $child->getAttribute('v');
      }
    }
    return $result;
  }

  protected function append(DOMDocument $targetDoc, DOMElement $inputNode) {
    $targetDoc->documentElement->appendChild($targetDoc->createTextNode("  "));
    $targetDoc->documentElement->appendChild($targetDoc->importNode($inputNode, true));
    $targetDoc->documentElement->appendChild($targetDoc->createTextNode("\n"));
  }

  /**
   * Answer a simplified string for fuzzy matching.
   *
   */
  protected function simplifyHouseNumber($housenumber) {
    return strtolower(preg_replace('/[^a-z0-9]/i', '', $housenumber));
  }

  /**
   * Answer a simplified string for fuzzy matching.
   *
   */
  protected function simplifyStreet($street) {
    // Trim off the Street/St/Drive/etc suffix.
    $suffixes = array_merge(array_keys($this->street_suffixes), array_values($this->street_suffixes));
    $street = preg_replace('/ ('.implode('|', $suffixes).')$/i', '', $street);
    // Trim a second time in case there are two extensions.
    $street = preg_replace('/ ('.implode('|', $suffixes).')$/i', '', $street);

    // Trim any directional prefix when looking for conflicts.
    $street = preg_replace('/^(North|South|East|West) /i', '', $street);

    // Lowercase and strip non-alpha-numeric.
    return strtolower(preg_replace('/[^a-z0-9]/i', '', $street));
  }

  // list from https://github.com/blackboxlogic/OsmTagsTranslator/blob/master/OsmTagsTranslator/Lookups/StreetSuffixes.json
  public $street_suffixes = array("allee" => "Alley",
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

}
