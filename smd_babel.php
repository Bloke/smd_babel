<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'smd_babel';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '1.0.0';
$plugin['author'] = 'Stef Dawson';
$plugin['author_uri'] = 'https://stefdawson.com/';
$plugin['description'] = 'Manage language translation strings from the Textpattern admin panel';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '4';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '0';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

$plugin['textpack'] = <<<EOT
#@language en, en-ca, en-gb, en-us
#@admin-side
smd_babel => Translations
#@smd_babel
smd_babel_add_string => Add translation string
smd_babel_export_strings => Export strings
smd_babel_group => Group
smd_babel_key => Key
smd_babel_lang => Language
smd_babel_lang_site => Site language
smd_babel_lang_ui => Admin language
smd_babel_lang_xlate => Translated language
smd_babel_language_not_installed => Language <strong>{name}</strong> not installed.
smd_babel_string_deleted => String <strong>{name}</strong> deleted.
smd_babel_string_updated => String <strong>{name}</strong> updated.
smd_babel_value => Translation string
tab_smd_babel => Translations
EOT;

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
/**
 * smd_babel
 *
 * A Textpattern CMS plugin for managing language strings translations.
 *
 * @author Stef Dawson
 * @link   https://stefdawson.com/
 */
if (txpinterface === 'admin') {
    new smd_babel();
}

/**
 * Admin-side user interface.
 */
class smd_babel
{
    /**
     * The plugin's event as registered in Txp.
     *
     * @var string
     */
    protected $event = 'smd_babel';

    /**
     * Constructor to set up callbacks and environment.
     */
    public function __construct()
    {
        add_privs($this->event, '1,2');
        register_tab('admin', $this->event, gTxt('smd_babel'));
        register_callback(array($this, 'smd_babel'), $this->event);
        register_callback(array($this, 'inject_css'), 'admin_side', 'head_end');
    }

    /**
     * Plugin jumpoff point.
     *
     * @param  string $evt Textpattern event
     * @param  string $stp Textpattern step (action)
     */
    public function smd_babel($evt, $stp)
    {
        $available_steps = array(
            'ui'         => false,
            'fetchGroup' => true,
            'save'       => true,
            'delete'     => true,
            'export'     => true,
        );

        if (!$stp or !bouncer($stp, $available_steps)) {
            $stp = 'ui';
        }

        $this->$stp();
    }

    /**
     * Inject style rules into the &lt;head&gt; of the page.
     *
     * @param  string $evt Textpattern event (panel)
     * @param  string $stp Textpattern step (action)
     * @return string      Style rules, or nothing if not the correct $event
     */
    public function inject_css($evt, $stp)
    {
        global $event;

        if ($event === $this->event) {
            $smd_babel_styles = <<<EOCSS
.smd_babel_string { width: 100%; }
.smd_babel_panel { float: right; }
.smd_babel_delete { cursor: pointer; }
textarea.smd_babel_string { min-height: auto; height: auto; }
EOCSS;

            echo '<style type="text/css">' . $smd_babel_styles . '</style>';
        }

        return;
    }

