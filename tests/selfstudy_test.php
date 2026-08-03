<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/format/selfstudy/lib.php');

/**
 * Tests for the selfstudy course format.
 *
 * @package    format_selfstudy
 * @category   test
 */
class format_selfstudy_test extends advanced_testcase {

    public function test_format_is_built_on_the_shared_base(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'selfstudy']);
        $format = course_get_format($course);

        $this->assertInstanceOf(\format_selfstudy::class, $format);
        $this->assertInstanceOf(\local_selfstudy\courseformat\base::class, $format);
        $this->assertTrue($format->uses_sections());
    }

    public function test_course_format_options_are_the_shared_ones(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'selfstudy']);
        $options = course_get_format($course)->course_format_options();

        // The format adds nothing of its own, so exactly the shared set must arrive.
        foreach (['enableavatar', 'enableactivitynavigation', 'shownavprevious', 'shownavmap', 'shownavnext',
                'showactivitystatus', 'useactivitymetadata', 'showlockedactivities', 'allowpersonalpaths',
                'pathpointcolor', 'nextbuttoncolor', 'previousbuttonlabel', 'mapbuttonlabel', 'nextbuttonlabel',
                'customnavbuttonlabel', 'customnavbuttonurl'] as $name) {
            $this->assertArrayHasKey($name, $options, 'Missing shared course format option ' . $name);
        }
        $this->assertSame('#6f1ab1', $options['pathpointcolor']['default']);
        $this->assertSame(1, $options['enableavatar']['default']);
        $this->assertSame(0, $options['allowpersonalpaths']['default']);

        // The edit form hides the options that are not meant to be configured there.
        $editoptions = course_get_format($course)->course_format_options(true);
        $this->assertArrayNotHasKey('enableavatar', $editoptions);
        $this->assertArrayNotHasKey('allowpersonalpaths', $editoptions);
        $this->assertSame('advcheckbox', $editoptions['showlockedactivities']['element_type']);
        $this->assertSame('text', $editoptions['pathpointcolor']['element_type']);
    }

    public function test_section_options_are_stored_but_no_longer_edited_in_the_section_form(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'selfstudy']);
        $format = course_get_format($course);

        // Both values keep their place in course_format_options.
        $options = $format->section_format_options();
        $this->assertArrayHasKey('learninggoal', $options);
        $this->assertSame('', $options['learninggoal']['default']);
        $this->assertArrayHasKey('pathkind', $options);

        // The edit form set has to keep them: core validates incoming data
        // against it, so an empty set would make them unsavable.
        $this->assertArrayHasKey('learninggoal', $format->section_format_options(true));

        // They are edited in the "set up the steps" panel, so create_edit_form_elements()
        // takes the fields core built back out - they carried no label and no
        // one could tell what belonged in them.
        $mform = new MoodleQuickForm('test', 'post', '');
        $format->create_edit_form_elements($mform, true);
        $this->assertFalse($mform->elementExists('learninggoal'));
        $this->assertFalse($mform->elementExists('pathkind'));
        $this->assertTrue($mform->elementExists('selfstudysettingsmoved'),
            'Instead the form says where the fields went.');
    }

    public function test_option_names_never_collide_with_table_columns(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'selfstudy']);
        $format = course_get_format($course);

        // A format option that is named like a {course} column silently overwrites
        // that column - this happened once with an option called "theme", which
        // replaced the Moodle course theme.
        $collisions = array_intersect(array_keys($format->course_format_options()),
            array_keys($DB->get_columns('course')));
        $this->assertSame([], array_values($collisions),
            'Course format options must not shadow columns of the course table.');

        $sectioncollisions = array_intersect(array_keys($format->section_format_options()),
            array_keys($DB->get_columns('course_sections')));
        $this->assertSame([], array_values($sectioncollisions),
            'Section format options must not shadow columns of the course_sections table.');
    }

    public function test_validate_course_format_options_normalises_the_colours(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'selfstudy']);
        $format = course_get_format($course);

        $validated = $format->validate_course_format_options([
            'pathpointcolor' => '#AABBCC',
            'nextbuttoncolor' => '#001122',
        ]);
        $this->assertSame('#aabbcc', $validated['pathpointcolor'], 'Upper case hex is stored lower case.');
        $this->assertSame('#001122', $validated['nextbuttoncolor']);

        // Garbage falls back: the point colour to the plugin default, the button
        // colour to the (already normalised) point colour.
        $fallback = $format->validate_course_format_options([
            'pathpointcolor' => 'rebeccapurple',
            'nextbuttoncolor' => '12345',
        ]);
        $this->assertSame('#6f1ab1', $fallback['pathpointcolor']);
        $this->assertSame('#6f1ab1', $fallback['nextbuttoncolor']);

        $inherited = $format->validate_course_format_options([
            'pathpointcolor' => '#123456',
            'nextbuttoncolor' => 'not a colour',
        ]);
        $this->assertSame('#123456', $inherited['nextbuttoncolor'],
            'An invalid button colour falls back to the configured path point colour.');
    }

    public function test_course_contacts_are_saved_and_read_back(): void {
        global $DB;

        $this->resetAfterTest();
        $this->assertTrue(format_selfstudy_contacts_table_exists());

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy']);
        $author = $generator->create_user(['lastname' => 'Autorin']);
        $support = $generator->create_user(['lastname' => 'Support']);
        $stranger = $generator->create_user(['lastname' => 'Fremd']);
        $generator->enrol_user($author->id, $course->id, 'editingteacher');
        $generator->enrol_user($support->id, $course->id, 'teacher');

        $this->assertSame([], format_selfstudy_get_course_contacts((int)$course->id));

        course_get_format($course)->update_course_format_options((object)[
            'selfstudycontactauthors' => [$author->id],
            'selfstudycontactsupport' => [$author->id, $support->id, $stranger->id],
        ]);

        $contacts = format_selfstudy_get_course_contacts((int)$course->id);
        $this->assertCount(2, $contacts, 'The user without an enrolment is dropped.');
        $this->assertSame((int)$author->id, (int)$contacts[0]->userid);
        $this->assertSame('author,support', $contacts[0]->roles,
            'A user in both selectors keeps both roles on one record.');
        $this->assertSame(0, (int)$contacts[0]->sortorder);
        $this->assertSame((int)$support->id, (int)$contacts[1]->userid);
        $this->assertSame('support', $contacts[1]->roles);
        $this->assertSame('Support', $contacts[1]->lastname, 'The user record is joined in.');

        // Saving again replaces the old set instead of adding to it.
        course_get_format($course)->update_course_format_options((object)[
            'selfstudycontactauthors' => [$support->id],
            'selfstudycontactsupport' => [],
        ]);
        $contacts = format_selfstudy_get_course_contacts((int)$course->id);
        $this->assertCount(1, $contacts);
        $this->assertSame((int)$support->id, (int)$contacts[0]->userid);
        $this->assertSame('author', $contacts[0]->roles);
        $this->assertSame(1, $DB->count_records('format_selfstudy_contacts', ['courseid' => $course->id]));

        // Deleted users disappear from the hero even though the record stays.
        delete_user($DB->get_record('user', ['id' => $support->id]));
        $this->assertSame([], format_selfstudy_get_course_contacts((int)$course->id));
        $this->assertSame(1, $DB->count_records('format_selfstudy_contacts', ['courseid' => $course->id]));
    }

    public function test_a_course_update_without_the_form_fields_keeps_the_contacts(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy']);
        $author = $generator->create_user();
        $generator->enrol_user($author->id, $course->id, 'editingteacher');
        course_get_format($course)->update_course_format_options((object)[
            'selfstudycontactauthors' => [$author->id],
            'selfstudycontactsupport' => [],
        ]);

        // update_course() reaches the format with a plain course record. Core
        // does this during a course restore, and it must not clear the contacts.
        update_course((object)['id' => $course->id, 'fullname' => 'Neuer Name']);

        $this->assertSame(1, $DB->count_records('format_selfstudy_contacts', ['courseid' => $course->id]));
    }

    public function test_course_contact_role_labels_are_translated(): void {
        $this->resetAfterTest();
        $labels = format_selfstudy_get_course_contact_role_labels();

        $this->assertSame(['author', 'support'], array_keys($labels));
        foreach ($labels as $key => $label) {
            $this->assertNotSame('', trim($label));
            $this->assertStringNotContainsString('[[', $label, 'Missing language string for role ' . $key);
        }
    }

    public function test_pluginfile_serves_the_hero_area_only(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['format' => 'selfstudy']);
        $context = context_course::instance($course->id);
        $fs = get_file_storage();

        $this->store_file($fs, $context->id, 'format_selfstudy', 'heroimage', 0, 'hero.png');
        $this->store_file($fs, $context->id, 'local_selfstudy', 'sectionimage', 42, 'section.png');
        $this->store_file($fs, $context->id, 'local_selfstudy', 'pathimage', 7, 'path.png');

        // The library owns section and path images, so this format refuses them
        // even though the files really exist.
        $this->assertFalse(format_selfstudy_pluginfile($course, null, $context, 'sectionimage',
            ['42', 'section.png'], false));
        $this->assertFalse(format_selfstudy_pluginfile($course, null, $context, 'pathimage',
            ['7', 'path.png'], false));

        // A wrong context level is refused as well.
        $this->assertFalse(format_selfstudy_pluginfile($course, null, context_system::instance(), 'heroimage',
            ['0', 'hero.png'], false));

        // The hero area is handled, but a missing file still returns false - which
        // proves the guard let this call through to the file lookup.
        $this->assertFalse(format_selfstudy_pluginfile($course, null, $context, 'heroimage',
            ['0', 'does-not-exist.png'], false));
        $this->assertFalse(format_selfstudy_pluginfile($course, null, $context, 'heroimage', ['0', ''], false));
    }

    public function test_hero_image_url_follows_the_stored_file(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'selfstudy']);
        $this->assertNull(format_selfstudy_get_hero_image_url($course));

        $context = context_course::instance($course->id);
        $this->store_file(get_file_storage(), $context->id, 'format_selfstudy', 'heroimage', 0, 'hero.png');

        $url = format_selfstudy_get_hero_image_url($course);
        $this->assertInstanceOf(moodle_url::class, $url);
        $this->assertStringContainsString('format_selfstudy/heroimage', $url->out(false));
        $this->assertStringContainsString('hero.png', $url->out(false));
    }

    /**
     * Stores a small file in the given file area.
     *
     * @param \file_storage $fs
     * @param int $contextid
     * @param string $component
     * @param string $filearea
     * @param int $itemid
     * @param string $filename
     */
    private function store_file(\file_storage $fs, int $contextid, string $component, string $filearea,
            int $itemid, string $filename): void {
        $fs->create_file_from_string([
            'contextid' => $contextid,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
        ], 'test image content');
    }
}
