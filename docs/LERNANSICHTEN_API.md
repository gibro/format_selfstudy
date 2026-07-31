# Lernansichten-API

Stand: 2026-07-23

Diese Datei dokumentiert die aktuelle Entwickler-API für optionale Lernansichten im Kursformat `format_selfstudy`.

Die API ist in der ersten tragfähigen Ausbaustufe. Lernansichten sind **paketinterne Module** dieses Kursformats,
keine eigenständigen Moodle-Plugins. Neue Lernansichten sollten nah an den hier beschriebenen Verträgen bleiben und
keine Core-Logik duplizieren.

## Warum keine Moodle-Subplugins

Moodle erlaubt Subplugins nur für die Plugin-Typen `mod`, `editor`, `tool` und `local`
(`core_component::$supportsubplugins`). Kursformate stehen nicht in dieser Liste, ein `db/subplugins.json` in
`format_selfstudy` würde also nie gelesen. Lernansichten werden deshalb bewusst als paketinterne Module ausgeliefert:
Die Registry findet sie über das Verzeichnis `experience/` und lädt ihre Klassen direkt per Pfad.

Das hat Konsequenzen, die beim Bauen einer Lernansicht wichtig sind:

- Eine Lernansicht wird **nicht** einzeln installiert, sondern mit dem Kursformat ausgeliefert.
- Es gibt **keine** eigene `version.php`, kein eigenes Upgrade und keine eigenen Moodle-Capabilities.
- Es gibt **keine** eigene Sprachdatei. Alle Strings liegen in `lang/{de,en}/format_selfstudy.php` und werden mit der
  Komponente `format_selfstudy` geholt.
- Eigene Datenbanktabellen müssten in `db/install.xml` und `db/upgrade.php` des Kursformats gepflegt werden.

Sollen Lernansichten später wirklich getrennt installierbar sein, ist der Weg ein eigenes `local_`-Plugin als Wirt —
nur dort erlaubt Moodle echte Subplugins. Das ist ausdrücklich **nicht** der aktuelle Stand.

## Begriff

Eine Lernansicht ist eine optionale Darstellung oder Interaktion für denselben veröffentlichten Lernpfad. Beispiele sind eine Lernlandkarte, eine Story-Ansicht, eine Kompetenzmatrix oder später ein spielerischer Modus.

Technisch werden Lernansichten über einen Komponentenschlüssel identifiziert:

```text
selfstudyexperience_{name}
```

Dieser Schlüssel ist **kein** Moodle-Komponentenname, sondern der Schlüssel, unter dem die Registry die Lernansicht
führt und unter dem ihre Konfiguration in `format_selfstudy_experiences` gespeichert ist. Er bleibt stabil, weil
Kursdaten darauf verweisen.

Die erste Lernansicht ist:

```text
selfstudyexperience_learningmap
```

Sie liegt unter:

```text
course/format/selfstudy/experience/learningmap
```

## Architekturregeln

Lernansichten sind Zusatzdarstellungen. Die fachliche Lernlogik bleibt im Core.

Eine Lernansicht darf:

- den veröffentlichten Lernpfad darstellen,
- Zusatzlinks oder visuelle Einstiegspunkte rendern,
- Activity-Navigation-Hinweise liefern,
- eigene Darstellungsdaten in der gemeinsamen Konfiguration speichern,
- optional eigene Tabellen nutzen, wenn spätere Ausbaustufen das brauchen.

Eine Lernansicht darf nicht:

- harte Freischaltungen berechnen,
- Moodle-Availability ersetzen,
- Completion-Regeln als eigene Wahrheit speichern,
- Aktivitäten freigeben, die Moodle selbst sperrt,
- die Basisansicht voraussetzen oder blockieren.

Wenn eine Lernansicht fehlt, deaktiviert ist, inkompatibel ist oder beim Rendern eine Exception wirft, muss der Kurs weiterhin über Dashboard, Listenansicht und Basisnavigation nutzbar bleiben.

## Verzeichnisstruktur

Struktur einer Lernansicht:

