<?php

/**
 * SAUserprefs
 *
 * Plugin to allow the user to manage their SpamAssassin settings using an SQL database
 *
 * @version 1.0-BETA
 * @author Philip Weir
 * @url http://roundcube.net/plugins/sauserprefs
 */
class sauserprefs extends rcube_plugin
{
	public $task = 'mail|addressbook|settings';
	private $config;
	private $db;
	private $sections = array();
	private $cur_section;
	private $global_prefs;
	private $user_prefs;
	private $deprecated_prefs = array(
			'required_hits' => 'required_score'
			);

	function init()
	{
		if (rcmail::get_instance()->task == 'settings') {
			$this->add_texts('localization/', array('sauserprefs'));

			$this->sections = array(
				'general' => array('id' => 'general', 'section' => $this->gettext('spamgeneralsettings')),
				'tests' => array('id' => 'tests', 'section' => $this->gettext('spamtests')),
				'headers' => array('id' => 'headers', 'section' => $this->gettext('headers')),
				'report' => array('id' => 'report','section' => $this->gettext('spamreportsettings')),
				'addresses' => array('id' => 'addresses', 'section' => $this->gettext('spamaddressrules')),
			);
			$this->cur_section = get_input_value('_section', RCUBE_INPUT_GPC);

			$this->register_action('plugin.sauserprefs', array($this, 'init_html'));
			$this->register_action('plugin.sauserprefs.edit', array($this, 'init_html'));
			$this->register_action('plugin.sauserprefs.save', array($this, 'save'));
			$this->register_action('plugin.sauserprefs.whitelist_import', array($this, 'whitelist_import'));
			$this->include_script('sauserprefs.js');
		}
		else {
			$this->add_hook('create_contact', array($this, 'contact_add'));
			$this->add_hook('save_contact', array($this, 'contact_save'));
			$this->add_hook('delete_contact', array($this, 'contact_delete'));
		}
	}

	function init_html()
	{
		$this->_load_config();
		$this->_db_connect('r');
		$this->_load_global_prefs();
		$this->_load_user_prefs();

		$this->api->output->set_pagetitle($this->gettext('sauserprefssettings'));

		if (rcmail::get_instance()->action == 'plugin.sauserprefs.edit') {
			$this->user_prefs = array_merge($this->global_prefs, $this->user_prefs);
			$this->api->output->add_handler('userprefs', array($this, 'gen_form'));
			$this->api->output->add_handler('sectionname', array($this, 'prefs_section_name'));
			$this->api->output->send('sauserprefs.settingsedit');
		}
		else {
			$this->api->output->add_handler('sasectionslist', array($this, 'section_list'));
			$this->api->output->add_handler('saprefsframe', array($this, 'preference_frame'));
			$this->api->output->send('sauserprefs.sauserprefs');
		}
	}

	function section_list($attrib)
	{
		$rcmail = rcmail::get_instance();

		// add id to message list table if not specified
		if (!strlen($attrib['id']))
			$attrib['id'] = 'rcmsectionslist';

		$sections = array();
		$blocks = $attrib['sections'] ? preg_split('/[\s,;]+/', strip_quotes($attrib['sections'])) : array('general','headers','tests','report','addresses');
		foreach ($blocks as $block)
			$sections[$block] = $this->sections[$block];

		// create XHTML table
		$out = rcube_table_output($attrib, $sections, array('section'), 'id');

		// set client env
		$rcmail->output->add_gui_object('sectionslist', $attrib['id']);
		$rcmail->output->include_script('list.js');

		return $out;
	}

	function preference_frame($attrib)
	{
		if (!$attrib['id'])
			$attrib['id'] = 'rcmprefsframe';

		$attrib['name'] = $attrib['id'];

		$this->api->output->set_env('contentframe', $attrib['name']);
		$this->api->output->set_env('blankpage', $attrib['src'] ? $this->api->output->abs_url($attrib['src']) : 'program/blank.gif');

		return html::iframe($attrib);
	}

