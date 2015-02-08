<?php
/*
Plugin Name: Gravity Forms Contact Form 7 Importer
Plugin URI: http://www.katzwebservices.com
Description: Import your existing Contact Form 7 forms into <a href="http://wordpressformplugin.com?r=gfcf7">Gravity Forms</a>.
Author: Katz Web Services, Inc.
Version: 1.0.2
Author URI: http://www.katzwebservices.com

------------------------------------------------------------------------
Copyright 2011 Katz Web Services, Inc.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA

*/

add_action( 'plugins_loaded', 'gfcf7_init' );

function gfcf7_init() {
	if(is_admin()) {
		global $GFCF7_Import;
		$GFCF7_Import = new GFCF7_Import();
	}
}

class GFCF7_Import {

	var $posts = array ();
	var $file;

	function header() { ?>
		<div class="wrap">
		<img alt="<?php _e("Gravity Forms", "gfcf7") ?>" src="<?php echo self::get_base_url()?>/images/gravity-import-icon-32.png" style="float:left; margin:15px 7px 0 0;" width="36" height="35"/>
		<h2><?php _e('Import a Contact Form 7 Form to Gravity Forms'); ?></h2>
		<div class="clear"></div>
	<?php
	}

	function footer() {
		echo '</div>';
	}

	function unhtmlentities($string) { // From php.net for < 4.3 compat
		$trans_tbl = get_html_translation_table(HTML_ENTITIES);
		$trans_tbl = array_flip($trans_tbl);
		return strtr($string, $trans_tbl);
	}

	//Returns the url of the plugin's root folder
    protected function get_base_url(){
        return plugins_url(null, __FILE__);
    }

	function greet() {
			self::kwd_import_upload_form(add_query_arg(array('step' => 1)));
	}

	function get_settings($field) {
		$values = '';
		foreach ( $field['options'] as $key => $setting ) {
			$option = explode(':', $setting);

			if(!isset($field["{$option[0]}"])) {

				if(preg_match('/([0-9]+)?\/([0-9]+)?/',$option[0])) {
					$maxes = explode('/', $option[0]);
					if(isset($maxes[0])) {
						$field['options']['size'] = $maxes[0];
						$field['options']['maxLength'] = $maxes[1];
					}
				} else {
					$field['options']["{$option[0]}"] = isset($option[1]) ? $option[1] : true;
				}
				unset($field['options']["{$key}"]);
			}
		}

		if(isset($field['options']["default"])) {
			$field['options']["default"] = explode('_', $field['options']["default"]);
		}

		return $field;
	}

	function update_field_types_and_ids($field, $id) {
		switch($field['type']) {
			// Names
			case 'prefix': 		$field['type'] = 'name'; 		$useid = $id.'.2'; break;
			case 'firstname': 	$field['type'] = 'name'; 		$useid = $id.'.3'; break;
			case 'lastname': 	$field['type'] = 'name'; 		$useid = $id.'.6'; break;
			case 'suffix': 		$field['type'] = 'name'; 		$useid = $id.'.8'; break;

			// Address
			case 'street': 		$field['type'] = 'address';		$useid = $id.'.1'; break;
			case 'street2': 	$field['type'] = 'address';		$useid = $id.'.2'; break;
			case 'city': 		$field['type'] = 'address';		$useid = $id.'.3'; break;
			case 'state': 		$field['type'] = 'address';		$useid = $id.'.4'; break;
			case 'zip': 		$field['type'] = 'address';		$useid = $id.'.5'; break;
			case 'country': 	$field['type'] = 'address';		$useid = $id.'.6'; break;
			default: 			$useid = $id; break;
		}
		return array($field, $useid);
	}

	function getLabel($label) {
		if(isset($label['parsedLabelBefore']) && !empty($label['parsedLabelBefore'])) {
			return trim(rtrim($label['parsedLabelBefore']));
		} elseif(isset($field['labels'][0])) {
			return trim(rtrim($field['labels'][0]));
		}
		return '';
	}

