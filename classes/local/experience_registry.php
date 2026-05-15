<?php
// This file is part of Moodle - http://moodle.org/

namespace format_selfstudy\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Registry for optional learner experiences.
 */
class experience_registry {

    /** @var string Status for installed and usable entries. */
    public const STATUS_AVAILABLE = 'available';

    /** @var string Status for stored entries whose plugin is not installed. */
    public const STATUS_MISSING = 'missing';

    /** @var string Status for stored entries disabled for the course. */
    public const STATUS_DISABLED = 'disabled';

    /** @var string Status for installed entries with invalid metadata or renderer. */
    public const STATUS_INCOMPATIBLE = 'incompatible';

    /** @var experience_repository */
    private $repository;

    /** @var array|null */
    private $installedmetadata;

    /**
     * Constructor.
     *
     * @param experience_repository|null $repository
     * @param array|null $installedmetadata Optional component => metadata map, primarily for tests.
     */
    public function __construct(?experience_repository $repository = null, ?array $installedmetadata = null) {
        $this->repository = $repository ?? new experience_repository();
        $this->installedmetadata = $installedmetadata;
    }

    /**
     * Returns registry entries for a course, including stored missing entries.
     *
     * @param \stdClass $course
     * @param \stdClass|null $baseview
     * @return \stdClass[]
     */
    public function get_course_experiences(\stdClass $course, ?\stdClass $baseview = null): array {
        $installed = $this->get_installed_metadata();
        $this->repository->mark_missing_experiences((int)$course->id, array_keys($installed));

        $stored = [];
        foreach ($this->repository->get_course_experiences((int)$course->id) as $record) {
            $stored[$record->component] = $record;
        }

        $entries = [];
        foreach ($stored as $component => $record) {
            $metadata = $installed[$component] ?? null;
            $entries[] = $this->build_entry($course, $record, $metadata, $baseview);
            unset($installed[$component]);
        }

        foreach ($installed as $component => $metadata) {
            $record = (object)[
                'courseid' => (int)$course->id,
                'component' => $component,
                'enabled' => 0,
                'sortorder' => 9999,
                'configjson' => '{}',
                'configschema' => (int)($metadata->schema ?? 1),
                'missing' => 0,
            ];
            $entries[] = $this->build_entry($course, $record, $metadata, $baseview);
        }

        usort($entries, static function(\stdClass $left, \stdClass $right): int {
            return [$left->sortorder, $left->component] <=> [$right->sortorder, $right->component];
        });

        return $entries;
    }

    /**
     * Returns enabled, available and compatible entries for rendering.
     *
     * @param \stdClass $course
     * @param \stdClass $baseview
     * @return \stdClass[]
     */
    public function get_renderable_experiences(\stdClass $course, \stdClass $baseview): array {
        return array_values(array_filter($this->get_course_experiences($course, $baseview),
            static function(\stdClass $entry): bool {
                return $entry->status === self::STATUS_AVAILABLE && !empty($entry->enabled) &&
                    $entry->renderer instanceof experience_renderer_interface;
            }));
    }

