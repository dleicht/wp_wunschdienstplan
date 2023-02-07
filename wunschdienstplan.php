<?php

/**
 * Wunschdienstplan
 *
 * @package           Wunschdienstplan
 * @author            Dominik Leicht
 * @copyright         2023 Dominik Leicht
 * @license           GPLv2
 *
 * @wordpress-plugin
 * Plugin Name:       Wunschdienstplan
 * Plugin URI:        https://github.com/dleicht/wp_wunschdienstplan
 * Description:       UKGM Wunschdienstplan Tool. Frontend und Backend Funktionalität.
 * Version:           0.0.1
 * Requires at least: 6.1
 * Requires PHP:      7.2
 * Author:            Dominik Leicht
 * Author URI:        https://github.com/dleicht
 * Text Domain:       wunschdienstplan
 * License:           GPL v2 only
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

/* Verbiete den direkten Zugriff auf die Plugin-Datei. */
defined('ABSPATH') or die('Na na na! *fingerzeig* Das ist aber jemand unanständig!');

/* CSS und JS bereitstellen. */
add_action('wp_enqueue_scripts', 'callback_for_setting_up_scripts');
function callback_for_setting_up_scripts() {
    wp_register_style('datatables', plugin_dir_url(__FILE__).'inc/datatables.css');
    wp_register_style('datetimepicker', plugin_dir_url(__FILE__).'inc/jquery.datetimepicker.min.css');
    wp_enqueue_style('datatables');
    wp_enqueue_style('datetimepicker');
    wp_enqueue_script('jquery');
    wp_enqueue_script('datatables', plugin_dir_url(__FILE__).'inc/datatables.js', array('jquery'));
    wp_enqueue_script('datetimepicker', plugin_dir_url(__FILE__).'inc/jquery.datetimepicker.full.min.js', array('jquery'));
    wp_enqueue_script('sweetalert', plugin_dir_url(__FILE__).'inc/sweetalert.min.js');
}

add_action('admin_enqueue_scripts', 'callback_for_setting_up_admin_scripts');
function callback_for_setting_up_admin_scripts() {
    wp_register_style('datatables', plugin_dir_url(__FILE__).'inc/datatables.css');
    wp_enqueue_style('datatables');
    wp_enqueue_script('jquery');
    wp_enqueue_script('datatables', plugin_dir_url(__FILE__).'inc/datatables.js', array('jquery'));
}


/* Initialisieren der Datenbank tables beim Aktivieren des plugins, wenn noch nicht vorhanden.
Dienstarten und Dienstgründe sind durch die entsprechenden Datenbank Tabellen vorgegeben bzw. dort konfigurierbar. */
function wdp_initialize() {
    global $wpdb;
    $table_entries = $wpdb->prefix . "wdp_entries";
    $table_dienstarten = $wpdb->prefix . "wdp_dienstarten";
    $table_dienstgruende = $wpdb->prefix . "wdp_dienstgruende";
    $charset_collate = $wpdb->get_charset_collate();

    if($wpdb->get_var("SHOW TABLES LIKE '$table_entries'") != $table_entries) {
        $sql = "CREATE TABLE $table_entries (
                    id int NOT NULL AUTO_INCREMENT,
                    user_id smallint NOT NULL,
                    user_name varchar(20),
                    wunsch_date date NOT NULL,
                    entry_date timestamp NOT NULL,
                    dienstart tinyint NOT NULL,
                    dienstgrund tinyint NOT NULL,
                    kommentar tinytext,
                    aktiv bool default true,
                    PRIMARY KEY  (id)
                ) $charset_collate;";
    
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    if($wpdb->get_var("SHOW TABLES LIKE '$table_dienstarten'") != $table_dienstarten) {
        $sql = "CREATE TABLE $table_dienstarten (
                    id int NOT NULL AUTO_INCREMENT,
                    dienstart tinytext NOT NULL,
                    PRIMARY KEY  (id)
                ) $charset_collate;";
    
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        $dienstarten = array('Kein Dienst','Klinik 24h','NEF Bad Nauheim','NEF Nidda','ITH 24h');
        foreach ($dienstarten as $dienstart) {
            $wpdb->insert(
                $table_dienstarten,
                array(
                    'dienstart' => $dienstart
                    )
            );
        }
    }

    if($wpdb->get_var("SHOW TABLES LIKE '$table_dienstgruende'") != $table_dienstgruende) {
        $sql = "CREATE TABLE $table_dienstgruende (
                    id int NOT NULL AUTO_INCREMENT,
                    dienstgrund tinytext NOT NULL,
                    PRIMARY KEY  (id)
                ) $charset_collate;";
    
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        $dienstgruende = array('dienstlich','privat');
        foreach ($dienstgruende as $dienstgrund) {
            $wpdb->insert(
                $table_dienstgruende,
                array(
                    'dienstgrund' => $dienstgrund
                    )
            );
        }
    }

}