	function get_forms() {
		global $wpdb, $wpcf7_shortcode_manager, $GFCF7_Import;

		if(!function_exists('wpcf7_contact_form')) {
			echo '<div class="error" id="message">';
			_e(wpautop('You need to have Contact Form 7 installed and activated for this importer to work.'));
			echo '</div>';
			return false;
		}

		$GFCF7_Import->form = $form = wpcf7_contact_form($_POST['cf7form']);

		$parselabels = isset($_POST['parselabels']);
		$combinefields = isset($_POST['combinefields']);

		#$form->notification = $form->mail;
			$form->notification['to'] = $form->mail['recipient'];
			$form->notification['message'] = $form->mail['body'];
			$form->notification['subject'] = $form->mail['subject'];
			$form->notification['from'] = $form->mail['sender'];
			$form->notification['disableAutoformat'] = !empty($form->mail['use_html']);

		#$form->autoResponder = $form->mail_2;
			$form->autoResponder['toField'] = $form->mail_2['recipient'];
			$form->autoResponder['message'] = $form->mail_2['body'];
			$form->autoResponder['subject'] = $form->mail_2['subject'];
			$form->autoResponder['from'] = $form->mail_2['sender'];
			$form->autoResponder['disableAutoformat'] = !empty($form->mail_2['use_html']);

		$WPCF7 = new WPCF7_ContactForm();
		$form->fields = $wpcf7_shortcode_manager->scan_shortcode($form->form);

		$form->formCode = $form->form;

		foreach($form->fields as $key => $field) {
			if(strpos($field['type'], '*')) {
				$form->fields["{$key}"]['isRequired'] = 1;
			} else {
				$form->fields["{$key}"]['isRequired'] = false;
			}

			$field['type'] = str_replace('*', '', $field['type']);
			$field['name'] = isset($field['name']) ? $field['name'] : '';
#			echo $field['type'].'<br />';
			$field['originalType'] = $field['type'];
			$field['type'] = self::get_field_type($field['type'], $field['name']);
#			echo $form->fields["{$key}"]['type'].'<br />';
			$field['defaultValue'] = (isset($field['values']) && isset($field['values'][0])) ? $field['values'][0] : false;

			$form->fields["{$key}"] = self::get_settings($field);

			unset($form->fields["{$key}"]['pipes']);

			if($form->fields["{$key}"]['type'] == 'submit') {
				$form->buttonText = $field['values'][0];
			}

			if($parselabels) {
				$regex = '/((.*)(?:\s+)?\[(?:.*?)'.$field['name'].'(?:.*?)\](.*))/im';
				preg_match($regex, $form->formCode, $matches);
				if(!empty($matches)) {
					$form->fields["{$key}"]['parsedLabelBefore'] = strip_tags($matches[2], '<b><strong><em><i><span><u>');
					$form->fields["{$key}"]['parsedLabelAfter'] = strip_tags($matches[3], '<b><strong><em><i><span><u>');
					$form->formCode = preg_replace($regex, '', $form->formCode);
				}
			}
		}

		unset($form->mail, $form->mail_2);

		#print_r($form);

$xml = <<<EOD
<?xml version="1.0" encoding="UTF-8"?>
<forms version="1.5.2.8">
	<form labelPlacement="top_label" useCurrentUserAsAuthor="1">
		<title><![CDATA[{$form->title}]]></title>
		<description><![CDATA[]]></description>
		<confirmation type="message">
			<message><![CDATA[{$form->messages['mail_sent_ok']}]]></message>
		</confirmation>
		<button type="text">
			<text><![CDATA[{$form->buttonText}]]></text>
		</button>
		<fields>
EOD;
		$xml .= "\n\t\t"; $id = 1;
		$form->addresses = $form->names = 0;
		foreach($form->fields as $field) {

			list($field, $useid) = self::update_field_types_and_ids($field, $id);

			if($field['type'] == 'address') {
				$form->addresses++;
				if($combinefields && $form->addresses > 1) { continue; }
			}
			if($field['type'] == 'name') {
				$form->names++;
				if($combinefields && $form->names > 1) { continue; }
			}

			$xml .= "<field id='{$useid}' size='medium' allowsPrepopulate='1'";
				if($field['type']  == 'acceptance') {
					$xml .= " type='checkbox'";
				} elseif($field['type']  == 'checkbox' && isset($field['options']['exclusive'])) {
					$field['type'] = 'radio';
					$xml .= " type='radio'"; // Exclusive checkboxes != checkboxes. No idea why they have this option.
				} else {
					$xml .= " type='{$field['type']}'";
				}
				$xml .= (isset($field['isRequired']) || $field['type']  == 'acceptance') ? " isRequired='1'" : '';
				$xml .= ($field['type'] == 'captcha') ? " displayOnly='1'" : '';
				$xml .= ($field['type'] == 'phone') ? " phoneFormat='standard'" : '';
			$xml .= ">\n\t\t";

			if(isset($field['options']['class'])) {
				$xml .= "\t<cssClass><![CDATA[{$field['options']['class']}]]></cssClass>\n\t\t";
			}

			if(isset($field['options']['maxLength'])) {
				$xml .= "\t<maxLength><![CDATA[{$field['options']['maxLength']}]]></maxLength>\n\t\t";
			}

			if(!empty($field['name']) && $field['type'] !== 'captcha') {
				$xml .= "\t<inputName><![CDATA[{$field['name']}]]></inputName>\n\t\t";
			}

			$label = self::getLabel($field);

			switch($field['type']) {

				case 'radio':
				case 'checkbox':
				case 'select':
					$choices = isset($field['labels']) ? $field['labels'] : array();
					$xml .= "\t<label><![CDATA[{$label}]]></label>\n\t\t";
					if(!empty($choices)) {
						$xml .= "\t<choices>\n\t\t";
						$i = 1;
						foreach($choices as $choice) {
							if(isset($field['options']["default"]) && is_array($field['options']["default"]) && in_array($i, $field['options']["default"])) {
								$xml .= "\t\t<choice isSelected='1'>";
							} else {
								$xml .= "\t\t<choice>";
							}
							$xml .= "\n\t\t\t\t\t<text><![CDATA[{$choice}]]></text>\n\t\t\t\t</choice>\n\t\t";
							$i++;
						}
						$xml .= "\t</choices>\n\t\t";
					}
					break;
				case 'acceptance':
					$xml .= "\t<label><![CDATA[{$label}]]></label>\n\t\t";
					$xml .= "\t<choices>\n\t\t\t";
					if(isset($field['options']["default"]) && is_array($field['options']["default"]) && $field['options']["default"][0] === 'on') {
						$xml .= "\t<choice isSelected='1'>";
					} else {
						$xml .= "\t<choice>";
					}
					$xml .= "\n\t\t\t\t\t<text><![CDATA[]]></text>\n\t\t\t\t</choice>\n\t\t\t</choices>\n\t\t";
					break;
				case 'captcha':
					$xml .= "\t<label><![CDATA[{$label}]]></label>\n\t\t";
					$xml .= "\t<captchaTheme><![CDATA[Red]]></captchaTheme>\n\t\t";
					$xml .= "\t<errorMessage><![CDATA[{$form->messages['captcha_not_match']}]]></errorMessage>\n\t\t";
					break;
				case 'address':
				case 'name':
				case 'file':
				case 'text':
				case 'email':
				case 'textarea':
				default:
					$xml .= "\t<label><![CDATA[{$label}]]></label>\n\t\t";
					break;
			}

			$xml .= "</field>\n\t\t";

		$id++;

		}

		$xml .= "</fields>\n\t";

		$notification = '';
		if(!empty($form->notification) && !empty($form->notification['to'])) {
			$notification .= "\t<notification>
				<to><![CDATA[{$form->notification['to']}]]></to>
				<from><![CDATA[{$form->notification['from']}]]></from>
				<subject><![CDATA[{$form->notification['subject']}]]></subject>
				<message><![CDATA[{$form->notification['message']}]]></message>";
			if(!empty($form->notification['disableAutoformat'])) {
				$notification .= "\n\t\t\t<disableAutoformat><![CDATA[1]]></disableAutoformat>";
			}
			$notification .= "\n\t\t</notification>\n\t";
		}

		if(!empty($form->autoResponder) && !empty($form->autoResponder['toField'])) {
			$notification .= "\t<autoResponder>
				<toField><![CDATA[{$form->autoResponder['toField']}]]></toField>
				<from><![CDATA[{$form->autoResponder['from']}]]></from>
				<subject><![CDATA[{$form->autoResponder['subject']}]]></subject>
				<message><![CDATA[{$form->autoResponder['message']}]]></message>";
			if(!empty($form->autoResponder['disableAutoformat'])) {
				$notification .= "\n\t\t\t<disableAutoformat><![CDATA[1]]></disableAutoformat>";
			}
			$notification .= "\n\t\t</autoResponder>\n\t";
		}

		$id = 1;
		foreach($form->fields as $field) {
			if(empty($field['name'])) { continue; }
			$label = isset($field['labels'][0]) ? $field['labels'][0] : '';

			list($field, $useid) = self::update_field_types_and_ids($field, $id);

			$notification = str_replace("[{$field['name']}]", "{{$label}:{$id}}", $notification);

			$id++;
		}

		$xml .= $notification;

		$xml .= "</form>\n</forms>";


		$GFCF7_Import->xml = $xml;
		$GFCF7_Import->form = $form;

		return;

	}