	function gen_form($attrib)
	{
		$this->api->output->add_label(
			'sauserprefs.spamaddressexists', 'sauserprefs.spamenteraddress',
			'sauserprefs.spamaddresserror', 'sauserprefs.spamaddressdelete',
			'sauserprefs.spamaddressdeleteall', 'sauserprefs.enabled', 'sauserprefs.disabled',
			'sauserprefs.importingaddresses', 'sauserprefs.usedefaultconfirm');

		// output global prefs as default in env
		foreach($this->global_prefs as $key => $val)
			$this->api->output->set_env(str_replace(" ", "_", $key), $val);

		list($form_start, $form_end) = get_form_tags($attrib, 'plugin.sauserprefs.save', null,
			array('name' => '_section', 'value' => $this->cur_section));

		unset($attrib['form']);

		$out = $form_start;

		$out .= $this->_prefs_block($this->cur_section, $attrib);

		return $out . $form_end;
	}

	function prefs_section_name()
	{
		return $this->sections[$this->cur_section]['section'];
	}

	function save()
	{
		$this->_load_config();
		$this->_db_connect('r');
		$this->_load_global_prefs();
		$this->_load_user_prefs();

		$new_prefs = array();

		if ($this->cur_section == 'general') {
			if ($this->config['general_settings']['score'])
				$new_prefs['required_score'] = $_POST['_spamthres'];

			if ($this->config['general_settings']['subject'])
				$new_prefs['rewrite_header Subject'] = $_POST['_spamsubject'];

			if ($this->config['general_settings']['language']) {
				$new_prefs['ok_locales'] = is_array($_POST['_spamlang']) ? implode(" ", $_POST['_spamlang']) : '';
				$new_prefs['ok_languages'] = $new_prefs['ok_locales'];
			}
		}

		if ($this->cur_section == 'headers') {
			$new_prefs['fold_headers'] = empty($_POST['_spamfoldheaders']) ? "0" : $_POST['_spamfoldheaders'];
			$spamchar = empty($_POST['_spamlevelchar']) ? "*" : $_POST['_spamlevelchar'];
			if ($_POST['_spamlevelstars'] == "1") {
				$new_prefs['add_header all Level'] = "_STARS(". $spamchar .")_";
				$new_prefs['remove_header all'] = "0";
			}
			else {
				$new_prefs['add_header all Level'] = "";
				$new_prefs['remove_header all'] = "Level";
			}
		}

		if ($this->cur_section == 'tests') {
			$new_prefs['use_razor1'] = empty($_POST['_spamuserazor1']) ? "0" : $_POST['_spamuserazor1'];
			$new_prefs['use_razor2'] = empty($_POST['_spamuserazor2']) ? "0" : $_POST['_spamuserazor2'];
			$new_prefs['use_pyzor'] = empty($_POST['_spamusepyzor']) ? "0" : $_POST['_spamusepyzor'];
			$new_prefs['use_bayes'] = empty($_POST['_spamusebayes']) ? "0" : $_POST['_spamusebayes'];
			$new_prefs['use_dcc'] = empty($_POST['_spamusedcc']) ? "0" : $_POST['_spamusedcc'];

			if ($_POST['_spamskiprblchecks'] == "1")
				$new_prefs['skip_rbl_checks'] = "";
			else
				$new_prefs['skip_rbl_checks'] = "1";
		}

		if ($this->cur_section == 'report')
			$new_prefs['report_safe'] = $_POST['_spamreport'];

		$result = true;
		foreach ($new_prefs as $preference => $value){
			if ($value == "" || $value == $this->global_prefs[$preference]) {
				$result = false;

				$this->db->query(
				  "DELETE FROM ". $this->config['sql_table_name'] ."
				   WHERE  ". $this->config['sql_username_field'] ." = '". $_SESSION['username'] ."'
				   AND    ". $this->config['sql_preference_field'] ." = '". $preference ."';"
				  );

				$result = $this->db->affected_rows();

				if (!$result)
					break;
			}
			elseif (array_key_exists($preference, $this->user_prefs) && $value != $this->user_prefs[$preference]) {
				$result = false;

				$this->db->query(
				  "UPDATE ". $this->config['sql_table_name'] ."
				   SET    ". $this->config['sql_value_field'] ." = '". $value ."'
				   WHERE  ". $this->config['sql_username_field'] ." = '". $_SESSION['username'] ."'
				   AND    ". $this->config['sql_preference_field'] ." = '". $preference ."';"
				  );

				$result = $this->db->affected_rows();

				if (!$result)
					break;
			}
			elseif ($value != $this->global_prefs[$preference] && $value != $this->user_prefs[$preference]) {
				$result = false;

				$this->db->query(
				  "INSERT INTO ". $this->config['sql_table_name'] ."
				   (".$this->config['sql_username_field'].", ".$this->config['sql_preference_field'].", ".$this->config['sql_value_field'].")
				   VALUES ('". $_SESSION['username'] ."', '". $preference ."', '". $value ."')"
				  );

				$result = $this->db->affected_rows();

				if (!$result)
					break;
			}
		}

		if ($result) {
			if ($this->cur_section == 'addresses') {
				$acts = $_POST['_address_rule_act'];
				$prefs = $_POST['_address_rule_field'];
				$vals = $_POST['_address_rule_value'];

				foreach ($acts as $idx => $act){
					if ($act == "DELETE") {
						$result = false;

						$this->db->query(
						  "DELETE FROM ". $this->config['sql_table_name'] ."
						   WHERE  ". $this->config['sql_username_field'] ." = '". $_SESSION['username'] ."'
						   AND    ". $this->config['sql_preference_field'] ." = '". $prefs[$idx] ."'
						   AND    ". $this->config['sql_value_field'] ." = '". $vals[$idx] . "';"
						  );

						$result = $this->db->affected_rows();

						if (!$result)
							break;
					}
					elseif ($act == "INSERT") {
						$result = false;

						$this->db->query(
						  "INSERT INTO ". $this->config['sql_table_name'] ."
						   (".$this->config['sql_username_field'].", ".$this->config['sql_preference_field'].", ".$this->config['sql_value_field'].")
						   VALUES ('". $_SESSION['username']. "', '". $prefs[$idx] . "', '". $vals[$idx] ."')"
						  );

						$result = $this->db->affected_rows();

						if (!$result)
							break;
					}
				}
			}

			if ($result)
				$this->api->output->command('display_message', $this->gettext('sauserprefchanged'), 'confirmation');
			else
				$this->api->output->command('display_message', $this->gettext('sauserpreffailed'), 'error');
		}
		else {
			$this->api->output->command('display_message', $this->gettext('sauserpreffailed'), 'error');
		}

		// go to next step
		rcmail_overwrite_action('plugin.sauserprefs.edit');
		$this->_load_user_prefs();
		$this->init_html();
	}