/* Hier führen wir die Initialisierung einmalig bei der Aktivierung des Plugins durch. */
register_activation_hook(__FILE__, 'wdp_initialize');

/* Hier bauen wir das Formular um Wünsche einzutragen und ebenfalls die Listenansicht bereits eingetragener Wünsche. */
function wdp_print_user_table() {
    global $wpdb;
    $current_user = wp_get_current_user();
    setlocale(LC_ALL, "de_DE");
    $table_entries = $wpdb->prefix . "wdp_entries";
    $table_dienstarten = $wpdb->prefix . "wdp_dienstarten";
    $table_dienstgruende = $wpdb->prefix . "wdp_dienstgruende";
    
    /* Hier übernehmen wir die ID des Eintrags, der gelöscht werden soll.
    Dabei löschen wir den Eintrag aber nicht aus der Datenbank, wir markieren ihn nur als "nicht aktiv".
    Damit können wir rückwirkend nachvollziehen wann welche Einträge gemacht wurden. */
    if(isset($_POST["deleteid"])) {
        $wpdb->update(
            $table_entries,
            array( 
                'aktiv' => false
            ),
            array(
                'id' => $_POST["deleteid"]
            )
        );
    }

    /* Hier übernehmen wir Daten aus dem Eintragsformular und ergänzen den Rest für den Datenbankeintrag.
    Besonderes Augenmerk liegt hier auf der Formatierung des Datums!
    Datum als DATE in der DB und Datum als STRING in der Tabelle sind zwei Paar Schuhe!
    Das gilt insbesondere dann, wenn man nach Datum sortieren will. */
    if(isset($_POST["submit"])) {
        $wpdb->insert($table_entries, array(
            "user_id" => $current_user->id,
            "user_name" => $current_user->user_login,
            "wunsch_date" => date('Y-m-d', strtotime($_POST["wunschdate"])),
            "dienstart" => $_POST["dienstart"],
            "dienstgrund" => $_POST["dienstgrund"],
            "kommentar" => $_POST["kommentar"],
            "aktiv" => true ,
         ));
    }
    
    /* Hier wird geprüft, ob der User die entsprechende Rolle hat. */
    if (in_array( 'arzt', (array) $current_user->roles ) ) {
        echo '<style> 
        tr:nth-child(even) {
          background-color:#f0f0f0;
        }
        </style>';
        echo '<p>Deine Dienst Wünsche: <b>'.$current_user->first_name.' '.$current_user->last_name.' ('.$current_user->user_login.')</b></p>';
    
        /* Hier definieren wir Variablen, die wir für die Tabellen, das Formular und die javascript Konfigurationen brauchen. */
        $headers = array('Datum', 'Dienstart', 'Dienstgrund', 'Kommentar');
        $entries = $wpdb->get_results('SELECT a.id, date_format(a.wunsch_date, "%d.%m.%Y") as wunsch_date, b.dienstart, c.dienstgrund, a.kommentar FROM '.$table_entries.' a, '.$table_dienstarten.' b, '.$table_dienstgruende.' c WHERE a.dienstart = b.id AND a.dienstgrund = c.id AND a.user_id = '.$current_user->id.' AND a.aktiv is true ORDER BY a.wunsch_date ASC');
        /* Ein user soll ein Datum jeweils nur ein mal eintragen dürfen. Dafür bauen wir ein array mit den Datums Daten aus dem $entries query.
        Dieses array übergeben wir dann später an JS, denn wir wollen den Eintrag aus dem datetimepicker Feld damit matchen. */
        $entry_dates = [];
        foreach($entries as $entry) {
            array_push($entry_dates, $entry->wunsch_date);
        }
        $dienstarten = $wpdb->get_results('SELECT * from '.$table_dienstarten);
        $dienstgruende = $wpdb->get_results('SELECT * from '.$table_dienstgruende);
        $start_date = date('d.m.Y', strtotime('first day of this month +2 month'));

        
        /* START - Eintrag Formular */
        echo '<form onsubmit="return validateForm()" method="post">';
        echo '<table style="width:100%;font-size:80%">';
        echo '  <thead style="background-color:#d6d6d6">';
        echo '  <tr>';
        foreach($headers as $header) {
            echo '      <th>'.$header.'</th>';
        }
        echo '  </tr>';
        echo '  </thead>';
        echo '  <tbody>';
        echo '  <tr>';
        echo '      <td><input id="datetimepicker" type="text" name="wunschdate" value="'.$start_date.'" readonly style="margin:0px"></td>';
        echo '      <td><select name="dienstart" id="dienstart">';
        foreach($dienstarten as $dienstart) {
            echo '<option value="'.$dienstart->id.'">'.$dienstart->dienstart.'</option>';
        }
        echo '</select></td>';
        echo '      <td><select name="dienstgrund" id="dienstgrund">';
        foreach($dienstgruende as $dienstgrund) {
            echo '<option value="'.$dienstgrund->id.'">'.$dienstgrund->dienstgrund.'</option>';
        }
        echo '</select></td>';
        echo '      <td style="width:55%"><input type="text" name="kommentar" id="kommentar" maxlength="250" placeholder="max. 250 Zeichen" style="margin:0px"></td>';
        echo '  </tr>';
        echo '  </tbody>';
        echo '</table>';
        echo '<input type="submit" id="submit" name="submit" value="Wunsch eintragen">';
        echo '</form>';
        /* ENDE - Eintrag Formular */

        /* START - Eintrag Tabelle */
        echo '<form method="post">';
        echo '<table style="width:100%;font-size:80%">';
        echo '  <thead style="background-color:#d6d6d6">';
        echo '  <tr>';
        echo '      <th></th>';
        foreach($headers as $header) {
            echo '      <th>'.$header.'</th>';
        }
        echo '  </tr>';
        echo '  </thead>';
        echo '  <tbody>';
        foreach($entries as $entry) {
            echo '  <tr>';
            echo '      <td><input type="radio" id="deleteid" name="deleteid" value="'.$entry->id.'"></td>';
            echo '      <td>'.$entry->wunsch_date.'</td>';
            echo '      <td>'.$entry->dienstart.'</td>';
            echo '      <td>'.$entry->dienstgrund.'</td>';
            echo '      <td>'.$entry->kommentar.'</td>';
            echo '  </tr>';
        }
        echo '  </tbody>';
        echo '</table>';
        echo '<input style="background:red" type="submit" name="delete" value="Wunsch löschen">';
        echo '</form>';
        /* ENDE - Eintrag Tabelle */

        /* START seitenspezifisches JS */
        $thisyear = date('Y', strtotime('today'));
        $nextyear = date('Y', strtotime($thisyear . '+1 year'));
        $max_date = date('d.m.Y', strtotime('last day of december next year'));
        echo "<script type='text/javascript'>
            function validateForm() {
                let entry_dates = ".json_encode($entry_dates).";
                let x = document.getElementById('datetimepicker');
                if (entry_dates.includes(x.value)) {
                    swal('Dienstwunsch bereits eingetragen!', 'Lösche zuerst den bereits vorhandenen Eintrag, oder wähle ein anderes Datum. 😅', 'error');
                    return false;
                }
            }
            
            jQuery(document).ready( function () {
                
                jQuery.datetimepicker.setLocale('de');

                jQuery('#datetimepicker').datetimepicker({
                i18n:{
                de:{
                months:[
                    'Januar','Februar','März','April',
                    'Mai','Juni','Juli','August',
                    'September','Oktober','November','Dezember',
                ],
                dayOfWeek:[
                    'Mo', 'Di', 'Mi',
                    'Do', 'Fr', 'Sa', 'So'
                ]
                }
                },
                timepicker:false,
                scrollInput:false,
                scrollMonth:false,
                yearStart:".$thisyear.",
                yearEnd:".$nextyear.",
                startDate:'$start_date',
                minDate:'$start_date',
                maxDate:'$max_date',
                formatDate:'d.m.Y',
                format:'d.m.Y',
                dayOfWeekStart: 1
                });
            } );
            </script>";
        /* ENDE seitenspezifisches JS */
    } else {
        echo "<p>Einträge sind hier nur für die Benutzergruppe <u>Arzt</u> erlaubt.</p>";
    }
}

