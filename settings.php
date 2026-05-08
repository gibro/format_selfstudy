<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configtext(
        'format_selfstudy/defaultmainmapname',
        get_string('defaultmainmapname', 'format_selfstudy'),
        get_string('defaultmainmapname_desc', 'format_selfstudy'),
        '',
        PARAM_TEXT
    ));
}
