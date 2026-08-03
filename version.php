<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'format_selfstudy';
$plugin->version = 2026080300;
$plugin->requires = 2023100900;
$plugin->maturity = MATURITY_ALPHA;
$plugin->release = '0.3.0-alpha';

// The learning path core lives in the shared library, this format only renders it.
$plugin->dependencies = [
    'local_selfstudy' => 2026073100,
];
