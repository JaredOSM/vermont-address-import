#!/usr/bin/env php
<?php

$help = "

Conflate all town files in data_files_to_import/draft/ and write to data_files_to_import/conflated/

". $argv[0] . " [-hv] [--help] [--verbose]

  -h --help           Show this help
  -v --verbose        Print status output.

";

#options
$options = getopt("hv", ["help", "verbose", "output-type::"], $reset_index);
if ($options === FALSE || isset($options["h"]) || isset($options["help"])) {
  print $help;
  exit(1);
}
$verbose = false;
if (isset($options['v']) || isset($options['verbose'])) {
  $verbose = true;
}

$input_files = scandir(__DIR__ . '/data_files_to_import/draft');
foreach ($input_files as $input_file) {
  if (preg_match('/(.+)_addresses\.osm$/i', $input_file, $file_matches)) {
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