	public static function get_field_type($type = '', $name = ''){
		$label = false;

		$temp = $the_label = strtolower($name);

		switch(trim($type)) {
			case 'quiz':
				return 'captcha';
				break;
			case 'acceptance':
			case 'radio':
			case 'email':
			case 'checkbox':
			case 'select':
			case 'hidden':
				return $type;
				break;
			case 'multiple':
				return 'select';
				break;
			case 'textarea':
			case 'description':
				return 'textarea';
				break;
			case 'file':
				return 'fileupload';
				break;
		}

		if ($type == 'name' && (strpos($the_label, 'first') !== false || ( strpos($the_label,"name") !== false && strpos($the_label,"first") !== false))) {
			$label = 'firstname';
		} else if ($type == 'name' && ( strpos( $the_label,"last") !== false || ( strpos( $the_label,"name") !== false && strpos($the_label,"last") !== false) )) {
			$label = 'lastname';
		} elseif($the_label == 'prefix' || $the_label == 'salutation' || strpos( $the_label, 'prefix') || strpos( $the_label, 'salutation')) {
			$label = 'prefix';
		} elseif($the_label == 'suffix') {
			$label = 'suffix';
		} else if ( strpos( $the_label,"email") !== false || strpos( $the_label,"e-mail") !== false || $type == 'email') {
			$label = 'email';
		} else if ( strpos( $the_label,"mobile") !== false || strpos( $the_label,"cell") !== false ) {
			$label = 'phone';
		} else if ( strpos( $the_label,"fax") !== false) {
			$label = 'phone';
		} else if ( strpos( $the_label,"phone") !== false ) {
			$label = 'phone';
		} else if ( strpos( $the_label,"city") !== false ) {
			$label = 'city';
		} else if ( strpos( $the_label,"country") !== false ) {
			$label = 'country';
		} else if ( strpos( $the_label,"state") !== false ) {
			$label = 'state';
		} else if ( strpos( $the_label,"zip") !== false ) {
			$label = 'zip';
		} else if ( strpos( $the_label,"street") !== false || strpos( $the_label,"address") !== false ) {
			$label = 'street';
		} else if ( strpos( $the_label,"website") !== false || strpos( $the_label,"web site") !== false || strpos( $the_label,"web") !== false ||  strpos( $the_label,"url") !== false) {
			$label = 'website';
		} else if ( strpos( $the_label,"name") !== false && $type == 'text') {
			$label = 'name';
		} else {
			$label = $type;
		}

		return $label;
    }