    /**
     * Table of language strings by group.
     *
     * @param  string $message Flash message to display success/error
     * @return string          HTML
     */
    public function ui($message = '')
    {
        pagetop(gTxt('smd_babel'), $message);
        require_privs('smd_babel');

        $groups = safe_column('event', 'txp_lang', '1 GROUP BY event');
        $langObj = Txp::get('\Textpattern\L10n\Lang');
        $defaultOwner = TEXTPATTERN_LANG_OWNER_SITE;

        $activeLang = $langObj->available(TEXTPATTERN_LANG_ACTIVE);
        $activeIdentifier = key($activeLang);

        $siteLang = get_pref('language', TEXTPATTERN_DEFAULT_LANG, true);
        $uiLang = get_pref('language_ui', $siteLang, true);
        $selectedLang = get_pref('smd_babel_lang', $activeIdentifier);
        $baseLang = $siteLang;

        $primary = $langObj->languageSelect('smd_babel_lang_xlate', $selectedLang);
        $installed = (method_exists($langObj, 'languageList')) ? $langObj->languageList() : $this->languageList();

        $showUI = ($uiLang !== $baseLang);
        $uiSelect = hInput('smd_babel_lang_ui', $uiLang);
        $baseSelect = hInput('smd_babel_lang_site', $baseLang);

        $group = get_pref('smd_babel_group', 'admin');
        $groups = selectInput('smd_babel_group', $groups, $group);

        $searchBlock = n.tag(
            '<div class="smd_babel_panel">
                <a class="txp-button smd_babel_add" href="#">' . gTxt('add') . '</a>
                <a class="txp-button smd_babel_export" href="#">' . gTxt('export') . '</a>
            </div>'.n.
            tag(
                form(
                    inputLabel('key', fInput('text', array('name' => 'key', 'required' => 1), ''), 'smd_babel_key').
                    inputLabel('group', fInput('text', array('name' => 'group', 'required' => 1), $group), 'smd_babel_group').
                    inputLabel('lang', selectInput('lang', $installed, $selectedLang, false), 'smd_babel_lang').
                    inputLabel('value', fInput('text', array('name' => 'value', 'required' => 1), ''), 'smd_babel_value').
                    fInput('submit', 'smd_babel_submit', gTxt('save'))
                    .eInput($this->event)
                    .sInput('save'),
                    '',
                    '',
                    'post',
                    'async'
                ),
                'div', array(
                    'class'      => 'smd_babel_addform',
                    'aria-label' => gTxt('smd_babel_add_string'),
                    'title'      => gTxt('smd_babel_add_string'),
                )).n.
            tag(
                form(
                    inputLabel('group', fInput('text', array('name' => 'group'), $group), 'smd_babel_group').
                    inputLabel('lang', selectInput('lang', $installed, $selectedLang, false), 'smd_babel_lang').
                    inputLabel('key', fInput('text', array('name' => 'key'), ''), 'smd_babel_key').
                    fInput('submit', 'smd_babel_submit', gTxt('download'))
                    .eInput($this->event)
                    .sInput('export'),
                    '',
                    '',
                    'post'
                ),
                'div', array(
                    'class'      => 'smd_babel_exportform',
                    'aria-label' => gTxt('smd_babel_export_strings'),
                    'title'      => gTxt('smd_babel_export_strings'),
                )),
            'div', array(
                'class' => 'txp-layout-4col-3span',
                'id'    => $this->event.'_control',
            )
        );

        $pageBlock = '';
        $total = 1;
        $criteria = '';

        // Three columns:
        // 1) Keys and their base language translations.
        // 2) Primary (current admin language) translations in 2nd column if base not
        //    in use on admin side.
        // 3) Translation column with lang selector at top to load the strings from that
        //    lang corresponding to the currently selected group (event). Editable.
        $createBlock = tag($groups, 'div', array('class' => 'txp-control-panel'));
        $contentBlock = tag_start('div', array('class' => 'txp-listtables')).
                n.tag_start('table', array('class' => 'txp-list')).
                n.tag_start('thead').
                tr(
                    hCell(gTxt('smd_babel_lang_site', array('{lang}' => $baseLang)).n.$baseSelect, null, array('class' => 'langCol '.$baseLang)).
                    (
                        ($showUI)
                        ? hCell(gTxt('smd_babel_lang_ui', array('{lang}' => $uiLang)).n.$uiSelect, null, array('class' => 'langCol '.$uiLang))
                        : ''
                    ).
                    hCell(gTxt('smd_babel_lang_xlate').n.$primary, null, array('class' => 'langCol', 'width' => '40%'))
                ).
                n.tag_end('thead').
                n.tag_start('tbody', array('class' => 'smd_babel_table')).
                n.tag_end('tbody').
                n.tag_end('table').
                n.tag_end('div');

        $table = new \Textpattern\Admin\Table($this->event);
        echo $table->render(compact('total', 'criteria'), $searchBlock, $createBlock, $contentBlock, $pageBlock);

        echo script_js(<<<EOJS
jQuery(function() {
    /**
     * Group change handler.
     */
    jQuery('select[name=smd_babel_group]').on('change', smd_babel_rebuild_table).change();

    /**
     * Language change handler.
     */
    jQuery('select[name=smd_babel_lang_xlate]').on('change', smd_babel_rebuild_table).change();

    /**
     * Add button handlers.
     */
    $(document).on('click', '.smd_babel_add', function (ev) {
        ev.preventDefault();
        $('.smd_babel_addform').dialog('open');
    });

    jQuery('.smd_babel_addform, .smd_babel_exportform').dialog({
        dialogClass: 'txp-tagbuilder-container',
        autoOpen: false,
        focus: function (ev, ui) {
            $(ev.target).closest('.ui-dialog').focus();
        }
    });

    /**
     * Export button handlers.
     */
    $(document).on('click', '.smd_babel_export', function (ev) {
        ev.preventDefault();
        $('.smd_babel_exportform').dialog('open');
    });

    /**
     * Store the given string in the given language when input changes.
     */
    jQuery('.smd_babel_table').on('change', 'textarea', function() {
        var me = jQuery(this);
        var key = me.attr('name');
        var lng = me.data('lang');
        var val = me.val();
        var grp = jQuery('select[name=smd_babel_group]').val();

        sendAsyncEvent(
        {
            event: textpattern.event,
            step: 'save',
            key: key,
            lang: lng,
            group: grp,
            value: val
        }, function (data) {
            textpattern.Console.addMessage([data.msg, 0], 'smd_babel').announce('smd_babel');
        },
        'json');
    });

    /**
     * Delete the given string in the given language.
     */
    jQuery('.smd_babel_table').on('click', '.smd_babel_delete', function(ev) {
        ev.preventDefault();
        var me = jQuery(this);
        var key = me.data('key');
        var lng = jQuery('select[name=smd_babel_lang_xlate]').val();
        var grp = jQuery('select[name=smd_babel_group]').val();

        sendAsyncEvent(
        {
            event: textpattern.event,
            step: 'delete',
            key: key,
            lang: lng,
            group: grp
        }, function (data) {
            textpattern.Console.addMessage([data.msg, 0], 'smd_babel').announce('smd_babel');
            me.closest('tr').remove();
        },
        'json');
    });

    /**
     * Fetch new strings and reconstruct the main table body.
     */
    function smd_babel_rebuild_table() {
        var grp = jQuery('select[name=smd_babel_group]').val();
        var langs = [];

        jQuery('.langCol').each(function() {
            langs.push(jQuery(this).find('input, select').val());
        });

        sendAsyncEvent(
        {
            event: textpattern.event,
            step: 'fetchGroup',
            group: grp,
            langs: langs
        }, function (data) {
            var keys = [];
            var owners = [];
            var strings = [];

            // Loop through the languages and extract matching values with translations.
            jQuery.each(langs, function(idx, lng) {
                strings[lng] = [];

                jQuery.each(data[lng], function(key, str) {
                    // Assume base language contains all keys: faulty assumption in
                    // some cases but there's no guarantee English is installed.
                    if (idx === 0) {
                        keys.push(key);
                    }

                    owners.push(str.owner);

                    // Guard against languages with missing keys.
                    if (keys.includes(key)) {
                        strings[lng].push(str.data);
                    } else {
                        strings[lng].push('');
                    }
                });
            });

            // Reconstruct the table.
            var tbl = jQuery('.smd_babel_table');
            tbl.empty();
            var selectedLang = '';

            jQuery.each(keys, function(idx, key) {
                var row = [];
                var maxLangs = langs.length - 1;
                var link = '';

                var canDelete = (owners[idx] === '{$defaultOwner}');

                jQuery.each(langs, function(jdx, lng) {
                    if (jdx === maxLangs) {
                        // Todo: escape strings in case they contain double quotes.
                        selectedLang = lng;
                        link = '<textarea name="'+key+'" class="smd_babel_string" data-lang="'+lng+'">'+strings[lng][idx]+'</textarea>';
                    } else {
                        link = (jdx === 0 && (canDelete ? '<a class="smd_babel_delete ui-icon ui-icon-close" data-key="'+key+'">x</a>': '')) + strings[lng][idx] + ((jdx === 0) ? '<br/><span class="txp-form-field-instructions">' + key + '</span>': '');
                    }
                    row.push('<td>'+ link + '</td>');
                });

                tbl.append('<tr>'+row.join(' ')+'</tr>');
            });

            // Resync the language and group selectors in the Add form in case they've changed.
            jQuery('.smd_babel_addform, .smd_babel_exportform').find('[name=lang]').val(selectedLang).prop('selected', true);
            jQuery('.smd_babel_addform, .smd_babel_exportform').find('[name=group]').val(grp);
        },
        'json');
    }
})
EOJS
        );
    }