```text
course/format/selfstudy/experience/{name}/
  classes/
    experience.php
    renderer.php
    config_form.php   (optional)
```

Mehr braucht es nicht: keine `version.php`, kein `db/`, kein `lang/`. Die Registry erkennt ein Verzeichnis als
Lernansicht, sobald `classes/experience.php` lesbar ist.

Sprachstrings der Lernansicht gehören nach:

```text
course/format/selfstudy/lang/de/format_selfstudy.php
course/format/selfstudy/lang/en/format_selfstudy.php
```

Ein Präfix im Stringnamen hält sie beieinander, zum Beispiel `learningmapexperience_*`.

## Metadata-Provider

Jede Lernansicht stellt eine Klasse bereit:

```php
namespace selfstudyexperience_example;

defined('MOODLE_INTERNAL') || die();

class experience {
    public static function get_metadata(): \stdClass {
        return (object)[
            'component' => 'selfstudyexperience_example',
            'name' => get_string('exampleexperience_name', 'format_selfstudy'),
            'description' => get_string('exampleexperience_description', 'format_selfstudy'),
            'icon' => 'i/navigationitem',
            'schema' => 1,
            'features' => ['activitynavigation'],
            'rendererclass' => '\\selfstudyexperience_example\\renderer',
            'configformclass' => '',
        ];
    }
}
```

### Metadata-Felder

`component`
: Komponentenschlüssel der Lernansicht, zum Beispiel `selfstudyexperience_example`. Muss zum Verzeichnisnamen passen.

`name`
: Sichtbarer Name in der Oberfläche. Für Lehrende nutzernahe Begriffe verwenden, zum Beispiel `Lernlandkarte`.

`description`
: Kurze Beschreibung für die Lernansichten-Verwaltung.

`icon`
: Moodle-Pix-Iconname. Aktuell rein informativ.

`schema`
: Schema-Version der Konfiguration dieser Lernansicht. Mindestens `1`.

`features`
: Liste unterstützter Features. Übliche Werte sind `map`, `activitynavigation`, `sectionmaps`, `fullscreen`, `avatar`.

`rendererclass`
: Vollqualifizierte Renderer-Klasse.

`configformclass`
: Für spätere Ausbaustufen vorgesehen. Aktuell kann dieser Wert leer bleiben.

## Renderer-Vertrag

Renderer implementieren:

```php
\format_selfstudy\local\experience_renderer_interface
```

Das Interface liegt in:

```text
course/format/selfstudy/classes/local/experience_renderer_interface.php
```

Aktueller Vertrag:

```php
interface experience_renderer_interface {
    public function supports(\stdClass $course, \stdClass $baseview, \stdClass $config): bool;

    public function render_course_entry(\stdClass $course, \stdClass $baseview,
            \stdClass $config): string;

    public function get_activity_navigation(\stdClass $course, \stdClass $baseview,
            \cm_info $cm, \stdClass $config): ?\stdClass;
}
```

### `supports()`

Gibt zurück, ob die Lernansicht im aktuellen Kurs mit der aktuellen Konfiguration nutzbar ist.

Diese Methode sollte keine Ausgabe erzeugen und keine Exceptions nach außen werfen. Wenn eine Abhängigkeit fehlt oder die Konfiguration hier nicht passt, `false` zurückgeben.

Beispiele:

- benötigte Aktivität nicht vorhanden,
- Konfigurationsschema nicht unterstützt,
- notwendige Assets oder Daten fehlen.

### `render_course_entry()`

Rendert einen Einstieg für die Kursseite. Das Ergebnis wird in der Experience-Zone der Kursübersicht ausgegeben.

Rückgabe:

- HTML-String, wenn etwas angezeigt werden soll,
- leerer String, wenn die Lernansicht im Kurs aktuell keinen Einstieg hat.

Die Methode bekommt `baseview`, damit sie den veröffentlichten Lernpfad, Fortschritt und Outline nutzen kann. Sie soll nicht direkt das Editor-Raster lesen.

### `get_activity_navigation()`

