<?php
// This file is part of Moodle - http://moodle.org/

namespace selfstudyexperience_nuggets;

defined('MOODLE_INTERNAL') || die();

/**
 * Produces the inline (embedded) content of a nugget.
 *
 * The scroll feed shows suitable activities directly on the course page so
 * learners rarely have to switch to subpages: text pages render as full
 * content, H5P activities embed the core H5P player (with attempt tracking
 * via the mod_h5pactivity component). Everything here is display only —
 * completion and unlocking stay with Moodle core.
 */
class inline_content {

    /**
     * Returns which embed type a course module supports, or null.
     *
     * Multi-page activities (lesson, quiz, assign, ...) intentionally return
     * null and keep their card with a launch button.
     *
     * @param \cm_info $cm
     * @return string|null page|h5p
     */
    public static function embed_type(\cm_info $cm): ?string {
        if (!$cm->uservisible) {
            return null;
        }
        if ($cm->modname === 'page') {
            return 'page';
        }
        if ($cm->modname === 'h5pactivity') {
            return 'h5p';
        }
        return null;
    }

    /**
     * Builds the embed HTML for a course module.
     *
     * @param \cm_info $cm
     * @return string empty when the content cannot be embedded
     */
    public static function build(\cm_info $cm): string {
        try {
            switch (self::embed_type($cm)) {
                case 'page':
                    return self::build_page($cm);
                case 'h5p':
                    return self::build_h5p($cm);
            }
        } catch (\Throwable $exception) {
            debugging('Nugget inline content failed for cmid ' . $cm->id . ': ' .
                $exception->getMessage(), DEBUG_DEVELOPER);
        }
        return '';
    }

    /**
     * Renders a mod_page's content inline, mirroring mod/page/view.php.
     *
     * @param \cm_info $cm
     * @return string
     */
    private static function build_page(\cm_info $cm): string {
        global $DB, $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $page = $DB->get_record('page', ['id' => (int)$cm->instance]);
        if (!$page) {
            return '';
        }

        $context = \context_module::instance($cm->id);
        $content = file_rewrite_pluginfile_urls($page->content, 'pluginfile.php', $context->id,
            'mod_page', 'content', $page->revision);
        $content = format_text($content, $page->contentformat, [
            'context' => $context,
            'noclean' => true,
            'overflowdiv' => true,
        ]);

        if (trim($content) === '') {
            return '';
        }

        return \html_writer::div($content, 'format-selfstudy-nuggets-embed format-selfstudy-nuggets-embed-page');
    }

    /**
     * Embeds the core H5P player for a mod_h5pactivity, mirroring its view.php.
     *
     * The component stays 'mod_h5pactivity', so attempts and grades are
     * tracked exactly as if the learner had opened the activity page.
     *
     * @param \cm_info $cm
     * @return string
     */
    private static function build_h5p(\cm_info $cm): string {
        global $DB, $CFG;

        if (!class_exists('\\core_h5p\\player')) {
            return '';
        }

        $instance = $DB->get_record('h5pactivity', ['id' => (int)$cm->instance]);
        if (!$instance) {
            return '';
        }

        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_h5pactivity', 'package', 0, 'id', false);
        $file = reset($files);
        if (!$file) {
            return '';
        }

        $fileurl = \moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(),
            $file->get_filearea(), $file->get_itemid(), $file->get_filepath(),
            $file->get_filename(), false);

        $factory = new \core_h5p\factory();
        $config = \core_h5p\helper::decode_display_options($factory->get_core(),
            (int)$instance->displayoptions);

        $html = \core_h5p\player::display($fileurl, $config, true, 'mod_h5pactivity', false);
        if (trim($html) === '') {
            return '';
        }

        // Load the player only when the learner scrolls near this nugget.
        $html = str_replace('<iframe ', '<iframe loading="lazy" ', $html);

        return \html_writer::div($html, 'format-selfstudy-nuggets-embed format-selfstudy-nuggets-embed-h5p');
    }
}