	function whitelist_import()
	{
		$contacts = new rcube_contacts(rcmail::get_instance()->db, $_SESSION['user_id']);
		$contacts->page_size = 0;
		$result = $contacts->list_records();

		if (empty($result) || $result->count == 0)
			return;

		while ($row = $result->next())
			$this->api->output->command('sauserprefs_addressrule_import', $row['email'], '', '');
	}

	function contact_add($args)
	{
		// only works with default address book
		if ($args['source'] != 0 && $args['source'] != null)
			return;

		$this->_load_config();
		if (!$this->config['whitelist_sync'])
			return;

		$this->_db_connect('w');
		$email = $args['record']['email'];

		// check address is not already whitelisted
		$sql_result = $this->db->query("SELECT value FROM ". $this->config['sql_table_name'] ." WHERE ". $this->config['sql_username_field'] ." = '". $_SESSION['username'] ."' AND ". $this->config['sql_preference_field'] ." = 'whitelist_from' AND ". $this->config['sql_value_field'] ." = '". $email ."';");
		if ($this->db->num_rows($sql_result) == 0)
			$this->db->query("INSERT INTO ". $this->config['sql_table_name'] ." (". $this->config['sql_username_field'] .", ". $this->config['sql_preference_field'] .", ". $this->config['sql_value_field'] .") VALUES ('". $_SESSION['username'] ."', 'whitelist_from', '". $email ."');");
	}

	function contact_save($args)
	{
		// only works with default address book
		if ($args['source'] != 0 && $args['source'] != null)
			return;

		$this->_load_config();
		if (!$this->config['whitelist_sync'])
			return;

		$this->_db_connect('w');
		$contacts = new rcube_contacts(rcmail::get_instance()->db, $_SESSION['user_id']);
		$old_email = $contacts->get_record($args['id'], true);
		$old_email = $old_email['email'];
		$email = $args['record']['email'];

		// check address is not already whitelisted
		$sql_result = $this->db->query("SELECT value FROM ". $this->config['sql_table_name'] ." WHERE ". $this->config['sql_username_field'] ." = '". $_SESSION['username'] ."' AND ". $this->config['sql_preference_field'] ." = 'whitelist_from' AND ". $this->config['sql_value_field'] ." = '". $email ."';");
		if ($this->db->num_rows($sql_result) == 0)
			$this->db->query("UPDATE ". $this->config['sql_table_name'] ." SET ". $this->config['sql_value_field'] ." = '". $email ."' WHERE ". $this->config['sql_username_field'] ." = '". $_SESSION['username'] ."' AND ". $this->config['sql_preference_field'] ." = 'whitelist_from' AND ". $this->config['sql_value_field'] ." = '". $old_email ."';");
	}