Liefert optionale Hinweise für Aktivitätsseiten. Die Basisnavigation bleibt im Core.

Erlaubte Rückgabewerte im aktuellen Ausbau:

```php
return (object)[
    'mapurl' => '/mod/example/view.php?id=123',
    'mapbackgroundurl' => null,
    'previousurl' => null,
    'nexturl' => null,
];
```

Alle Felder sind optional. Typische Lernansichten liefern nur `mapurl`.

`previousurl` und `nexturl` sollten nur gesetzt werden, wenn die Lernansicht bewusst die Core-Basisnavigation ergänzen oder übersteuern muss. In der Regel bleiben vorherige/nächste Aktivität Core-Aufgabe.

## Basisdaten

Renderer bekommen vom Core:

`$course`
: Moodle-Kursobjekt.

`$baseview`
: Kanonische Lernendenansicht aus `format_selfstudy\local\base_view::create()`. Enthält je nach Kurs unter anderem den aktiven Lernpfad, Fortschritt und Outline.

`$config`
: Dekodierte JSON-Konfiguration aus `format_selfstudy_experiences.configjson`.

`$cm`
: Aktuelle Aktivität auf Aktivitätsseiten.

## Konfigurationsspeicher

Gemeinsame Lernansicht-Konfigurationen liegen in:

```text
format_selfstudy_experiences
```

Wichtige Felder:

`courseid`
: Kurs-ID.

`component`
: Komponentenname, zum Beispiel `selfstudyexperience_example`.

`enabled`
: Ob die Lernansicht im Kurs aktiv ist.

`sortorder`
: Reihenfolge in der Experience-Zone und Verwaltung.

`configjson`
: JSON-Konfiguration der Lernansicht.

`configschema`
: Schema-Version der gespeicherten Konfiguration.

`missing`
: Die gespeicherte Lernansicht gehört nicht mehr zum Paket, ihre Konfiguration bleibt aber erhalten.

Zugriff erfolgt über:

```php
$repository = new \format_selfstudy\local\experience_repository();
$record = $repository->get_course_experience($courseid, 'selfstudyexperience_example');
$config = $record ? $repository->decode_config($record) : (object)[];

$repository->save_course_experience(
    $courseid,
    'selfstudyexperience_example',
    ['mode' => 'compact'],
    true,
    10,
    1,
    false
);
```

## Registry

Die Registry liegt in:

```text
course/format/selfstudy/classes/local/experience_registry.php
```

Sie ist zuständig für:

- paketinterne Lernansichten im Verzeichnis `experience/` finden und ihre Klassen laden,
- gespeicherte, nicht mehr vorhandene Lernansichten erhalten,
- Status berechnen,
- Renderer erzeugen,
- nur aktive und kompatible Lernansichten an Kursseite und Navigation geben,
- Exceptions beim Rendern und bei Navigation isolieren.

Statuswerte:

`available`
: Vorhanden, ausgewählt und nutzbar.

`disabled`
: Vorhanden, aber im Kurs nicht ausgewählt.

`missing`
: Gespeichert, aber im Paket nicht mehr vorhanden.

`incompatible`
: Vorhanden, aber Metadaten, Renderer oder `supports()` passen nicht.

## Kursseiten-Hook

Die Kursseite ruft:

```php
format_selfstudy_render_experience_zone($course, $baseview)
```

Der Hook lädt renderbare Lernansichten über die Registry und ruft pro Eintrag:

```php
$entry->renderer->render_course_entry($course, $baseview, $entry->config);
```

Leere Rückgaben werden ignoriert. Exceptions werden abgefangen, damit die Basisansicht weiter funktioniert.

## Activity-Navigation-Hook

Aktivitätsseiten fragen optionale Hinweise über:

```php
$registry->get_activity_navigation($course, $baseview, $cm);
```

Die erste aktive Lernansicht, die ein nicht-leeres Objekt zurückgibt, liefert die Zusatzhinweise. Wenn keine Lernansicht etwas liefert, fällt die Navigation auf die Core-Basislogik zurück.