    /**
     * Ajax: Fetch all Textpack strings from the given group.
     *
     * Requires POST variables:
     *  param  string group The language group (event)
     *  param  array  langs The language (refs) to fetch
     * @return array        JSON response
     */
    public function fetchGroup()
    {
        $grp = doSlash(ps('group'));
        $lng = doSlash(ps('langs'));

        if (!$lng) {
            $lng = TEXTPATTERN_DEFAULT_LANG;
        }

        $lng = (array) $lng;
        $last_lang = end($lng);

        $lang_list = "lang IN (".implode(',', quote_list($lng)).")";

        $rs = safe_rows('name, lang, data, owner', 'txp_lang', "event='$grp' AND $lang_list ORDER BY name");
        $out = array();

        foreach ($rs as $row) {
            $out[$row['lang']][$row['name']] = array('data' => $row['data'], 'owner' => $row['owner']);
        }

        set_pref('smd_babel_group', $grp, 'smd_babel', PREF_HIDDEN, '', 0, PREF_PRIVATE);
        set_pref('smd_babel_lang', $last_lang, 'smd_babel', PREF_HIDDEN, '', 0, PREF_PRIVATE);

        echo json_encode($out);
    }

    /**
     * Ajax: Save (overwrite) the given string with the new translation.
     *
     * Requires POST variables:
     *  param  string key   The key name to change
     *  param  string lang  The language (ref) to alter
     *  param  string group The language group (event)
     *  param  string value The new string value
     * @return array        JSON response
     */
    public function save()
    {
        $key = ps('key');
        $lng = ps('lang');
        $grp = ps('group');
        $val = ps('value');

        $langObj = Txp::get('\Textpattern\L10n\Lang');
        $installed = $langObj->installed();
        $msg = '';

        if (!in_array($lng, $installed)) {
            $msg = gTxt('smd_babel_language_not_installed', array('{name}' => $lng));
        } else {
            $ret = safe_upsert(
                'txp_lang',
                array('data' => $val, 'event' => $grp, 'lastmod' => 'NOW()', 'owner' => TEXTPATTERN_LANG_OWNER_SITE),
                array('name' => $key, 'lang' => $lng)
            );

            $this->syncKeys($key, $grp, $lng);

            if ($ret) {
                $msg = gTxt('smd_babel_string_updated', array('{name}' => $key));
            }
        }

        // @todo Figure out how to make the message appear.
        echo json_encode(array('msg' => $msg));

        return;
    }

