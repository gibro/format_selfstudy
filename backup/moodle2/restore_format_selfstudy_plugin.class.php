<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Restore support for the selfstudy course format.
 *
 * @package   format_selfstudy
 * @category  backup
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Restores selfstudy path data and remaps course-local ids.
 */
class restore_format_selfstudy_plugin extends restore_format_plugin {

    /**
     * Defines restore paths for selfstudy plugin data.
     *
     * @return restore_path_element[]
     */
    public function define_course_plugin_structure() {
        return [
            new restore_path_element('format_selfstudy_path', $this->get_pathfor('/paths/path')),
            new restore_path_element('format_selfstudy_item', $this->get_pathfor('/paths/path/items/item')),
            new restore_path_element('format_selfstudy_snapshot', $this->get_pathfor('/paths/path/snapshots/snapshot')),
            new restore_path_element('format_selfstudy_revision', $this->get_pathfor('/paths/path/revisions/revision')),
            new restore_path_element('format_selfstudy_choice', $this->get_pathfor('/choices/choice')),
            new restore_path_element('format_selfstudy_milestone', $this->get_pathfor('/milestones/milestone')),
            new restore_path_element('format_selfstudy_cmgoal', $this->get_pathfor('/cmgoals/cmgoal')),
        ];
    }

    /**
     * Restores one learning path.
     *
     * @param array $data
     */
    public function process_format_selfstudy_path(array $data): void {
        global $DB;

        $oldid = (int)$data['id'];
        $data = (object)$data;
        $olduserid = (int)($data->userid ?? 0);
        $data->courseid = $this->step->get_task()->get_courseid();
        $data->userid = empty($olduserid) ? 0 : $this->get_mappingid('user', $olduserid, 0);

        if (empty($data->name) || ($olduserid && empty($data->userid))) {
            return;
        }

        $newid = $DB->insert_record('format_selfstudy_paths', $data);
        $this->set_mapping('format_selfstudy_path', $oldid, $newid);
    }

    /**
     * Restores one learning path item.
     *
     * @param array $data
     */
    public function process_format_selfstudy_item(array $data): void {
        global $DB;

        $oldid = (int)$data['id'];
        $data = (object)$data;
        $data->pathid = $this->get_mappingid('format_selfstudy_path', $data->pathid, 0);
        if (empty($data->pathid)) {
            return;
        }

        if (!empty($data->parentid)) {
            $data->parentid = $this->get_mappingid('format_selfstudy_item', $data->parentid, 0);
        }
        if (!empty($data->cmid)) {
            $data->cmid = $this->get_mappingid('course_module', $data->cmid, 0);
        }
        if (!empty($data->sectionid)) {
            $data->sectionid = $this->get_mappingid('course_section', $data->sectionid, 0);
        }

        if ($data->itemtype === 'station' && empty($data->cmid)) {
            return;
        }

        $newid = $DB->insert_record('format_selfstudy_path_items', $data);
        $this->set_mapping('format_selfstudy_item', $oldid, $newid);
    }

    /**
     * Restores one published runtime snapshot.
     *
     * @param array $data
     */
    public function process_format_selfstudy_snapshot(array $data): void {
        global $DB;

        $data = (object)$data;
        $data->pathid = $this->get_mappingid('format_selfstudy_path', $data->pathid, 0);
        if (empty($data->pathid)) {
            return;
        }

        $olduserid = (int)($data->userid ?? 0);
        $data->courseid = $this->step->get_task()->get_courseid();
        $data->userid = empty($olduserid) ? 0 : $this->get_mappingid('user', $olduserid, 0);
        if ($olduserid && empty($data->userid)) {
            return;
        }

        $data->snapshotjson = $this->remap_snapshot_json((string)($data->snapshotjson ?? ''), (int)$data->pathid,
            (int)$data->courseid, (int)$data->userid);
        if ($data->snapshotjson === '') {
            return;
        }
        $data->sourcehash = hash('sha256', $data->snapshotjson);

        $existing = $DB->get_record('format_selfstudy_snapshots', ['pathid' => $data->pathid], '*', IGNORE_MISSING);
        if ($existing) {
            $data->id = $existing->id;
            $DB->update_record('format_selfstudy_snapshots', $data);
            return;
        }

        $DB->insert_record('format_selfstudy_snapshots', $data);
    }