## Beispiel: Minimaler Renderer

```php
namespace selfstudyexperience_example;

use format_selfstudy\local\experience_renderer_interface;

defined('MOODLE_INTERNAL') || die();

class renderer implements experience_renderer_interface {
    public function supports(\stdClass $course, \stdClass $baseview, \stdClass $config): bool {
        return !empty($config->enabledview);
    }

    public function render_course_entry(\stdClass $course, \stdClass $baseview,
            \stdClass $config): string {
        if (empty($config->url)) {
            return '';
        }

        return \html_writer::link(
            new \moodle_url($config->url),
            get_string('exampleexperience_open', 'format_selfstudy'),
            ['class' => 'btn btn-secondary']
        );
    }

    public function get_activity_navigation(\stdClass $course, \stdClass $baseview,
            \cm_info $cm, \stdClass $config): ?\stdClass {
        if (empty($config->url)) {
            return null;
        }

        return (object)[
            'mapurl' => (new \moodle_url($config->url))->out(false),
        ];
    }
}
```

## Beispiel: Konfiguration

Eine Lernansicht kann ihre eigenen Einstellungen als JSON-Objekt speichern. Ein minimales Beispiel:

```json
{
  "url": "/course/view.php?id=42",
  "accent": "#1f7a64"
}
```

Die Bedeutung und Validierung dieses Objekts gehoert zur jeweiligen Lernansicht. Die generische Verwaltung
speichert die Konfiguration nur und reicht sie an Renderer und Navigation weiter.

## Backup, Restore und Transfer

Die gemeinsame Tabelle `format_selfstudy_experiences` wird durch die Paket-6-Mechanik in Backup/Restore und Path-Transfer erhalten.

Regel:

- bekannte Lernansichten können aktiv bleiben,
- fehlende Lernansichten bleiben gespeichert,
- fehlende Lernansichten werden als `missing` und inaktiv behandelt,
- Konfiguration wird nicht gelöscht, nur weil die passende Lernansicht gerade fehlt.

## Tests

API-nahe Tests liegen in:

```text
course/format/selfstudy/tests/experience_api_test.php
```

Wichtige Testfälle:

- Repository speichert, liest und markiert fehlende Lernansichten,
- Registry unterscheidet `available`, `disabled`, `missing`, `incompatible`,
- Activity-Navigation nutzt nur aktive verfügbare Lernansichten,
- Transfer erhält Experience-Konfiguration,
- Learningmap-Migration erhält auch ungültige Legacy-Referenzen ohne Exception,
- Learningmap-Renderer rendert ohne nutzbare Karte keinen Einstieg.

## Namens- und UI-Regeln

In Lehrenden- und Lernendenoberflächen werden nutzernahe Begriffe verwendet:

- `Lernansicht`,
- `Lernlandkarte`,
- `Haupt-Lernlandkarte`,
- `Unter-Lernlandkarte`.

Technische Begriffe wie `Experience` oder `selfstudyexperience_*` bleiben technischen Dateien, Debug-Kontext und Entwicklerdokumentation vorbehalten.

## Checkliste für neue Lernansichten

1. Verzeichnis `experience/{name}` anlegen (keine eigene `version.php` — Lernansichten sind paketintern, siehe Verzeichnisstruktur; die `version.php` des Kursformats hochzählen).
2. `classes/experience.php` mit `get_metadata()` erstellen (Komponente `selfstudyexperience_{name}` passend zum Verzeichnisnamen).
3. `classes/renderer.php` erstellen und `experience_renderer_interface` implementieren.
4. Sprachstrings in `lang/de` und `lang/en` ergänzen.
5. Konfigurationsschema definieren und `schema` setzen.
6. Keine harte Freischaltlogik in der Lernansicht implementieren.
7. Leere Ausgabe oder `null` zurückgeben, wenn Daten fehlen.
8. Tests für Registry, Rendering-Fallback und Navigation ergänzen.
9. Browser-Smoke: Kursseite, Lernansichten-Seite und Aktivitätsseite prüfen.
