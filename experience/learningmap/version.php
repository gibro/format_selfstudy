<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'selfstudyexperience_learningmap';
$plugin->version = 2026072300;
$plugin->requires = 2022041900;
$plugin->maturity = MATURITY_ALPHA;
$plugin->release = '0.2.0-alpha';
$plugin->dependencies = [
    'format_selfstudy' => 2026072300,
];