	private function cleanup(&$forms){
        unset($forms["version"]);

        //adding checkboxes "inputs" property based on "choices". (they were removed from the export
        //to provide a cleaner xml format
        foreach($forms as &$form){
            if(!isset($form["fields"]) || !is_array($form["fields"]))
                continue;

            foreach($form["fields"] as &$field){
                $input_type = RGFormsModel::get_input_type($field);
                if(in_array($input_type, array("checkbox", "radio", "select"))){

                    //creating inputs array for checkboxes
                    if($input_type == "checkbox" && !isset($field["inputs"]))
                        $field["inputs"] = array();

					if(isset($field["choices"])) {
	                    for($i=1, $count = sizeof($field["choices"]); $i<=$count; $i++){
	                        if(!RGForms::get("enableChoiceValue", $field))
	                            $field["choices"][$i-1]["value"] = $field["choices"][$i-1]["text"];

	                        if($input_type == "checkbox")
	                            $field["inputs"][] = array("id" => $field["id"] . "." . $i, "label" => $field["choices"][$i-1]["text"]);
	                    }
                    }

                }
            }
        }
        return $forms;
    }

	function import_forms() {
		global $GFCF7_Import;
		require_once(GFCommon::get_base_path()."/export.php");
		require_once(GFCommon::get_base_path()."/xml.php");

		$options = array(
			"page" => array("unserialize_as_array" => true),
			"form"=> array("unserialize_as_array" => true),
			"field"=> array("unserialize_as_array" => true),
			"rule"=> array("unserialize_as_array" => true),
			"choice"=> array("unserialize_as_array" => true),
			"input"=> array("unserialize_as_array" => true),
			"routing_item"=> array("unserialize_as_array" => true),
			"routin"=> array("unserialize_as_array" => true) //routin is for backwards compatibility
			);

		$xml = new RGXML($options);

		$forms = $xml->unserialize($GFCF7_Import->xml);

        if(!$forms)
            return 0;   //Error. could not unserialize XML file
#        else if(version_compare($forms["version"], GFExport::$min_import_version, "<"))
#            return -1;  //Error. XML version is not compatible with current Gravity Forms version

        //cleaning up generated object
        $forms = self::cleanup($forms);

        foreach($forms as $key => $form){
            $title = $form["title"];
            $count = 2;
            while(!RGFormsModel::is_unique_title($title)){
                $title = $form["title"] . " ($count)";
                $count++;
            }

            //inserting form
            $form_id = RGFormsModel::insert_form($title);

            //updating form meta
            $form["title"] = $title;
            $form["id"] = $form_id;
            RGFormsModel::update_form_meta($form_id, $form);
        }
        $form['addresses'] = $GFCF7_Import->form->addresses;
        $form['names'] = $GFCF7_Import->form->names;
        return $form;

	}