	function contact_delete($args)
	{
		// only works with default address book
		if ($args['source'] != 0 && $args['source'] != null)
			return;

		$this->_load_config();
		if (!$this->config['whitelist_sync'])
			return;

		$this->_db_connect('w');
		$contacts = new rcube_contacts(rcmail::get_instance()->db, $_SESSION['user_id']);
		$email = $contacts->get_record($args['id'], true);
		$email = $email['email'];

		$this->db->query("DELETE FROM ". $this->config['sql_table_name'] ." WHERE ". $this->config['sql_username_field'] ." = '". $_SESSION['username'] ."' AND ". $this->config['sql_preference_field'] ." = 'whitelist_from' AND ". $this->config['sql_value_field'] ." = '". $email ."';");
	}

	private function _load_config()
	{
		$fpath = $this->home.'/config.inc.php';
		if (is_file($fpath) && is_readable($fpath)) {
			ob_start();
			include($fpath);
			$this->config = (array)$sauserprefs_config;
			ob_end_clean();

			// update deprecated prefs
			foreach ($this->deprecated_prefs as $old => $new) {
				if ($this->config['default_prefs'][$old]) {
					$this->config['default_prefs'][$new] = $this->config['default_prefs'][$old];
					unset($this->config['default_prefs'][$old]);
				}
			}
		}
		else {
			raise_error(array(
				'code' => 527,
				'type' => 'php',
				'message' => "Failed to load SAUserprefs plugin config"), TRUE, TRUE);
		}
	}

	private function _db_connect($mode)
	{
		$this->db = new rcube_mdb2($this->config['db_dsnw'], $this->config['db_dsnr'], $this->config['db_persistent']);
		$this->db->db_connect($mode);

		// check DB connections and exit on failure
		if ($err_str = $this->db->is_error()) {
		  raise_error(array(
		    'code' => 603,
		    'type' => 'db',
		    'message' => $err_str), FALSE, TRUE);
		}
	}

	private function _load_global_prefs()
	{
		$global_prefs = array();

		$sql_result = $this->db->query(
		  "SELECT ". $this->config['sql_preference_field'] .", ". $this->config['sql_value_field'] ."
		   FROM   ". $this->config['sql_table_name'] ."
		   WHERE  ". $this->config['sql_username_field'] ." = '\$GLOBAL'
		   AND    ". $this->config['sql_preference_field'] ." <> 'whitelist_from'
		   AND    ". $this->config['sql_preference_field'] ." <> 'blacklist_from'
		   AND    ". $this->config['sql_preference_field'] ." <> 'whitelist_to';"
	   	  );

		while ($sql_result && ($sql_arr = $this->db->fetch_assoc($sql_result)))
		    $global_prefs[$sql_arr[$this->config['sql_preference_field']]] = $sql_arr[$this->config['sql_value_field']];

		// update deprecated prefs
		foreach ($this->deprecated_prefs as $old => $new) {
			if ($global_prefs[$old]) {
				$this->db->query(
					  "UPDATE ". $this->config['sql_table_name'] ."
					   SET    ". $this->config['sql_preference_field'] ." = '". $new ."'
					   WHERE  ". $this->config['sql_username_field'] ." = '\$GLOBAL'
					   AND    ". $this->config['sql_preference_field'] ." = '". $old ."';"
					  );

				$global_prefs[$new] = $global_prefs[$old];
				unset($global_prefs[$old]);
			}
		}

		$global_prefs = array_merge($this->config['default_prefs'], $global_prefs);
		$this->global_prefs = $global_prefs;
	}