    /**
     * Returns optional activity navigation hints from the first supporting experience.
     *
     * @param \stdClass $course
     * @param \stdClass $baseview
     * @param \cm_info $cm
     * @return \stdClass|null
     */
    public function get_activity_navigation(\stdClass $course, \stdClass $baseview, \cm_info $cm): ?\stdClass {
        foreach ($this->get_renderable_experiences($course, $baseview) as $entry) {
            try {
                $navigation = $entry->renderer->get_activity_navigation($course, $baseview, $cm, $entry->config);
                if ($navigation) {
                    return $navigation;
                }
            } catch (\Throwable $exception) {
                debugging('Selfstudy experience navigation failed for ' . $entry->component . ': ' .
                    $exception->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return null;
    }

    /**
     * Builds one registry entry.
     *
     * @param \stdClass $course
     * @param \stdClass $record
     * @param \stdClass|null $metadata
     * @param \stdClass|null $baseview
     * @return \stdClass
     */
    private function build_entry(\stdClass $course, \stdClass $record, ?\stdClass $metadata,
            ?\stdClass $baseview = null): \stdClass {
        $status = self::STATUS_AVAILABLE;
        $renderer = null;

        if (!$metadata) {
            $status = self::STATUS_MISSING;
        } else if (empty($record->enabled)) {
            $status = self::STATUS_DISABLED;
        } else {
            $renderer = $this->create_renderer($metadata);
            if (!$renderer) {
                $status = self::STATUS_INCOMPATIBLE;
            } else if ($baseview) {
                try {
                    if (!$renderer->supports($course, $baseview, $this->repository->decode_config($record))) {
                        $status = self::STATUS_INCOMPATIBLE;
                    }
                } catch (\Throwable $exception) {
                    debugging('Selfstudy experience support check failed for ' . $record->component . ': ' .
                        $exception->getMessage(), DEBUG_DEVELOPER);
                    $status = self::STATUS_INCOMPATIBLE;
                }
            }
        }

        return (object)[
            'component' => (string)$record->component,
            'name' => $metadata->name ?? (string)$record->component,
            'description' => $metadata->description ?? '',
            'icon' => $metadata->icon ?? '',
            'schema' => (int)($metadata->schema ?? $record->configschema ?? 1),
            'features' => $metadata->features ?? [],
            'rendererclass' => $metadata->rendererclass ?? '',
            'configformclass' => $metadata->configformclass ?? '',
            'enabled' => !empty($record->enabled),
            'sortorder' => (int)($record->sortorder ?? 0),
            'missing' => $status === self::STATUS_MISSING,
            'status' => $status,
            'record' => $record,
            'config' => $this->repository->decode_config($record),
            'renderer' => $renderer,
        ];
    }

    /**
     * Creates a renderer instance from metadata.
     *
     * @param \stdClass $metadata
     * @return experience_renderer_interface|null
     */
    private function create_renderer(\stdClass $metadata): ?experience_renderer_interface {
        $class = (string)($metadata->rendererclass ?? '');
        if ($class !== '' && !class_exists($class)) {
            $this->require_component_class((string)($metadata->component ?? ''), 'renderer');
        }
        if ($class === '' || !class_exists($class)) {
            return null;
        }

        $renderer = new $class();
        return $renderer instanceof experience_renderer_interface ? $renderer : null;
    }

    /**
     * Returns installed experience metadata keyed by component.
     *
     * @return \stdClass[]
     */
    private function get_installed_metadata(): array {
        if ($this->installedmetadata !== null) {
            return $this->normalise_metadata_map($this->installedmetadata);
        }

        $components = [];
        if (class_exists('\core_component') &&
                array_key_exists('selfstudyexperience', \core_component::get_plugin_types())) {
            foreach (\core_component::get_plugin_list('selfstudyexperience') as $name => $dir) {
                $component = 'selfstudyexperience_' . $name;
                $metadata = $this->load_component_metadata($component);
                if ($metadata) {
                    $components[$component] = $metadata;
                }
            }
        }

        foreach ($this->get_packaged_component_names() as $name) {
            $component = 'selfstudyexperience_' . $name;
            if (isset($components[$component])) {
                continue;
            }
            $metadata = $this->load_component_metadata($component);
            if ($metadata) {
                $components[$component] = $metadata;
            }
        }

        return $components;
    }

    /**
     * Loads metadata from a component provider class.
     *
     * @param string $component
     * @return \stdClass|null
     */
    private function load_component_metadata(string $component): ?\stdClass {
        $class = '\\' . $component . '\\experience';
        if (!class_exists($class)) {
            $this->require_component_class($component, 'experience');
        }
        if (!class_exists($class) || !method_exists($class, 'get_metadata')) {
            return null;
        }

        try {
            $metadata = $class::get_metadata();
        } catch (\Throwable $exception) {
            debugging('Selfstudy experience metadata failed for ' . $component . ': ' .
                $exception->getMessage(), DEBUG_DEVELOPER);
            return null;
        }

        return $this->normalise_metadata($component, $metadata);
    }

    /**
     * Returns packaged experience plugin names from the format directory.
     *
     * @return string[]
     */
    private function get_packaged_component_names(): array {
        $experiencedir = dirname(__DIR__, 2) . '/experience';
        if (!is_dir($experiencedir)) {
            return [];
        }

        $names = [];
        foreach (glob($experiencedir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = clean_param(basename($dir), PARAM_PLUGIN);
            if ($name !== '' && is_readable($dir . '/classes/experience.php')) {
                $names[] = $name;
            }
        }

        sort($names);
        return $names;
    }

    /**
     * Requires a packaged experience class file when Moodle has not autoloaded it as a subplugin.
     *
     * @param string $component
     * @param string $classname
     */
    private function require_component_class(string $component, string $classname): void {
        $component = clean_param($component, PARAM_COMPONENT);
        if (strpos($component, 'selfstudyexperience_') !== 0) {
            return;
        }

        $name = substr($component, strlen('selfstudyexperience_'));
        $name = clean_param($name, PARAM_PLUGIN);
        if ($name === '' || clean_param($classname, PARAM_ALPHANUMEXT) !== $classname) {
            return;
        }

        $path = dirname(__DIR__, 2) . '/experience/' . $name . '/classes/' . $classname . '.php';
        if (is_readable($path)) {
            require_once($path);
        }
    }

    /**
     * Normalises an injected metadata map.
     *
     * @param array $metadata
     * @return \stdClass[]
     */
    private function normalise_metadata_map(array $metadata): array {
        $normalised = [];
        foreach ($metadata as $component => $entry) {
            $component = clean_param(is_string($component) ? $component : (string)($entry->component ?? ''),
                PARAM_COMPONENT);
            $entry = $this->normalise_metadata($component, $entry);
            if ($entry) {
                $normalised[$component] = $entry;
            }
        }

        return $normalised;
    }

    /**
     * Normalises one metadata object.
     *
     * @param string $component
     * @param mixed $metadata
     * @return \stdClass|null
     */
    private function normalise_metadata(string $component, $metadata): ?\stdClass {
        if (is_array($metadata)) {
            $metadata = (object)$metadata;
        }
        if (!$metadata instanceof \stdClass) {
            return null;
        }

        $metadata->component = clean_param((string)($metadata->component ?? $component), PARAM_COMPONENT);
        if ($metadata->component !== $component) {
            return null;
        }
        $metadata->name = trim((string)($metadata->name ?? $component));
        $metadata->description = (string)($metadata->description ?? '');
        $metadata->icon = (string)($metadata->icon ?? '');
        $metadata->schema = max(1, (int)($metadata->schema ?? 1));
        $metadata->features = array_values(array_filter((array)($metadata->features ?? []), 'is_string'));
        $metadata->rendererclass = (string)($metadata->rendererclass ?? '');
        $metadata->configformclass = (string)($metadata->configformclass ?? '');
        if ($metadata->configformclass !== '' && !class_exists($metadata->configformclass)) {
            $this->require_component_class($metadata->component, 'config_form');
        }

        return $metadata->name === '' ? null : $metadata;
    }
}
