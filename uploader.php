<?php

require 'vendor/autoload.php';
require 'KomootUploader.php';

$user = \getenv('KOMOOT_USER');
$pass = \getenv('KOMOOT_PASSWORD');

$uploader = new KomootUploader($user, $pass);

$gpxContent = \file_get_contents('/home/stefan/Downloads/2021-04-30 - 80.3 km.gpx');
$tourId = $uploader->uploadPlannedTour($gpxContent, 'mtb_easy', 'Test 1.2');

printf("Tour upload successful.  Tour ID is %d.\n", $tourId);