	function import() {
		global $GFCF7_Import;
		if(empty($_POST['cf7form'])) {
			_e(sprintf('%sYou must choose a form. %sReturn to previous page.%s%s', '<p>', '<a href="'.admin_url('admin.php?import=gfcf7').'">', '</a>', '</p>'));
			return;
		}

		// Create the XML for Gravity Forms
		self::get_forms();

		// Add the form using
		$result = self::import_forms();

		if ( is_wp_error( $result ) )
			return $result;

		do_action('import_done', 'gfcf7');
		echo '<h3>';

		if(isset($result['id']) && isset($result['title'])) {
			$url = admin_url('admin.php?page=gf_edit_forms&id='.$result['id']);
			printf(__('The form &ldquo;%s&rdquo; was imported. %sEdit in Gravity Forms &rarr;%s', "gfcf7"), $result['title'], '<a href="'.$url.'" style="font-weight:normal;">', '</a>');
		} else {
			$url = admin_url('admin.php?page=gf_edit_forms');
			printf(__('Form imported. %sView all forms%s', "gfcf7"), '<a href="'.$url.'">', '</a>');
		}
		echo '</h3>';
		$notices = array();
		if(isset($result['addresses']) && $result['addresses'] > 1) {
			if(isset($_POST['combinefields'])) {
				$notices[] = sprintf(__('Your form had %s address fields that were combined.', "gfcf7"), $result['addresses']);
			} else {
				$notices[] = sprintf(__('Your form had %s address fields. You will likely need to remove or combine them.', "gfcf7"), $result['addresses']);
			}
		}
		if(isset($result['names']) && $result['names'] > 1) {
			if(isset($_POST['combinefields'])) {
				$notices[] = sprintf(__('Your form had %s name fields that were combined.', "gfcf7"), $result['names']);
			} else {
				$notices[] = sprintf(__('Your form had %s name fields. You will likely need to remove or combine them.', "gfcf7"), $result['names']);
			}
		}
		if(!empty($notices)) {
			echo '<div class="updated" id="message">
				<h3 style="margin-bottom:0.5em;">'.__('Import notes:', "gfcf7").'</h3>
				<ul class="ul-disc">';
			foreach($notices as $notice) {
				echo '<li>'.$notice.'</li>';
			}
			echo '</ul>
			</div>';
		}
	}