/* Hier bauen wir das Admin Menü */
function wdp_print_admin_page() {
    global $wpdb;
    /* Hier definieren wir Variablen, die wir für die Tabellen, das Formular und die javascript Konfigurationen brauchen. */
    $table_entries = $wpdb->prefix . "wdp_entries";
    $table_dienstarten = $wpdb->prefix . "wdp_dienstarten";
    $table_dienstgruende = $wpdb->prefix . "wdp_dienstgruende";
    $headers = array('Datum', 'Mitarbeiter', 'Dienstart', 'Dienstgrund', 'Kommentar', 'Eingetragen am');
    $all_entries = $wpdb->get_results('SELECT a.id, a.user_name, a.wunsch_date, b.dienstart, c.dienstgrund, a.kommentar, a.entry_date FROM '.$table_entries.' a, '.$table_dienstarten.' b, '.$table_dienstgruende.' c WHERE a.dienstart = b.id AND a.dienstgrund = c.id AND a.aktiv is true ORDER BY a.wunsch_date ASC');
    $dienstarten = $wpdb->get_results('SELECT * from '.$table_dienstarten);
    $dienstgruende = $wpdb->get_results('SELECT * from '.$table_dienstgruende);
    echo "<h1>Wunschdienstplan Gesamtansicht für Moderatoren</h1>";
    /* Dann lass mal die Tabelle rendern! */
    echo '<table id="wdp-table" class="display compact" style="width:100%">';
        echo '  <thead>';
        echo '  <tr>';
        foreach($headers as $header) {
            echo '      <th>'.$header.'</th>';
        }
        echo '  </tr>';
        echo '  </thead>';
        echo '  <tbody>';
        foreach($all_entries as $entry) {
            echo '  <tr>';
            echo '      <td style="white-space:nowrap">'.$entry->wunsch_date.'</td>';
            echo '      <td>'.$entry->user_name.'</td>';
            echo '      <td>'.$entry->dienstart.'</td>';
            echo '      <td>'.$entry->dienstgrund.'</td>';
            echo '      <td>'.$entry->kommentar.'</td>';
            echo '      <td>'.$entry->entry_date.'</td>';
            echo '  </tr>';
        }
        echo '  </tbody>';
        echo '</table>';
    
    /* START seitenspezifisches JS */
    echo "<script type='text/javascript'>
        jQuery(document).ready( function () {
            jQuery('#wdp-table').DataTable({
                language: {
                    'emptyTable': 'Keine Daten in der Tabelle vorhanden',
                    'info': '_START_ bis _END_ von _TOTAL_ Einträgen',
                    'infoEmpty': 'Keine Daten vorhanden',
                    'infoFiltered': '(gefiltert von _MAX_ Einträgen)',
                    'infoThousands': '.',
                    'loadingRecords': 'Wird geladen ..',
                    'processing': 'Bitte warten ..',
                    'paginate': {
                        'first': 'Erste',
                        'previous': 'Zurück',
                        'next': 'Nächste',
                        'last': 'Letzte'
                    },
                    'aria': {
                        'sortAscending': ': aktivieren, um Spalte aufsteigend zu sortieren',
                        'sortDescending': ': aktivieren, um Spalte absteigend zu sortieren'
                    },
                    'select': {
                        'rows': {
                            '_': '%d Zeilen ausgewählt',
                            '1': '1 Zeile ausgewählt'
                        },
                        'cells': {
                            '1': '1 Zelle ausgewählt',
                            '_': '%d Zellen ausgewählt'
                        },
                        'columns': {
                            '1': '1 Spalte ausgewählt',
                            '_': '%d Spalten ausgewählt'
                        }
                    },
                    'buttons': {
                        'print': 'Drucken',
                        'copy': 'Kopieren',
                        'copyTitle': 'In Zwischenablage kopieren',
                        'copySuccess': {
                            '_': '%d Zeilen kopiert',
                            '1': '1 Zeile kopiert'
                        },
                        'collection': 'Aktionen <span class=\'ui-button-icon-primary ui-icon ui-icon-triangle-1-s\'><\/span>',
                        'colvis': 'Spaltensichtbarkeit',
                        'colvisRestore': 'Sichtbarkeit wiederherstellen',
                        'copyKeys': 'Drücken Sie die Taste <i>ctrl<\/i> oder <i>⌘<\/i> + <i>C<\/i> um die Tabelle<br \/>in den Zwischenspeicher zu kopieren.<br \/><br \/>Um den Vorgang abzubrechen, klicken Sie die Nachricht an oder drücken Sie auf Escape.',
                        'csv': 'CSV',
                        'excel': 'Excel',
                        'pageLength': {
                            '-1': 'Alle Zeilen anzeigen',
                            '_': '%d Zeilen anzeigen',
                            '1': 'Zeige 1 Zeile'
                        },
                        'pdf': 'PDF',
                        'createState': 'Ansicht erstellen',
                        'removeAllStates': 'Alle Ansichten entfernen',
                        'removeState': 'Entfernen',
                        'renameState': 'Umbenennen',
                        'savedStates': 'Gespeicherte Ansicht',
                        'stateRestore': 'Ansicht %d',
                        'updateState': 'Aktualisieren'
                    },
                    'autoFill': {
                        'cancel': 'Abbrechen',
                        'fill': 'Alle Zellen mit <i>%d<i> füllen<\/i><\/i>',
                        'fillHorizontal': 'Alle horizontalen Zellen füllen',
                        'fillVertical': 'Alle vertikalen Zellen füllen'
                    },
                    'decimal': ',',
                    'search': 'Suche:',
                    'searchBuilder': {
                        'add': 'Bedingung hinzufügen',
                        'button': {
                            '0': 'Such-Baukasten',
                            '_': 'Such-Baukasten (%d)'
                        },
                        'condition': 'Bedingung',
                        'conditions': {
                            'date': {
                                'after': 'Nach',
                                'before': 'Vor',
                                'between': 'Zwischen',
                                'empty': 'Leer',
                                'not': 'Nicht',
                                'notBetween': 'Nicht zwischen',
                                'notEmpty': 'Nicht leer',
                                'equals': 'Gleich'
                            },
                            'number': {
                                'between': 'Zwischen',
                                'empty': 'Leer',
                                'equals': 'Entspricht',
                                'gt': 'Größer als',
                                'gte': 'Größer als oder gleich',
                                'lt': 'Kleiner als',
                                'lte': 'Kleiner als oder gleich',
                                'not': 'Nicht',
                                'notBetween': 'Nicht zwischen',
                                'notEmpty': 'Nicht leer'
                            },
                            'string': {
                                'contains': 'Beinhaltet',
                                'empty': 'Leer',
                                'endsWith': 'Endet mit',
                                'equals': 'Entspricht',
                                'not': 'Nicht',
                                'notEmpty': 'Nicht leer',
                                'startsWith': 'Startet mit',
                                'notContains': 'enthält nicht',
                                'notStartsWith': 'startet nicht mit',
                                'notEndsWith': 'endet nicht mit'
                            },
                            'array': {
                                'equals': 'ist gleich',
                                'empty': 'ist leer',
                                'contains': 'enthält',
                                'not': 'ist ungleich',
                                'notEmpty': 'ist nicht leer',
                                'without': 'aber nicht'
                            }
                        },
                        'data': 'Daten',
                        'deleteTitle': 'Filterregel entfernen',
                        'leftTitle': 'Äußere Kriterien',
                        'logicAnd': 'UND',
                        'logicOr': 'ODER',
                        'rightTitle': 'Innere Kriterien',
                        'title': {
                            '0': 'Such-Baukasten',
                            '_': 'Such-Baukasten (%d)'
                        },
                        'value': 'Wert',
                        'clearAll': 'Alle löschen'
                    },
                    'searchPanes': {
                        'clearMessage': 'Leeren',
                        'collapse': {
                            '0': 'Suchmasken',
                            '_': 'Suchmasken (%d)'
                        },
                        'countFiltered': '{shown} ({total})',
                        'emptyPanes': 'Keine Suchmasken',
                        'loadMessage': 'Lade Suchmasken..',
                        'title': 'Aktive Filter: %d',
                        'showMessage': 'zeige Alle',
                        'collapseMessage': 'Alle einklappen',
                        'count': '{total}'
                    },
                    'thousands': '.',
                    'zeroRecords': 'Keine passenden Einträge gefunden',
                    'lengthMenu': '_MENU_ Zeilen anzeigen',
                    'datetime': {
                        'previous': 'Vorher',
                        'next': 'Nachher',
                        'hours': 'Stunden',
                        'minutes': 'Minuten',
                        'seconds': 'Sekunden',
                        'unknown': 'Unbekannt',
                        'weekdays': [
                            'Sonntag',
                            'Montag',
                            'Dienstag',
                            'Mittwoch',
                            'Donnerstag',
                            'Freitag',
                            'Samstag'
                        ],
                        'months': [
                            'Januar',
                            'Februar',
                            'März',
                            'April',
                            'Mai',
                            'Juni',
                            'Juli',
                            'August',
                            'September',
                            'Oktober',
                            'November',
                            'Dezember'
                        ]
                    },
                    'editor': {
                        'close': 'Schließen',
                        'create': {
                            'button': 'Neu',
                            'title': 'Neuen Eintrag erstellen',
                            'submit': 'Neu'
                        },
                        'edit': {
                            'button': 'Ändern',
                            'title': 'Eintrag ändern',
                            'submit': 'ändern'
                        },
                        'remove': {
                            'button': 'Löschen',
                            'title': 'Löschen',
                            'submit': 'Löschen',
                            'confirm': {
                                '_': 'Sollen %d Zeilen gelöscht werden?',
                                '1': 'Soll diese Zeile gelöscht werden?'
                            }
                        },
                        'error': {
                            'system': 'Ein Systemfehler ist aufgetreten'
                        },
                        'multi': {
                            'title': 'Mehrere Werte',
                            'info': 'Die ausgewählten Elemente enthalten mehrere Werte für dieses Feld. Um alle Elemente für dieses Feld zu bearbeiten und auf denselben Wert zu setzen, klicken oder tippen Sie hier, andernfalls behalten diese ihre individuellen Werte bei.',
                            'restore': 'Änderungen zurücksetzen',
                            'noMulti': 'Dieses Feld kann nur einzeln bearbeitet werden, nicht als Teil einer Mengen-Änderung.'
                        }
                    },
                    'searchPlaceholder': 'Suchen...',
                    'stateRestore': {
                        'creationModal': {
                            'button': 'Erstellen',
                            'columns': {
                                'search': 'Spalten Suche',
                                'visible': 'Spalten Sichtbarkeit'
                            },
                            'name': 'Name:',
                            'order': 'Sortieren',
                            'paging': 'Seiten',
                            'scroller': 'Scroll Position',
                            'search': 'Suche',
                            'searchBuilder': 'Such-Baukasten',
                            'select': 'Auswahl',
                            'title': 'Neue Ansicht erstellen',
                            'toggleLabel': 'Inkludiert:'
                        },
                        'duplicateError': 'Eine Ansicht mit diesem Namen existiert bereits.',
                        'emptyError': 'Name darf nicht leer sein.',
                        'emptyStates': 'Keine gespeicherten Ansichten',
                        'removeConfirm': 'Bist du dir sicher, dass du %s entfernen möchtest?',
                        'removeError': 'Entfernen der Ansicht fehlgeschlagen.',
                        'removeJoiner': ' und ',
                        'removeSubmit': 'Entfernen',
                        'removeTitle': 'Ansicht entfernen',
                        'renameButton': 'Umbenennen',
                        'renameLabel': 'Neuer Name für %s:',
                        'renameTitle': 'Ansicht umbenennen'
                    }
                },
                paging: false,
                searching: true,
                ordering: true
            });
        } );
        </script>";
    /* ENDE seitenspezifisches JS */

    
}

add_action( 'admin_menu', 'wdp_admin_page' );
function wdp_admin_page() {
    add_menu_page(
        'WDP',
        'WDP',
        'wdp_admin', // Diese capability braucht der user, um die Page im Backend sehen zu können!
        'wdp_admin',
        'wdp_print_admin_page',
        'dashicons-list-view',
        20
    );
}

add_shortcode('wdp', 'wdp_print_user_table' );

?>