	private function _load_user_prefs()
	{
		$user_prefs = array();

		$sql_result = $this->db->query(
		  "SELECT ". $this->config['sql_preference_field'] .", ". $this->config['sql_value_field'] ."
		   FROM   ". $this->config['sql_table_name'] ."
		   WHERE  ". $this->config['sql_username_field'] ." = '". $_SESSION['username'] ."'
		   AND    ". $this->config['sql_preference_field'] ." <> 'whitelist_from'
		   AND    ". $this->config['sql_preference_field'] ." <> 'blacklist_from'
		   AND    ". $this->config['sql_preference_field'] ." <> 'whitelist_to';"
		  );

	    while ($sql_result && ($sql_arr = $this->db->fetch_assoc($sql_result)))
		    $user_prefs[$sql_arr[$this->config['sql_preference_field']]] = $sql_arr[$this->config['sql_value_field']];

		// update deprecated prefs
		foreach ($this->deprecated_prefs as $old => $new) {
			if ($user_prefs[$old]) {
				$this->db->query(
					  "UPDATE ". $this->config['sql_table_name'] ."
					   SET    ". $this->config['sql_preference_field'] ." = '". $new ."'
					   WHERE  ". $this->config['sql_username_field'] ." = '". $_SESSION['username'] ."'
					   AND    ". $this->config['sql_preference_field'] ." = '". $old ."';"
					  );

				$user_prefs[$new] = $user_prefs[$old];
				unset($user_prefs[$old]);
			}
		}

		$this->user_prefs = $user_prefs;
	}