    /**
     * Ajax: Delete a string if it exists and is non-core.
     *
     * Requires POST variables:
     *  param  string key   The key name to change
     *  param  string lang  The language (ref) to alter
     *  param  string group The language group (event)
     * @return array        JSON response
     */
    public function delete()
    {
        $msg = '';
        $lng = doSlash(ps('lang'));
        $grp = doSlash(ps('group'));
        $key = doSlash(ps('key'));

        $exists = safe_field('name', 'txp_lang', "name='$key' AND lang='$lng' AND event='$grp' AND owner='" . TEXTPATTERN_LANG_OWNER_SITE  . "'");

        if ($exists) {
            $safe_exists = doSlash($exists);
            $done = safe_delete('txp_lang', "name='$safe_exists' AND lang='$lng' AND event='$grp' AND owner='" . TEXTPATTERN_LANG_OWNER_SITE  . "'");

            if ($done) {
                $msg = gTxt('smd_babel_string_deleted', array('{name}' => $exists));
            }
        }

        echo json_encode(array('msg' => $msg));

        return;
    }

    /**
     * Ajax: Export a string set as a file.
     *
     * Available POST variables:
     *  param  string lang  The language (ref) to export
     *  param  string group The group (event) to export. Empty = all
     *  param  string key   The partial key (name) to match. Empty = ignored
     * 
     * @return string
     */
    public function export()
    {
        $lng = ps('lang');
        $grp = ps('group');
        $key = ps('key');

        $grpClause = ($grp) ? " AND event IN (".implode(',', quote_list(do_list($grp))).")" : '';
        $keyClause = ($key) ? " AND name LIKE '%" . doSlash($key) . "%'" : '';
        $rs = safe_rows('event, name, data', 'txp_lang', "lang='".doSlash($lng)."'".$grpClause.$keyClause." ORDER BY event, name");

        $out = $this->createIni($rs);

        set_headers(array(
            'content-type' => 'text/plain',
            'content-disposition' => 'attachment; filename="'.$lng.'.ini"',
            'content-description' => 'File Download',
            'content-length' => count($out),
            // Fix for IE6 PDF bug on servers configured to send cache headers.
            'cache-control' => 'private'
        ));

        echo implode(n, $out);

        exit;
    }

