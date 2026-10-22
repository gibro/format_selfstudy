<?php
// This file is part of Moodle - http://moodle.org/

use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use format_selfstudy\privacy\provider;

/**
 * Tests for the privacy provider of the selfstudy format.
 *
 * @package    format_selfstudy
 * @category   test
 * @covers     \format_selfstudy\privacy\provider
 */
class format_selfstudy_privacy_provider_test extends \core_privacy\tests\provider_testcase {

    public function test_contact_persons_are_found_and_deleted_without_touching_others(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'selfstudy']);
        $context = \context_course::instance($course->id);
        $leaving = $this->getDataGenerator()->create_user();
        $staying = $this->getDataGenerator()->create_user();
        foreach ([$leaving, $staying] as $index => $user) {
            $DB->insert_record('format_selfstudy_contacts', (object)[
                'courseid' => $course->id, 'userid' => $user->id, 'roles' => 'Ansprechperson',
                'sortorder' => $index, 'timecreated' => 1, 'timemodified' => 1,
            ]);
        }

        $userlist = new userlist($context, 'format_selfstudy');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([(int)$leaving->id, (int)$staying->id],
            array_map('intval', $userlist->get_userids()));

        provider::delete_data_for_users(new approved_userlist($context, 'format_selfstudy', [(int)$leaving->id]));

        $this->assertFalse($DB->record_exists('format_selfstudy_contacts', ['userid' => $leaving->id]));
        $this->assertTrue($DB->record_exists('format_selfstudy_contacts', ['userid' => $staying->id]));
    }
}
