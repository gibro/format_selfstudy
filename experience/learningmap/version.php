<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'selfstudyexperience_learningmap';
$plugin->version = 2026051500;
$plugin->requires = 2022041900;
$plugin->maturity = MATURITY_ALPHA;
$plugin->release = '0.1.0-alpha';
$plugin->dependencies = [
    'format_selfstudy' => 2026051500,
];