	function dispatch() {
		if (empty ($_POST['cf7form']))
			$step = 0;
		else
			$step = 1;

		self::header();

		switch ($step) {
			case 0 :
				self::greet();
				break;
			case 1 :
				check_admin_referer('import-upload');
				$result = self::import();
				if ( is_wp_error( $result ) )
					echo $result->get_error_message();
				break;
		}

		self::footer();
	}


	function kwd_import_upload_form( $action ) {
		global $wpdb, $wpcf7;

		if(!function_exists('wpcf7_contact_form')) {
			echo '<div class="error" id="message">';
			_e(wpautop('You need to have Contact Form 7 installed and activated for this importer to work.'));
			echo '</div>';
			return false;
		}

		$forms = array();

		// Version 3 changes everything…jeez!
		if(function_exists('wpcf7_convert_to_cpt')) {
			$results = get_posts('post_type=wpcf7_contact_form&numberposts=100000000');
			foreach($results as $row) {
				$forms[$row->ID] = maybe_unserialize($row->post_title);
			}
		} else {
			// Get CF7 Forms
			$query = $wpdb->prepare( "SELECT * FROM $wpcf7->contactforms");

			if ( ! $results = $wpdb->get_results( $query ) )
				return false; // No data

			foreach($results as $row) {
				$forms["{$row->cf7_unit_id}"] = maybe_unserialize($row->title);
			}
		}


		$output = '';

		$output .= '
		<form id="kws-import-upload-form" method="post" action="'.esc_attr($action).'">
			<fieldset class="alignleft" style="width:30%; margin-right:15px">
				<h3>'.__('Choose the Contact Form 7 form to import', "gfcf7").'</h3>
				<label for="cf7form">'.__('This form will be imported into Gravity Forms as a new form:', "gfcf7").'
				<select name="cf7form" id="cf7form" style="display:block;">
					<option value="0">'.__("Select a Contact Form 7 Form", "gfcf7").'</option>';
		foreach($forms as $key => $value) {
			$output .= '
					<option value="'.$key.'">'.$value.'</option>';
		}
		$output .= '
				</select>
				</label>
			</fieldset>';

		$output .= '
			<div class="clear">
			'.wp_nonce_field('import-upload','_wpnonce',true, false).'
			</div>
			<p><label for="parselabels"><input type="checkbox" name="parselabels" id="parselabels" checked="checked" /> '.__('Attempt to parse input labels from form HTML code.', "gfcf7").'</label>
			<span class="howto">'.__(sprintf('If you uncheck this, inputs will be imported based only on the tags inside your Contact Form 7 form code (such as %s[text text-45 "This will be the label used."]%s', '<code>', '</code>')).'</span>
			</p>
			<p><label for="combinefields"><input type="checkbox" name="combinefields" id="combinefields" checked="checked" /> '.__('Combine Name & Address fields', "gfcf7").'</label>
			<span class="howto">'.__('Street, City, ZIP, etc. will be combined into one Address input. First name and last name will be one Name field.', "gfcf7").'</span>
			</p>
			<div class="submit">
				<input type="submit" class="button" value="'.__( 'Import from Contact Form 7' ).'" />
			</div>
		</form>
		';

		echo $output;
	}

	function GFCF7_Import() {
		global $pagenow;

		add_filter("gform_addon_navigation", 'gfcf7_create_menu');

		add_action('init', create_function('', 'load_plugin_textdomain(\'gfcf7\');') );

		function gfcf7_create_menu($menus){

			// Adding submenu if user has access
			$permission = current_user_can("level_7");

			if(!empty($permission)) {
				$menus[] = array("name" => "gfcf7", "label" => __("<acronym title='Contact Form 7'>CF7</acronym> Import", "gfcf7"), "callback" =>  array("GFCF7_Import", "dispatch"), "permission" => $permission);
			}

			return $menus;
		}

		require_once(ABSPATH.'wp-admin/includes/import.php');

		if(function_exists('register_importer')) {
			register_importer('gfcf7', __('Contact Form 7 &rarr; Gravity Forms', "gfcf7"), __('Import Contact Form 7 forms into Gravity Forms.', "gfcf7"), array ('GFCF7_Import', 'dispatch'));
		}



	}
}
?>