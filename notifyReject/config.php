<?php
/**
* notifyReject: plugin for osTicket 1.10+ to scan syslog and email anyone who tried to open a ticket with a invalid account
* NOTE: If you do not require registration this plugin is not needed.
* distributed via http://software-mods.com
* @copyright 2017-2018, All rights reserved.
*/
require_once(INCLUDE_DIR.'/class.plugin.php');
require_once(INCLUDE_DIR.'/class.forms.php');
require_once(INCLUDE_DIR.'/class.config.php');

class notifyRejectPluginConfig extends PluginConfig {

    function getOptions() {
		
		//get plugin id from DB
		$idsql = "SELECT id FROM ".PLUGIN_TABLE." WHERE `name`='notifyReject';";
		if($idresult=db_query($idsql)) { 
			$num = db_num_rows($idresult);
		}
		if ($num >> 1) {
			// uh there should not be more than one notifyReject installed!
			print("<pre>Multiple notifyReject settings found (".$num.")</pre>");
			die();
		}
		else {
			$pluginid = db_result($idresult,0,"id");
			//print("<PRE>id is $pluginid</PRE>"); //uncomment to debug
			$namespace = "plugin.".$pluginid;
		}
		
		$lastrunsql = "SELECT `value` FROM ".CONFIG_TABLE." WHERE `namespace`='".$namespace."' AND `key`='lastrun';";
		if($lastrunresult=db_query($lastrunsql)) { 
			$num = db_num_rows($lastrunresult);
		}
		if ($num >> 0) {
			//print('records found.');
			$lastrun = db_result($lastrunresult,0,"value");
		}
		else {
			// no record found so we need to create one.
			$fauxrunsql = "INSERT INTO ".CONFIG_TABLE." VALUES ('0','".$namespace."','lastrun','0000-00-00 00:00:00',NOW())";
			if($fauxrunresult=db_query($fauxrunsql)) { 
				$num = db_num_rows($fauxrunresult);
			}
			
		}
		
		// get default email id #
		$demailsql = "SELECT value FROM ".CONFIG_TABLE." WHERE `key`='default_email_id';";
		if($demailresult=db_query($demailsql)) {
			$demail_id = db_result($demailresult,$i,"value");
		}
		
		// get all system emails
		//define('DEFAULT_EMAIL_ID',$demail_id );
		$emailssql = "SELECT email_id FROM ".EMAIL_TABLE." ORDER BY email_id;";
		if($emailsresult=db_query($emailssql)) { 
			$num = db_num_rows($emailsresult);
		}
		//echo "$emailsresult<br>";
		//print("<pre>".print_r($emailsresult,true)."</pre>");
		if ($num >> 0) {
			for ($i = 0; $i < $num; $i++) {
				$emails_id = db_result($emailsresult,$i,'email_id');
				
				$emailsnamesql = "SELECT email FROM ".EMAIL_TABLE." WHERE `email_id`=".$emails_id." ORDER BY email_id;";
				$emailsnameresult=db_query($emailsnamesql);
				if($emails_id == $demail_id) {
					$demails_name = db_result($emailsnameresult,$i,'email');
					$emails_name = $demails_name;
				}
				else {
					$emails_name = db_result($emailsnameresult,$i,'email');
				}
				//echo "id:".$emails_id." and email:".$emails_name."<br>";
				$emailarray[$emails_id] = $emails_name;
			}
			//print("<pre>".print_r($emailarray,true)."</pre>");
		}
		
		// get log_level
		$loglevelsql = "SELECT `value` FROM ".CONFIG_TABLE." WHERE `namespace`='core' AND `key`='log_level';";
		if($loglevelresult=db_query($loglevelsql)) { 
			$num = db_num_rows($loglevelresult);
		}
		if ($num >> 0) {
			$loglevel = db_result($loglevelresult,0,"value");
		}
		
		if($lastrun == '0000-00-00 00:00:00') { 
			$cfgplugin = 'Please configure the plugin below.';
		}
		
		// This plugin requires Admin panel -> Settings -> System -> Default Log Level: set to 2+ to function.		
		if(!$loglevel >= '2') {
			$logLevelCheck = 'This plugin requires Admin panel -> Settings -> System -> Default Log Level: set to 2+ to function!  ';
		}
		
		// get accept_unregistered_email
		$accept_unregistered_emailsql = "SELECT `value` FROM ".CONFIG_TABLE." WHERE `namespace`='core' AND `key`='accept_unregistered_email';";
		if($accept_unregistered_emailresult=db_query($accept_unregistered_emailsql)) { 
			$num = db_num_rows($accept_unregistered_emailresult);
		}
		if ($num >> 0) {
			$accept_unregistered_email = db_result($accept_unregistered_emailresult,0,"value");
		}
		
		// This plugin is not needed if Admin panel -> Email -> Settings -> Accept all emails is checked
		if($accept_unregistered_email != '0') {
			$logLevelCheck = ' This plugin is not needed if Admin panel -> Email -> Settings -> Accept all emails is checked!  ';
		}
		
		if(THIS_VERSION) {
			$tv = MAJOR_VERSION;
			if($tv != '1.10') {
		     	$ostvercheck = "osTicket Version Check: You are running ".THIS_VERSION.". This plugin was written for 1.10+..<br>This plugin is version: ".$info['version']."<br>";
			}
			else {
				$ostvercheck = 'osTicket Version Check: version okay. '.$cfgplugin;
			}
		}
				
        $basiccfg = array(
			'warn' => new SectionBreakField(array(
                'label' => $logLevelCheck,
				'hint' => $ostvercheck
            )),			
			'text' => new SectionBreakField(array(
                'label' => 'Plugin Options',
				'hint' => 'This section controls what you want the plugin to do and how it behaves'
            )),			
            'autocron_toggle' => new BooleanField(array(
                'id' => 's6r',
                'label' => ' Use with Autocron',
                'configuration' => array(
                    'desc' => 'requires Admin panel -> Emails -> Settings Fetch on auto-cron to be enabled '),
				'hint' => 'Makes the plugin run with Autocron.'
            )),
            'num_hours' => new ChoiceField(array(
                'label' => 'Hours',
				'hint' => 'Run check every X hours',
                'default' => '12',
                'choices' => array(
                    '1' => '1',
                    '2' => '2',
                    '3' => '3',
                    '4' => '4',
                    '6' => '6',
                    '8' => '8',
                    '10' => '10',
					'12' => '12',
					'24' => '24',
				),
            )),
			'email_toggle' => new BooleanField(array(
                'id' => 'emto',
                'label' => ' Email admin',
                'configuration' => array(
                'desc' => 'Email Admin with results?'),
				'hint' => 'This will send one email to the admin with a digest of all rejections since last run.'
            )),
			'html_toggle' => new BooleanField(array(
                'id' => 'html_toggle',
                'label' => ' Toggle HTML',
				'default' => '1',
                'configuration' => array(
                'desc' => 'Use HTML in email')
            )),
			'email_from' => new ChoiceField(array(
                'label' => 'From Address',
				'hint' => 'System Default: '.$demails_name,
                'default' => $demail_id,
                'choices' => $emailarray,
            )),
			'notify_message' => new TextareaField(array(
                'id' => 'notifymsg',
                'label' => 'Notification Message',
                'configuration' => array('html'=>false, 'rows'=>6, 'cols'=>80),
                'hint' => 'Leaving this blank will use a default message.',
            )),
			'log_toggle' => new BooleanField(array(
                'id' => 'log_toggle',
                'label' => ' Log Results',
                'configuration' => array(
                'desc' => 'Should we log each message sent to the syslog?')
            )),
			'email_ban' => new TextareaField(array(
                'id' => 'email_ban',
                'label' => 'email banned list',
                'configuration' => array('html'=>false, 'rows'=>6, 'cols'=>80),
                'hint' => 'Place one server entry per line.',
            )),
			'word_ban' => new TextareaField(array(
                'id' => 'word_ban',
                'label' => 'Word banned list',
                'configuration' => array('html'=>false, 'rows'=>6, 'cols'=>80),
                'hint' => 'Place one server entry per line.',
            )),
        );
		
		$plugincfg = $basiccfg;
	return $plugincfg;
    }

    function pre_save(&$config, &$errors) {
			
        global $msg;
        if (!$errors)
            $msg = 'notifyReject configuration updated successfully';

        return !$errors;
    }
}
?>