	private function _prefs_block($part, $attrib)
	{
		switch ($part)
		{
		// General tests
		case 'general':
			$out = '';
			$data = '';

			if ($this->config['general_settings']['score']) {
				$field_id = 'rcmfd_spamthres';
				$input_spamthres = new html_select(array('name' => '_spamthres', 'id' => $field_id));
				$input_spamthres->add($this->gettext('defaultscore'), '');

				$decPlaces = 0;
				if ($this->config['score_inc'] - (int)$this->config['score_inc'] > 0)
					$decPlaces = strlen($this->config['score_inc'] - (int)$this->config['score_inc']) - 2;

				$score_found = false;
				for ($i = 1; $i <= 10; $i = $i + $this->config['score_inc']) {
					$input_spamthres->add(number_format($i, $decPlaces), (float)$i);

					if (!$score_found && $this->user_prefs['required_score'] && (float)$this->user_prefs['required_score'] == (float)$i)
						$score_found = true;
				}

				if (!$score_found && $this->user_prefs['required_score'])
					$input_spamthres->add(str_replace('%s', $this->user_prefs['required_score'], $this->gettext('otherscore')), (float)$this->user_prefs['required_score']);

				$table = new html_table(array('class' => 'generalprefstable', 'cols' => 2));
				$table->add('title', html::label($field_id, Q($this->gettext('spamthres'))));
				$table->add(null, $input_spamthres->show((float)$this->user_prefs['required_score']));

				$data = $table->show() . Q($this->gettext('spamthresexp')) . '<br /><br />';
			}

			if ($this->config['general_settings']['subject']) {
				$table = new html_table(array('class' => 'generalprefstable', 'cols' => 2));

				$field_id = 'rcmfd_spamsubject';
				$input_spamsubject = new html_inputfield(array('name' => '_spamsubject', 'id' => $field_id, 'value' => $this->user_prefs['rewrite_header Subject'], 'style' => 'width:200px;'));

				$table->add('title', html::label($field_id, Q($this->gettext('spamsubject'))));
				$table->add(null, $input_spamsubject->show());

				$table->add(null, "&nbsp;");
				$table->add(null, Q($this->gettext('spamsubjectblank')));

				$data .= $table->show();
			}

			if (!empty($data))
				$out .= html::tag('fieldset', null, html::tag('legend', null, Q($this->gettext('mainoptions'))) . $data);

			if ($this->config['general_settings']['language']) {
				$data = html::p(null, Q($this->gettext('spamlangexp')));

				$table = new html_table(array('class' => 'langprefstable', 'cols' => 2));

				$select_all = $this->api->output->button(array('command' => 'plugin.sauserprefs.select_all_langs', 'type' => 'link', 'label' => 'all'));
				$select_none = $this->api->output->button(array('command' => 'plugin.sauserprefs.select_no_langs', 'type' => 'link', 'label' => 'none'));
				$select_invert = $this->api->output->button(array('command' => 'plugin.sauserprefs.select_invert_langs', 'type' => 'link', 'label' => 'invert'));

				$table->add(array('colspan' => 2, 'id' => 'listcontrols'), $this->gettext('select') .":&nbsp;&nbsp;". $select_all ."&nbsp;&nbsp;". $select_invert ."&nbsp;&nbsp;". $select_none);
				$table->add_row();

				$enable_button = html::img(array('src' => $attrib['enableicon'], 'alt' => $this->gettext('enabled'), 'border' => 0));
				$disable_button = html::img(array('src' => $attrib['disableicon'], 'alt' => $this->gettext('disabled'), 'border' => 0));

				$lang_table = new html_table(array('id' => 'spam-langs-table', 'class' => 'records-table', 'cellspacing' => '0', 'cols' => 2));
				$lang_table->add_header(array('colspan' => 2), $this->gettext('language'));
				$lang_table->set_row_attribs(array('style' => 'display: none;'));
				$lang_table->add(array('id' => 'enable_button'), $enable_button);
				$lang_table->add(array('id' => 'disable_button'), $disable_button);

				if ($this->user_prefs['ok_locales'] == "all")
					$ok_locales = array_keys($this->config['languages']);
				else
					$ok_locales = explode(" ", $this->user_prefs['ok_locales']);

				$i = 0;
				foreach ($this->config['languages'] as $lang_code => $name) {
					if (in_array($lang_code, $ok_locales)) {
						$button = $this->api->output->button(array('command' => 'plugin.sauserprefs.message_lang', 'prop' => $lang_code, 'type' => 'link', 'id' => 'spam_lang_' . $i, 'title' => 'sauserprefs.enabled', 'label' => '{[button]}'));
						$button = str_replace('[{[button]}]', $enable_button, $button);
					}
					else {
						$button = $this->api->output->button(array('command' => 'plugin.sauserprefs.message_lang', 'prop' => $lang_code, 'type' => 'link', 'id' => 'spam_lang_' . $i, 'title' => 'sauserprefs.disabled', 'label' => '{[button]}'));
						$button = str_replace('[{[button]}]', $disable_button, $button);
					}

					$input_spamlang = new html_checkbox(array('style' => 'display: none;', 'name' => '_spamlang[]', 'value' => $lang_code));

					$lang_table->add('lang', $name);
					$lang_table->add('tick', $button . $input_spamlang->show(in_array($lang_code, $ok_locales) ? $lang_code : ''));

					$i++;
				}

				$table->add(array('colspan' => 2), html::div(array('id' => 'spam-langs-cont'), $lang_table->show()));
				$table->add_row();

				$out .= html::tag('fieldset', null, html::tag('legend', null, Q($this->gettext('langoptions'))) . $data . $table->show());
			}

			break;

		// Header settings
		case 'headers':
			$data = html::p(null, Q($this->gettext('headersexp')));

			$field_id = 'rcmfd_spamfoldheaders';
			$input_spamreport = new html_checkbox(array('name' => '_spamfoldheaders', 'id' => $field_id, 'value' => '1'));
			$data .= $input_spamreport->show($this->user_prefs['fold_headers']) ."&nbsp;". html::label($field_id, Q($this->gettext('foldheaders'))) . "<br />";

			if ($this->user_prefs['remove_header all'] != 'Level') {
				$enabled = "1";
				$char = $this->user_prefs['add_header all Level'];
				$char = substr($char, 7, 1);
			}
			else {
				$enabled = "0";
				$char = "*";
			}

			$field_id = 'rcmfd_spamlevelstars';
			$input_spamreport = new html_checkbox(array('name' => '_spamlevelstars', 'id' => $field_id, 'value' => '1',
				'onchange' => JS_OBJECT_NAME . '.sauserprefs_toggle_level_char(this)'));
			$data .= $input_spamreport->show($enabled) ."&nbsp;". html::label($field_id, Q($this->gettext('spamlevelstars'))) . "<br />";

			$field_id = 'rcmfd_spamlevelchar';
			$input_spamsubject = new html_inputfield(array('name' => '_spamlevelchar', 'id' => $field_id, 'value' => $char,
				'style' => 'width:20px;', 'disabled' => $enabled?0:1));
			$data .= html::span(array('style' => 'padding-left: 30px;'), $input_spamsubject->show() ."&nbsp;". html::label($field_id, Q($this->gettext('spamlevelchar'))));

			$out = html::tag('fieldset', null, html::tag('legend', null, Q($this->gettext('mainoptions'))) . $data);
			break;

		// Test settings
		case 'tests':
			$data = html::p(null, Q($this->gettext('spamtestssexp')));

			$field_id = 'rcmfd_spamuserazor1';
			$input_spamtest = new html_checkbox(array('name' => '_spamuserazor1', 'id' => $field_id, 'value' => '1'));
			$data .= $input_spamtest->show($this->user_prefs['use_razor1']) ."&nbsp;". html::label($field_id, Q($this->gettext('userazor1'))) . "<br />";

			$field_id = 'rcmfd_spamuserazor2';
			$input_spamtest = new html_checkbox(array('name' => '_spamuserazor2', 'id' => $field_id, 'value' => '1'));
			$data .= $input_spamtest->show($this->user_prefs['use_razor2']) ."&nbsp;". html::label($field_id, Q($this->gettext('userazor2'))) . "<br />";

			$field_id = 'rcmfd_spamusepyzor';
			$input_spamtest = new html_checkbox(array('name' => '_spamusepyzor', 'id' => $field_id, 'value' => '1'));
			$data .= $input_spamtest->show($this->user_prefs['use_pyzor']) ."&nbsp;". html::label($field_id, Q($this->gettext('usepyzor'))) . "<br />";

			$field_id = 'rcmfd_spamusebayes';
			$input_spamtest = new html_checkbox(array('name' => '_spamusebayes', 'id' => $field_id, 'value' => '1'));
			$data .= $input_spamtest->show($this->user_prefs['use_bayes']) ."&nbsp;". html::label($field_id, Q($this->gettext('usebayes'))) . "<br />";

			$field_id = 'rcmfd_spamusedcc';
			$input_spamtest = new html_checkbox(array('name' => '_spamusedcc', 'id' => $field_id, 'value' => '1'));
			$data .= $input_spamtest->show($this->user_prefs['use_dcc']) ."&nbsp;". html::label($field_id, Q($this->gettext('usedcc'))) . "<br />";

			if ($this->user_prefs['skip_rbl_checks'] == "1")
				$enabled = "0";
			else
				$enabled = "1";

			$field_id = 'rcmfd_spamskiprblchecks';
			$input_spamtest = new html_checkbox(array('name' => '_spamskiprblchecks', 'id' => $field_id, 'value' => '1'));
			$data .= $input_spamtest->show($enabled) ."&nbsp;". html::label($field_id, Q($this->gettext('skiprblchecks'))) . "<br />";

			$out = html::tag('fieldset', null, html::tag('legend', null, Q($this->gettext('mainoptions'))) . $data);
			break;

		// Report settings
		case 'report':
			$data = html::p(null, Q($this->gettext('spamreport')));

			$field_id = 'rcmfd_spamreport';
			$input_spamreport0 = new html_radiobutton(array('name' => '_spamreport', 'id' => $field_id.'_0', 'value' => '0'));
			$data .= $input_spamreport0->show($this->user_prefs['report_safe']) ."&nbsp;". html::label($field_id .'_0', Q($this->gettext('spamreport0'))) . "<br />";

			$input_spamreport1 = new html_radiobutton(array('name' => '_spamreport', 'id' => $field_id.'_1', 'value' => '1'));
			$data .= $input_spamreport1->show($this->user_prefs['report_safe']) ."&nbsp;". html::label($field_id .'_1', Q($this->gettext('spamreport1'))) . "<br />";

			$input_spamreport2 = new html_radiobutton(array('name' => '_spamreport', 'id' => $field_id.'_2', 'value' => '2'));
			$data .= $input_spamreport2->show($this->user_prefs['report_safe']) ."&nbsp;". html::label($field_id .'_2', Q($this->gettext('spamreport2'))) . "<br />";

			$out = html::tag('fieldset', null, html::tag('legend', null, Q($this->gettext('mainoptions'))) . $data);
			break;

		// Address settings
		case 'addresses':
			$data = html::p(null, Q($this->gettext('whitelistexp')));

			if ($this->config['whitelist_sync'])
				$data .= Q($this->gettext('autowhitelist')) . "<br /><br />";

			$table = new html_table(array('class' => 'addressprefstable', 'cols' => 3));
			$field_id = 'rcmfd_spamaddressrule';
			$input_spamaddressrule = new html_select(array('name' => '_spamaddressrule', 'id' => $field_id));
			$input_spamaddressrule->add($this->gettext('whitelist_from'),'whitelist_from');
			$input_spamaddressrule->add($this->gettext('blacklist_from'), 'blacklist_from');
			$input_spamaddressrule->add($this->gettext('whitelist_to'), 'whitelist_to');

			$field_id = 'rcmfd_spamaddress';
			$input_spamaddress = new html_inputfield(array('name' => '_spamaddress', 'id' => $field_id, 'style' => 'width:200px;'));

			$field_id = 'rcmbtn_add_address';
			$button_addaddress = $this->api->output->button(array('command' => 'plugin.sauserprefs.addressrule_add', 'type' => 'input', 'class' => 'button', 'label' => 'sauserprefs.addrule', 'style' => 'width: 75px;'));

			$table->add(null, $input_spamaddressrule->show());
			$table->add(null, $input_spamaddress->show());
			$table->add(array('align' => 'right'), $button_addaddress);
			$table->add(array('colspan' => 3), "&nbsp;");
			$table->add_row();

			$import = $this->api->output->button(array('command' => 'plugin.sauserprefs.import_whitelist', 'type' => 'link', 'label' => 'import', 'title' => 'sauserprefs.importfromaddressbook'));
			$delete_all = $this->api->output->button(array('command' => 'plugin.sauserprefs.whitelist_delete_all', 'type' => 'link', 'label' => 'sauserprefs.deleteall'));

			$table->add(array('colspan' => 3, 'id' => 'listcontrols'), $import ."&nbsp;&nbsp;". $delete_all);
			$table->add_row();

			$address_table = new html_table(array('id' => 'address-rules-table', 'class' => 'records-table', 'cellspacing' => '0', 'cols' => 3));
			$address_table->add_header(array('width' => '180px'), $this->gettext('rule'));
			$address_table->add_header(null, $this->gettext('email'));
			$address_table->add_header(array('width' => '40px'), '&nbsp;');

			$this->_address_row($address_table, null, null, $attrib);

			$sql_result = $this->db->query(
			  "SELECT ". $this->config['sql_preference_field'] .", ". $this->config['sql_value_field'] ."
			   FROM   ". $this->config['sql_table_name'] ."
			   WHERE  ". $this->config['sql_username_field'] ." = '". $_SESSION['username'] ."'
			   AND   (". $this->config['sql_preference_field'] ." = 'whitelist_from'
			   OR     ". $this->config['sql_preference_field'] ." = 'blacklist_from'
			   OR     ". $this->config['sql_preference_field'] ." = 'whitelist_to')
			   ORDER BY ". $this->config['sql_value_field'] .";"
			  );

			if ($sql_result && $this->db->num_rows($sql_result) > 0)
				$norules = 'display: none;';

			$address_table->set_row_attribs(array('style' => $norules));
			$address_table->add(array('colspan' => '3'), rep_specialchars_output($this->gettext('noaddressrules')));
			$address_table->add_row();

			$this->api->output->set_env('address_rule_count', $this->db->num_rows());

			while ($sql_result && $sql_arr = $this->db->fetch_assoc($sql_result)) {
				$field = $sql_arr[$this->config['sql_preference_field']];
				$value = $sql_arr[$this->config['sql_value_field']];

				$this->_address_row($address_table, $field, $value, $attrib);
			}

			$table->add(array('colspan' => 3), html::div(array('id' => 'address-rules-cont'), $address_table->show()));
			$table->add_row();

			if ($table->size())
				$out = html::tag('fieldset', null, html::tag('legend', null, Q($this->gettext('mainoptions'))) . $data . $table->show());

			break;

		default:
			$out = '';
		}

		return $out;
	}