    /**
     * Ensure any new keys are represented in all languages.
     *
     * @param string $key Key to check
     * @param string $grp Group (event) in which the key belongs
     * @param string $lng Language that's been saved already
     */
    protected function syncKeys($key, $grp, $lng)
    {
        $langObj = Txp::get('\Textpattern\L10n\Lang');
        $installed = $langObj->installed();

        foreach ($installed as $lang) {
            if ($lang === $lng) {
                continue;
            }

            safe_upsert(
                'txp_lang',
                array('event' => $grp, 'lastmod' => 'NOW()', 'owner' => TEXTPATTERN_LANG_OWNER_SITE),
                array('name' => $key, 'lang' => $lang)
            );
        }

        return;
    }

    /**
     * Convert an array into .ini language file format.
     *
     * @param  array  $rs    The record set to store
     * @todo   Come the revolution, put something like this in Textpack\Parser.php?
     * @return [type]        [description]
     */
    protected function createIni($rs)
    {
        $res = array();
        $lastGrp = '';

        foreach ($rs as $row) {
            if (is_array($row)) {
                $grp = $row['event'];

                if ($grp != $lastGrp) {
                    $res[] = "[$grp]";
                }

                $name = $row['name'];
                $data = $row['data'];

                $res[] = "$name = \"".$data."\"";
                $lastGrp = $grp;
            }
        }

        return $res;
    }

