<?php
/*
Plugin Name: Gravity Forms Contact Form 7 Importer
Plugin URI: http://www.katzwebservices.com
Description: Import your existing Contact Form 7 forms into <a href="http://formplugin.com?r=gfcf7">Gravity Forms</a>.
Author: Katz Web Services, Inc.
Version: 2.0
Author URI: http://www.katzwebservices.com

------------------------------------------------------------------------
Copyright 2015 Katz Web Services, Inc.

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

class GFCF7_Import {

	static $instance;
	var $posts = array();
	var $file;
	var $xml;

	/**
	 * The HTML of the CF7 form
	 * @var string
	 */
	var $form = '';


	function __construct() {
		global $pagenow;

		if( !is_admin() ) { return; }

		add_filter("gform_addon_navigation", array('GFCF7_Import', 'create_gf_menu') );

		add_action('init', array('GFCF7_Import', 'init') );

	}

	static function init() {

		/**
		 * Set up internationalization support.
		 */
		load_plugin_textdomain( 'gfcf7', false,  dirname( plugin_basename( __FILE__ ) ).'/languages/' );


		require_once(ABSPATH.'wp-admin/includes/import.php');

		if( function_exists('register_importer') ) {
			register_importer('gfcf7', __('Contact Form 7 &rarr; Gravity Forms', "gfcf7"), __('Import Contact Form 7 forms into Gravity Forms.', "gfcf7"), array ('GFCF7_Import', 'dispatch'));
		}
	}

	/**
	 * Add menu link to Gravity Forms menu
	 * @param $menus
	 *
	 * @return array
	 */
	static function create_gf_menu($menus){

		// Adding submenu if user has access
		$permission = current_user_can('manage_options');

		if(!empty($permission)) {
			$menus[] = array(
				"name" => "gfcf7",
				"label" => __("CF7 Import", "gfcf7"),
				"callback" =>  array("GFCF7_Import", "dispatch"),
				"permission" => $permission
			);
		}

		return $menus;
	}

	/**
	 * @return GFCF7_Import
	 */
	static function getInstance() {
		if( empty( self::$instance ) ) {
			self::$instance = new GFCF7_Import;
		}

		return self::$instance;
	}

	/**
	 * @param
	 */
	public function setForm( $form ) {
		self::getInstance()->form = $form;
	}

	/**
	 * @param string XML
	 */
	public function setXML( $xml ) {
		self::getInstance()->xml = $xml;
	}

	static function header() { ?>
		<div class="wrap">
		<img alt="<?php _e("Gravity Forms", "gfcf7") ?>" src="<?php echo self::get_base_url()?>/images/gravity-import-icon-32.png" style="float:left; margin:15px 7px 0 0;" width="36" height="35"/>
		<h2><?php esc_html_e('Import a Contact Form 7 Form to Gravity Forms', 'gfcf7'); ?></h2>
		<div class="clear"></div>
	<?php
	}

	static function footer() {
		echo '</div>';
	}

	static function unhtmlentities($string) { // From php.net for < 4.3 compat
		$trans_tbl = get_html_translation_table(HTML_ENTITIES);
		$trans_tbl = array_flip($trans_tbl);
		return strtr($string, $trans_tbl);
	}

	//Returns the url of the plugin's root folder
    protected static function get_base_url(){
        return plugins_url(null, __FILE__);
    }

	static function greet() {
		self::import_upload_form(add_query_arg(array('step' => 1)));
	}

	static function get_settings($field) {
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

	/**
	 * Analyze the CF7 field types to generate Gravity Forms field settings for input numbers and field types
	 *
	 * @param $field
	 * @param $id
	 *
	 * @return array
	 */
	static function update_field_types_and_ids($field, $id) {

		$useid = $id;

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

			case 'tel':
				$field['type'] = 'phone';
				$field['phoneFormat'] = 'standard';
				break;
		}

		return array($field, $useid);
	}

	static function getLabel($label) {
		if(isset($label['parsedLabelBefore']) && !empty($label['parsedLabelBefore'])) {
			return trim(rtrim($label['parsedLabelBefore']));
		} elseif(isset($field['labels'][0])) {
			return trim(rtrim($field['labels'][0]));
		}
		return '';
	}

	static function get_forms() {
		global $wpdb, $wpcf7_shortcode_manager;

		if(!function_exists('wpcf7_contact_form')) {
			echo '<div class="error" id="message">';
			echo wpautop( __('You need to have Contact Form 7 installed and activated for this importer to work.', "gfcf7") );
			echo '</div>';
			return false;
		}

		$form = wpcf7_contact_form( intval( $_POST['cf7form'] ) );

		// Get
		$properties = $form->get_properties();

		$gf_form = new StdClass;

		$gf_form->title = $form->title();
		$gf_form->messages = $form->prop('messages');

		$gf_form->notification = array(
			'to' => $properties['mail']['recipient'],
			'message' => $properties['mail']['body'],
			'subject' => $properties['mail']['subject'],
			'from' => $properties['mail']['sender'],
			'disableAutoformat' => !empty($properties['mail']['use_html']),
		);

		$gf_form->autoResponder = array(
			'toField' => $properties['mail_2']['recipient'],
			'message' => $properties['mail_2']['body'],
			'subject' => $properties['mail_2']['subject'],
			'from' => $properties['mail_2']['sender'],
			'disableAutoformat' => !empty($properties['mail_2']['use_html']),
		);

		$gf_form->fields = $form->form_scan_shortcode(); //$wpcf7_shortcode_manager->scan_shortcode($form->form);

		$gf_form->formCode = $properties['form'];

		foreach($gf_form->fields as $key => $field) {

			// Just get the button text from the submit field
			if( $field['type'] === 'submit') {
				$gf_form->buttonText = $field['values'][0];
				unset( $gf_form->fields[ $key ] );
				continue;
			}

			// Required fields end in an asterisk
			$field['isRequired'] = ( substr( $field['type'], -1, 1 ) === '*' ) ? 1 : false;

			$field['type'] = str_replace('*', '', $field['type']);
			$field['name'] = isset($field['name']) ? $field['name'] : '';
			$field['originalType'] = $field['type'];
			$field['type'] = self::get_field_type($field['type'], $field['name']);
			$field['defaultValue'] = (isset($field['values']) && isset($field['values'][0])) ? $field['values'][0] : false;

			$gf_form->fields["{$key}"] = self::get_settings($field);

			unset($gf_form->fields["{$key}"]['pipes']);

			if( isset( $_POST['parselabels'] ) ) {
				$regex = '/((.*)(?:\s+)?\[(?:.*?)'.$field['name'].'(?:.*?)\](.*))/im';
				preg_match($regex, $gf_form->formCode, $matches);
				if(!empty($matches)) {
					$gf_form->fields["{$key}"]['parsedLabelBefore'] = strip_tags($matches[2], '<b><strong><em><i><span><u>');
					$gf_form->fields["{$key}"]['parsedLabelAfter'] = strip_tags($matches[3], '<b><strong><em><i><span><u>');
					$gf_form->formCode = preg_replace($regex, '', $gf_form->formCode);
				}
			}
		}

		unset($mail, $mail_2);

		$xml = self::generate_xml( $gf_form, $form );

		self::getInstance()->setXML( $xml );
		self::getInstance()->setForm( $form );

		return;
	}

	private static function generate_xml( $gf_form, $form ) {

		$combinefields = isset($_POST['combinefields']);

		$messages = $form->prop( 'messages' );

		$xml = <<<EOD
<?xml version="1.0" encoding="UTF-8"?>
<forms version="1.5.2.8">
	<form labelPlacement="top_label" useCurrentUserAsAuthor="1">
		<title><![CDATA[{$form->title()}]]></title>
		<description><![CDATA[]]></description>
		<confirmation type="message">
			<message><![CDATA[{$messages['mail_sent_ok']}]]></message>
		</confirmation>
		<button type="text">
			<text><![CDATA[{$gf_form->buttonText}]]></text>
		</button>
		<fields>
EOD;

		$xml .= "\n\t\t"; $id = 1;
		$gf_form->addresses = $gf_form->names = 0;
		foreach($gf_form->fields as $field) {

			list($field, $useid) = self::update_field_types_and_ids($field, $id);

			if($field['type'] == 'address') {
				$gf_form->addresses++;
				if($combinefields && $gf_form->addresses > 1) { continue; }
			}
			if($field['type'] == 'name') {
				$gf_form->names++;
				if($combinefields && $gf_form->names > 1) { continue; }
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
					$xml .= "\t<errorMessage><![CDATA[{$messages['captcha_not_match']}]]></errorMessage>\n\t\t";
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
		if(!empty($gf_form->notification) && !empty($gf_form->notification['to'])) {
			$notification .= "\t<notification>
				<to><![CDATA[{$gf_form->notification['to']}]]></to>
				<from><![CDATA[{$gf_form->notification['from']}]]></from>
				<subject><![CDATA[{$gf_form->notification['subject']}]]></subject>
				<message><![CDATA[{$gf_form->notification['message']}]]></message>";
			if(!empty($gf_form->notification['disableAutoformat'])) {
				$notification .= "\n\t\t\t<disableAutoformat><![CDATA[1]]></disableAutoformat>";
			}
			$notification .= "\n\t\t</notification>\n\t";
		}

		if(!empty($gf_form->autoResponder) && !empty($gf_form->autoResponder['toField'])) {
			$notification .= "\t<autoResponder>
				<toField><![CDATA[{$gf_form->autoResponder['toField']}]]></toField>
				<from><![CDATA[{$gf_form->autoResponder['from']}]]></from>
				<subject><![CDATA[{$gf_form->autoResponder['subject']}]]></subject>
				<message><![CDATA[{$gf_form->autoResponder['message']}]]></message>";
			if(!empty($gf_form->autoResponder['disableAutoformat'])) {
				$notification .= "\n\t\t\t<disableAutoformat><![CDATA[1]]></disableAutoformat>";
			}
			$notification .= "\n\t\t</autoResponder>\n\t";
		}

		// replace the CF7 field name shortcode with the Gravity Forms merge tags
		$id = 1;
		foreach($gf_form->fields as $field) {

			if( empty( $field['name'] ) ) {
				continue;
			}

			$label = self::getLabel( $field );

			list($use_field, $useid) = self::update_field_types_and_ids($field, $id);

			$notification = str_replace("[{$use_field['name']}]", "{{$label}:{$id}}", $notification);

			$id++;
		}

		$xml .= $notification;

		$xml .= "</form>\n</forms>";

		return $xml;
	}

	public static function get_field_type($type = '', $name = ''){
		$label = false;

		$temp = $the_label = strtolower($name);

		switch(trim($type)) {
			case 'captchar':
			case 'captchac':
				return 'captcha';
				break;
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
			case 'tel':
				return 'phone';
				break;
			case 'url':
				return 'website';
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

	static private function cleanup(&$forms){
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

	static function import_forms() {

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

		$forms = $xml->unserialize( self::getInstance()->xml );

        if(!$forms) {
	        return 0;   //Error. could not unserialize XML file
        }

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
        $form['addresses'] = self::getInstance()->form->addresses;
        $form['names'] = self::getInstance()->form->names;

        return $form;

	}

	/**
	 * Handle the import process. Calls
	 */
	static function import() {

		if(empty($_POST['cf7form'])) {
			printf( __('%sYou must choose a form. %sReturn to previous page.%s%s', 'gfcf7'), '<p>', '<a href="'.admin_url('admin.php?import=gfcf7').'">', '</a>', '</p>' );
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
				echo '<li>'.esc_html( $notice ).'</li>';
			}
			echo '</ul>
			</div>';
		}
	}

	/**
	 * Handle the Import Form submission
	 */
	static function dispatch() {
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


	static function import_upload_form( $action ) {
		global $wpdb, $wpcf7;

		if(!function_exists('wpcf7_contact_form')) {
			echo '<div class="error" id="message">';
			echo wpautop( __('You need to have Contact Form 7 installed and activated for this importer to work.', 'gfcf7' ) );
			echo '</div>';
			return false;
		}

		$forms = array();

		// Version 3 changes everything…jeez!
		if( !function_exists('wpcf7_convert_to_cpt') ) {
			_doing_it_wrong( 'GFCF7_Import::import_upload_form()', __('You are using an older version of Contact Form 7. Please upgrade to CF7 3.0 or higher', 'gfcf7'), '2.0' );
			return false;
		}

		$results = WPCF7_ContactForm::find();

		if( empty( $results ) ) {
			echo '<h3>'.esc_html__("There are no Contact Form 7 forms to import.", 'gfcf7').'</h3>';

			return false;
		}

		foreach($results as $row) {
			$forms[ $row->id() ] = maybe_unserialize( $row->title() );
		}


		$output = '';

		$output .= '
		<form id="kws-import-upload-form" method="post" action="'.esc_attr($action).'">
			<fieldset class="alignleft">
				<h2>'.__('Choose the Contact Form 7 form to import', "gfcf7").'</h2>
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
			<p><label for="parselabels"><input type="checkbox" name="parselabels" id="parselabels" checked="checked" /> '.esc_html__('Attempt to parse input labels from form HTML code.', "gfcf7").'</label>
			<span class="howto">'.sprintf( __('If you uncheck this, inputs will be imported based only on the tags inside your Contact Form 7 form code (such as %s[text text-45 "This will be the label used."]%s', 'gfcf7'),  '<code>', '</code>') .'</span>
			</p>
			<p><label for="combinefields"><input type="checkbox" name="combinefields" id="combinefields" checked="checked" /> '.__('Combine Name & Address fields', "gfcf7").'</label>
			<span class="howto">'.__('Street, City, ZIP, etc. will be combined into one Address input. First name and last name will be one Name field.', "gfcf7").'</span>
			</p>
			<div class="submit">
				<input type="submit" class="button button-large button-primary" value="'.esc_attr__( 'Import from Contact Form 7', 'gfcf7' ).'" />
			</div>
		</form>
		';

		echo $output;
	}

}

GFCF7_Import::getInstance();