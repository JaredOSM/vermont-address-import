#!/usr/bin/env php
<?php

$help = "

Conflate all town files in data_files_to_import/draft/ and write to data_files_to_import/conflated/

". $argv[0] . " [-hv] [--help] [--verbose]

  -h --help           Show this help
  -v --verbose        Print status output.

  --name-range        A range of name prefixes to conflate. Examples:

                      --name-range=-berkshire
                         Conflate addison and everything up to and including
                         berkshire.

                      --name-range=berlin-bolton
                         Conflate berlin, bethel, bloomfield, and bolton.

                      --name-range=norwich-
                        Conflate norwich and everything after.


";

#options
$options = getopt("hv", ["help", "verbose", "name-range::"], $reset_index);
if ($options === FALSE || isset($options["h"]) || isset($options["help"])) {
  print $help;
  exit(1);
}
$verbose = false;
if (isset($options['v']) || isset($options['verbose'])) {
  $verbose = true;
}

if (isset($options['name-range'])) {
  if (!preg_match('/^\w*-?\w*$/i', $options['name-range'])) {
    print "Invalid name range.\n\n";
    print $help;
    exit(1);
  }
} else {
  $options['name-range'] = NULL;
}

$input_files = scandir(__DIR__ . '/data_files_to_import/draft');
foreach ($input_files as $input_file) {
  if (preg_match('/(.+)_addresses\.osm$/i', $input_file, $file_matches)
    && should_include_file($input_file, $options['name-range'])
  ) {
    $input_path = __DIR__ . '/data_files_to_import/draft/' . $input_file;
    if ($verbose) {
      fwrite(STDERR, "\n---------------------------\nConflating $input_file\n");
    }
    $command = __DIR__ . '/conflate_town.php ';
    if ($verbose) {
      $command .= '-v ';
    }
    $command .= $input_path;

    $descriptorspec = [STDIN, STDOUT, STDOUT];
    $process = proc_open($command, $descriptorspec, $pipes);
    proc_close($process);
  }
}

function should_include_file($filename, $nameRange) {
  if (empty($nameRange)) {
    return TRUE;
  }

  // If we have a real range, get our start and end.
  if (preg_match('/^\w*-\w*$/i', $nameRange)) {
    $range = explode('-', $nameRange);
    $start = $range[0];
    $end = $range[1];
  }
  // If we have just a single name use it for start and end.
  else {
    $start = $nameRange;
    $end = $nameRange;
  }

  // Check for name before start.
  if (!empty($start) && strcasecmp(substr($filename, 0, strlen($start)), $start) < 0) {
    return FALSE;
  }
  // Check for name after end.
  if (!empty($end) && strcasecmp(substr($filename, 0, strlen($end)), $end) > 0) {
    return FALSE;
  }

  // Must be in range.
  return TRUE;
}
