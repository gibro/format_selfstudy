<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/format/selfstudy/lib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Tests for the course backup and restore of the selfstudy format.
 *
 * @package    format_selfstudy
 * @category   test
 */
class format_selfstudy_backup_restore_test extends advanced_testcase {

    public function test_contacts_and_hero_image_survive_a_course_restore(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->create_course_with_contacts($author, $support);
        $this->store_hero_image($source, 'hero.png');

        $target = $this->restore_into_new_course($this->backup_course($source));

        $contacts = $DB->get_records('format_selfstudy_contacts', ['courseid' => $target->id],
            'sortorder ASC, id ASC');
        $contacts = array_values($contacts);
        $this->assertCount(2, $contacts);
        $this->assertSame((int)$author->id, (int)$contacts[0]->userid);
        $this->assertSame('author,support', $contacts[0]->roles);
        $this->assertSame(0, (int)$contacts[0]->sortorder);
        $this->assertSame((int)$support->id, (int)$contacts[1]->userid);
        $this->assertSame('support', $contacts[1]->roles);
        $this->assertSame(1, (int)$contacts[1]->sortorder);

        // The source course keeps what it had.
        $this->assertSame(2, $DB->count_records('format_selfstudy_contacts', ['courseid' => $source->id]));

        $heroimage = format_selfstudy_get_hero_image_url($target);
        $this->assertNotNull($heroimage, 'The hero image must arrive in the restored course.');
        $this->assertStringContainsString('hero.png', $heroimage->out(false));
    }

    public function test_restore_into_an_existing_course_adds_no_duplicates(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->create_course_with_contacts($author, $support);
        $this->store_hero_image($source, 'hero.png');
        $backupid = $this->backup_course($source);

        // The target already lists the author - with a different role - and has
        // a hero image of its own.
        $target = $this->getDataGenerator()->create_course(['format' => 'selfstudy']);
        $this->getDataGenerator()->enrol_user($author->id, $target->id, 'editingteacher');
        course_get_format($target)->update_course_format_options((object)[
            'selfstudycontactauthors' => [],
            'selfstudycontactsupport' => [$author->id],
        ]);
        $this->store_hero_image($target, 'own-hero.png');

        // Core reads course.xml into an existing course only when the restore is
        // told to overwrite the course configuration - the hero data of this
        // format is course configuration and follows that rule.
        $this->restore_into_existing_course($backupid, $target, false);
        $this->assertSame(1, $DB->count_records('format_selfstudy_contacts', ['courseid' => $target->id]));

        $this->restore_into_existing_course($backupid, $target, true);

        $contacts = array_values($DB->get_records('format_selfstudy_contacts', ['courseid' => $target->id],
            'sortorder ASC, id ASC'));
        $this->assertCount(2, $contacts, 'The author must not arrive a second time.');
        $this->assertSame((int)$author->id, (int)$contacts[0]->userid);
        $this->assertSame('support', $contacts[0]->roles, 'The entry of the target course wins.');
        $this->assertSame((int)$support->id, (int)$contacts[1]->userid);
        $this->assertGreaterThan((int)$contacts[0]->sortorder, (int)$contacts[1]->sortorder,
            'Restored contacts line up behind the ones already there.');

        $heroimage = format_selfstudy_get_hero_image_url($target);
        $this->assertStringContainsString('own-hero.png', $heroimage->out(false),
            'A hero image already in place is not replaced.');
    }

    public function test_a_backup_without_users_carries_no_contacts(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $source = $this->create_course_with_contacts($author, $support);
        $this->store_hero_image($source, 'hero.png');

        $target = $this->restore_into_new_course($this->backup_course($source, false), false);

        // Without users there is nothing to map the contacts to, so they are
        // dropped rather than pointed at some other person.
        $this->assertSame(0, $DB->count_records('format_selfstudy_contacts', ['courseid' => $target->id]));

        // The hero image is course content and travels either way.
        $this->assertNotNull(format_selfstudy_get_hero_image_url($target));
    }

    /**
     * Creates a selfstudy course with two enrolled hero contacts.
     *
     * @param stdClass|null $author Receives the user that is author and support.
     * @param stdClass|null $support Receives the user that is support only.
     * @return stdClass The course.
     */
    private function create_course_with_contacts(&$author, &$support): stdClass {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'selfstudy']);
        $author = $generator->create_user(['lastname' => 'Autorin']);
        $support = $generator->create_user(['lastname' => 'Support']);
        $generator->enrol_user($author->id, $course->id, 'editingteacher');
        $generator->enrol_user($support->id, $course->id, 'teacher');

        course_get_format($course)->update_course_format_options((object)[
            'selfstudycontactauthors' => [$author->id],
            'selfstudycontactsupport' => [$author->id, $support->id],
        ]);

        return $course;
    }

    /**
     * Stores a hero image in the course.
     *
     * @param stdClass $course
     * @param string $filename
     */
    private function store_hero_image(stdClass $course, string $filename): void {
        get_file_storage()->create_file_from_string([
            'contextid' => context_course::instance($course->id)->id,
            'component' => 'format_selfstudy',
            'filearea' => 'heroimage',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], 'test image content');
    }

    /**
     * Backs up the whole course.
     *
     * @param stdClass $course
     * @param bool $withusers Whether user data is included.
     * @return string The backup id.
     */
    private function backup_course(stdClass $course, bool $withusers = true): string {
        global $CFG, $USER;

        // The restore reads the unpacked backup directory. Keeping it spares the
        // test from unpacking the archive again - and a backup without users is
        // not even filed with the course, it goes to the private area of its author.
        $CFG->keeptempdirectoriesonbackup = true;

        $bc = new backup_controller(backup::TYPE_1COURSE, $course->id, backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO, backup::MODE_GENERAL, $USER->id);
        $bc->get_plan()->get_setting('users')->set_value($withusers);
        $bc->execute_plan();
        $backupid = $bc->get_backupid();
        $bc->destroy();

        return $backupid;
    }

    /**
     * Restores a backup into a fresh course.
     *
     * @param string $backupid
     * @param bool $withusers Whether user data is restored.
     * @return stdClass The restored course.
     */
    private function restore_into_new_course(string $backupid, bool $withusers = true): stdClass {
        global $DB;

        $target = $this->getDataGenerator()->create_course();
        $this->run_restore($backupid, $target, backup::TARGET_NEW_COURSE, $withusers);

        return $DB->get_record('course', ['id' => $target->id], '*', MUST_EXIST);
    }

    /**
     * Restores a backup into a course that already holds content.
     *
     * @param string $backupid
     * @param stdClass $target
     * @param bool $overwriteconf Whether the course configuration is overwritten.
     */
    private function restore_into_existing_course(string $backupid, stdClass $target, bool $overwriteconf): void {
        $this->run_restore($backupid, $target, backup::TARGET_EXISTING_ADDING, true, $overwriteconf);
    }

    /**
     * Runs one restore.
     *
     * @param string $backupid
     * @param stdClass $target
     * @param int $targetmode
     * @param bool $withusers
     * @param bool|null $overwriteconf Null leaves the setting untouched.
     */
    private function run_restore(string $backupid, stdClass $target, int $targetmode, bool $withusers,
            ?bool $overwriteconf = null): void {
        global $USER;

        $rc = new restore_controller($backupid, $target->id, backup::INTERACTIVE_NO, backup::MODE_GENERAL,
            $USER->id, $targetmode);
        $rc->get_plan()->get_setting('users')->set_value($withusers);
        if ($overwriteconf !== null) {
            $rc->get_plan()->get_setting('overwrite_conf')->set_value($overwriteconf);
        }
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
    }
}