    /**
     * Restores one published runtime snapshot revision.
     *
     * @param array $data
     */
    public function process_format_selfstudy_revision(array $data): void {
        global $DB;

        $data = (object)$data;
        $data->pathid = $this->get_mappingid('format_selfstudy_path', $data->pathid, 0);
        if (empty($data->pathid)) {
            return;
        }

        $olduserid = (int)($data->userid ?? 0);
        $oldpublishedby = (int)($data->publishedby ?? 0);
        $data->courseid = $this->step->get_task()->get_courseid();
        $data->userid = empty($olduserid) ? 0 : $this->get_mappingid('user', $olduserid, 0);
        $data->publishedby = empty($oldpublishedby) ? 0 : $this->get_mappingid('user', $oldpublishedby, 0);
        if (($olduserid && empty($data->userid)) || ($oldpublishedby && empty($data->publishedby))) {
            return;
        }

        $data->snapshotjson = $this->remap_snapshot_json((string)($data->snapshotjson ?? ''), (int)$data->pathid,
            (int)$data->courseid, (int)$data->userid);
        if ($data->snapshotjson === '') {
            return;
        }
        $data->sourcehash = hash('sha256', $data->snapshotjson);
        $data->revision = max(1, (int)($data->revision ?? 1));
        $data->status = clean_param((string)($data->status ?? 'published'), PARAM_ALPHANUMEXT);
        $data->active = empty($data->active) ? 0 : 1;

        if ($data->active) {
            $DB->set_field('format_selfstudy_revisions', 'active', 0, ['pathid' => $data->pathid, 'active' => 1]);
            $DB->set_field('format_selfstudy_revisions', 'status', 'archived', ['pathid' => $data->pathid, 'active' => 0]);
        }

        $existing = $DB->get_record('format_selfstudy_revisions', [
            'pathid' => $data->pathid,
            'revision' => $data->revision,
        ], '*', IGNORE_MISSING);
        if ($existing) {
            $data->id = $existing->id;
            $DB->update_record('format_selfstudy_revisions', $data);
        } else {
            $DB->insert_record('format_selfstudy_revisions', $data);
        }

        if (!empty($data->active)) {
            $snapshot = clone($data);
            unset($snapshot->revision, $snapshot->publishedby, $snapshot->timepublished, $snapshot->status, $snapshot->active);
            $existing = $DB->get_record('format_selfstudy_snapshots', ['pathid' => $snapshot->pathid], '*', IGNORE_MISSING);
            if ($existing) {
                $snapshot->id = $existing->id;
                $DB->update_record('format_selfstudy_snapshots', $snapshot);
            } else {
                unset($snapshot->id);
                $DB->insert_record('format_selfstudy_snapshots', $snapshot);
            }
        }
    }

    /**
     * Restores one learner path choice.
     *
     * @param array $data
     */
    public function process_format_selfstudy_choice(array $data): void {
        global $DB;

        if (!$this->step->get_task()->get_setting_value('users')) {
            return;
        }

        $data = (object)$data;
        $data->courseid = $this->step->get_task()->get_courseid();
        $data->userid = $this->get_mappingid('user', $data->userid, 0);
        $data->pathid = $this->get_mappingid('format_selfstudy_path', $data->pathid, 0);

        if (empty($data->userid) || empty($data->pathid)) {
            return;
        }

        if (!$DB->record_exists('format_selfstudy_choices', [
                'courseid' => $data->courseid,
                'userid' => $data->userid,
            ])) {
            $DB->insert_record('format_selfstudy_choices', $data);
        }
    }