    /**
     * Return a list of available lanugages in Txp.
     *
     * Polyfill for Txp < 4.7.3.
     *
     * @param  int    $flags Logical OR list of flags indiacting the type of list to return:
     *                       TEXTPATTERN_LANG_ACTIVE: the active language
     *                       TEXTPATTERN_LANG_INSTALLED: all installed languages
     *                       TEXTPATTERN_LANG_AVAILABLE: all available languages in the file system
     * @return array
     */
    protected function languageList($flags = null)
    {
        if ($flags === null) {
            $flags = TEXTPATTERN_LANG_ACTIVE | TEXTPATTERN_LANG_INSTALLED;
        }

        $installed_langs = Txp::get('\Textpattern\L10n\Lang')->available((int)$flags);
        $vals = array();

        foreach ($installed_langs as $lang => $langdata) {
            $vals[$lang] = $langdata['name'];

            if (trim($vals[$lang]) == '') {
                $vals[$lang] = $lang;
            }
        }

        ksort($vals);
        reset($vals);

        return $vals;
    }
}

# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1. smd_babel

Managing translation strings in Textpattern has traditionally meant going into the database and fiddling with content there. This is no longer the case. Welcome... smd_babel.

h2. Installation

"Download a copy":https:.//github.com/bloke/smd_babel/releases of the .txt file, visit your Textpattern _Admin->Plugins_ panel and paste the contents into the Install plugin box. Submit and verify the plugin, then simply enable it by tapping the No/Yes toggle alongside the plugin's name.

h2. Usage

When the plugin is active, visit the _Admin->Translations_ panel. You will see:

h3. Group selector

All strings are separated into groups (a.k.a events). For core strings, these usually equate to the panel names, e.g. 'image' for the strings that are used on the Images panel, 'admin' for those that appear on the Users panel, and so on.

Plugins normally group strings by their plugin name. There are three special group

# @admin-side@: Strings in this group are available across the _entire_ administration side. Usual things here are menu styrings, or items that are used on more than one panel.
# @public@: Strings that are only loaded on the public site.
# @common@: Strings that are common to both the entire admin-side AND are loaded on the public website.

When choosing a group, use the most appropriate group for the task. Don't just assign everything to @common@ because that bulks up the amount of data sent on every page request, which slows down the website.

Selecting a group from the dropdown list will load strings from that group into the remainder of the panel.

h3. Translation table

This is where translation takes place, and it's sub-divided in up to three columns:

# Site language: your default site language for the website is shown in the first column. This includes all translations for the keys in that group, and it also shows beneath each translation the key itself (for reference).
# Admin language: if your administration site language differs for your public website, the central column will appear that shows you translations for the currently selected group in your native amdin language.
# Translation language: the final column is selectable. At the top is a language dropdown. Choose a language to load all the strings for that language into this column. Make amendments to any strings in the textareas. Simply altering the content and tabbing out of the box will commit the changes to the database for that string immediately. Be careful!

You can switch language in the third column, or change groups at any time to update whichever strings are already present across the entire system.

If you wish to delete a string, click its corresponding 'x' icon in the first column. No warning is given, so be sure. Note that you cannot delete core strings.

h3. Add button

If you wish to add a new string, you may do so by clicking the _Add_ button in the top right of the panel. A popup box will appear permitting you to enter/select:

* The new key name. This must be unique. If you type in a key that already exists, its contents will be overwritten without warning!
* The group in which the key will appear. Default is the current group in which you are working.
* The language in which the new key will appear. Default is the current language that you are translating.
* The new string value: its translation.

Once done, click the _Save_ button. Your string will be added to the database. Note that it won't appear in the table until you refresh the panel. This permits you to rapidly add more than one string in succession by just varying the key and translation.

h3. Export button

To download the strings into a file, click the _Export_ button. A popup box will permit you to choose:

* The group of strings you wish to download. Specify a comma-separated list of groups here, or empty the box if you wish to download all strings.
* The language of the strings you wish to export.
* A string (key) match.

Once you have selected the criteria, click _Download_ to save the file to your computer.

For example, if you wished to download all strings used by this plugin you would supply:

* Group: @smd_babel, admin-side@
* Lang: @English@
* Key: @smd_babel@

That ensures you only get the keys matching @smd_babel@ from the admin-side group, otherwise you'd get all of the strings from this group - core and other plugins included.

# --- END PLUGIN HELP ---
-->
<?php
}
?>