	private function _address_row($address_table, $field, $value, $attrib)
	{
		if (!isset($field))
			$address_table->set_row_attribs(array('style' => 'display: none;'));

		$hidden_action = new html_hiddenfield(array('name' => '_address_rule_act[]', 'value' => ''));
		$hidden_field = new html_hiddenfield(array('name' => '_address_rule_field[]', 'value' => $field));
		$hidden_text = new html_hiddenfield(array('name' => '_address_rule_value[]', 'value' => $value));

		switch ($field) {
			case "whitelist_from":
				$fieldtxt = rep_specialchars_output($this->gettext('whitelist_from'));
				break;
			case "blacklist_from":
				$fieldtxt = rep_specialchars_output($this->gettext('blacklist_from'));
				break;
			case "whitelist_to":
				$fieldtxt = rep_specialchars_output($this->gettext('whitelist_to'));
				break;
		}

		$address_table->add(array('class' => $field), $fieldtxt);
		$address_table->add(array('class' => 'email'), $value);
		$del_button = $this->api->output->button(array('command' => 'plugin.sauserprefs.addressrule_del', 'type' => 'image', 'image' => $attrib['deleteicon'], 'alt' => 'delete', 'title' => 'delete'));
		$address_table->add('control', '&nbsp;' . $del_button . $hidden_action->show() . $hidden_field->show() . $hidden_text->show());

		return $address_table;
	}
}

?>