    /**
     * Restores one milestone completion.
     *
     * @param array $data
     */
    public function process_format_selfstudy_milestone(array $data): void {
        global $DB;

        if (!$this->step->get_task()->get_setting_value('users')) {
            return;
        }

        $data = (object)$data;
        $data->itemid = $this->get_mappingid('format_selfstudy_item', $data->itemid, 0);
        $data->userid = $this->get_mappingid('user', $data->userid, 0);

        if (empty($data->itemid) || empty($data->userid)) {
            return;
        }

        if (!$DB->record_exists('format_selfstudy_milestones', [
                'itemid' => $data->itemid,
                'userid' => $data->userid,
            ])) {
            $DB->insert_record('format_selfstudy_milestones', $data);
        }
    }

    /**
     * Restores one activity learning goal.
     *
     * @param array $data
     */
    public function process_format_selfstudy_cmgoal(array $data): void {
        global $DB;

        $data = (object)$data;
        $data->cmid = $this->get_mappingid('course_module', $data->cmid, 0);
        if (empty($data->cmid)) {
            return;
        }
        $data->durationminutes = (int)($data->durationminutes ?? 0);
        $data->competencies = '';

        $existing = $DB->get_record('format_selfstudy_cmgoals', ['cmid' => $data->cmid], '*', IGNORE_MISSING);
        if ($existing) {
            $data->id = $existing->id;
            $DB->update_record('format_selfstudy_cmgoals', $data);
            return;
        }

        $DB->insert_record('format_selfstudy_cmgoals', $data);
    }

    /**
     * Remaps course-local ids inside snapshot JSON.
     *
     * @param string $snapshotjson
     * @param int $pathid
     * @param int $courseid
     * @param int $userid
     * @return string
     */
    private function remap_snapshot_json(string $snapshotjson, int $pathid, int $courseid, int $userid): string {
        if ($snapshotjson === '') {
            return '';
        }

        $snapshot = json_decode($snapshotjson, true);
        if (!is_array($snapshot)) {
            return '';
        }

        if (!isset($snapshot['path']) || !is_array($snapshot['path'])) {
            $snapshot['path'] = [];
        }
        $snapshot['path']['id'] = $pathid;
        $snapshot['path']['courseid'] = $courseid;
        $snapshot['path']['userid'] = $userid;

        if (isset($snapshot['nodes']) && is_array($snapshot['nodes'])) {
            foreach ($snapshot['nodes'] as &$node) {
                if (!is_array($node)) {
                    continue;
                }
                if (!empty($node['cmid'])) {
                    $node['cmid'] = $this->get_mappingid('course_module', $node['cmid'], 0);
                }
                if (!empty($node['sectionid'])) {
                    $node['sectionid'] = $this->get_mappingid('course_section', $node['sectionid'], 0);
                }
            }
            unset($node);
            $snapshot['nodes'] = array_values(array_filter($snapshot['nodes'], static function($node): bool {
                return is_array($node) && !empty($node['key']) &&
                    (($node['type'] ?? '') !== 'station' || !empty($node['cmid']));
            }));
            $validkeys = array_flip(array_map(static function(array $node): string {
                return (string)$node['key'];
            }, $snapshot['nodes']));
            foreach ($snapshot['nodes'] as &$node) {
                if (!empty($node['children']) && is_array($node['children'])) {
                    $node['children'] = array_values(array_filter($node['children'], static function($childkey) use ($validkeys): bool {
                        return isset($validkeys[(string)$childkey]);
                    }));
                }
            }
            unset($node);
            if (!empty($snapshot['root']) && is_array($snapshot['root'])) {
                $snapshot['root'] = array_values(array_filter($snapshot['root'], static function($rootkey) use ($validkeys): bool {
                    return isset($validkeys[(string)$rootkey]);
                }));
            }
        }

        return json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
