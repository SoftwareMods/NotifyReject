<?php
/**
* notifyReject: plugin for osTicket 1.10+ to scan syslog and email anyone who tried to open a ticket with an invalid account
* NOTE: If you do not require registration this plugin is not needed.
* distributed via http://software-mods.com
* @copyright 2017-2018, All rights reserved.
*/
require_once(INCLUDE_DIR.'class.plugin.php');
require_once('config.php');
require_once(INCLUDE_DIR.'class.app.php');

define('notifyReject_PLUGIN_VERSION','1.0');

class notifyReject extends Plugin {
	var $config_class = 'notifyRejectPluginConfig';
	
    function maybeRunNotifyReject($foo) {
		// Check to see if there is anything to do
		//error_log("maybeRunNotifyReject-ln:18"); // uncomment to debug
		global $ost, $cfg;
        $lastrun = $foo[lastrun];
		$num_hours = $foo[num_hours];
		
		//if($lastrun == '0000-00-00 00:00:00' || 
		//error_log('lastrun: '.$lastrun); // uncomment to debug
		
		$lastruna = date_create("$lastrun");
		$today_date = date_create(date('Y-m-d H:i:s'));
		$diff = $lastruna->diff($today_date);
		$hours = $diff->h;
		$hours = $hours + ($diff->days*24);
		//error_log("hours: ".$hours."<br>");  // uncomment to debug

		if($hours >= $num_hours) {
			$checkresult = '1';
			//error_log("checkresult: ".$checkresult." hours: ".$hours." cfg hours: ".$num_hours); // uncomment to debug
		}
		else {
			//$checkresult = '0';  // comment out to allow this to run
			//self::runNotifyReject($foo); // works
			$this->runNotifyReject($foo);
			$msg = 'notifyReject reports nothing to do.';
			if($foo[log_level])
				$ost->logWarning(_S('Plugin').' '.'- notifyReject: ', $msg, false);
				//error_log("checkresult: ".$checkresult." hours: ".$hours." cfg hours: ".$num_hours); // uncomment to debug
		}
    
        if ($checkresult > '0')
            return $this->runNotifyReject($foo);
    }

    function runNotifyReject($foo) {
		//error_log("runNotifyReject-ln:52"); // uncomment to debug
		require_once(INCLUDE_DIR.'class.osticket.php'); // required for log func
		
		if(!function_exists('notifyReject_logWarning')){
			function notifyReject_logWarning($title, $message, $alert=true) {
				global $ost;
				return $ost->log(LOG_WARN, $title, $message, $alert);
			}
		}
		
		if(!function_exists('notifyReject_find_key_value')){
			function notifyReject_find_key_value($array, $key, $val) {
				if(is_array($array)) {
					foreach ($array as $item) {
						if (is_array($item) && notifyReject_find_key_value($item, $key, $val)) return true;
						if (isset($item[$key]) && $item[$key] == $val) return true;
					}
				}
			return false;
			}
		}
	
		global $ost, $cfg;
	
		$get_logs_from = "SELECT * FROM ".SYSLOG_TABLE." WHERE title = 'Ticket Denied' AND logger !='1'";
			if(($res3=db_query($get_logs_from)) && ($cnt = db_num_rows($res3))) {
				$log_msg = '';
				
				while($row3=db_assoc_array($res3,MYSQLI_ASSOC)){
					
					for ($x=0; $x<$cnt; $x++){
						
						$logid = $row3[$x]['log_id']; // log id #
						$title = $row3[$x]['title']; // log entry title
						$log = $row3[$x]['log']; // log entry
						$lcreated = $row3[$x]['created']; // date the log entry was created
						//error_log('default log id is '.$logid.' and log is '.$log);  // uncomment to debug
						
						// debugging the array
						ob_start();
						var_dump($row3);
						$resultvd = ob_get_clean();
						//error_log($resultvd); // uncomment to debug
						
						// break up 'log' on space to get the parts
						$logs = preg_split('/\s+/', $log);
						
						// clean up the parts
						$part_one = str_replace('(', '', $logs[2]);  // step one of clean addrss
						$part_one = str_replace(')', '', $part_one);  // this should be the clean email address
						$part_two = str_replace(')', '', $logs[3]);  // this should be "unregistered client"
						//error_log('email address is '.$part_one);  // uncomment to debug
						//error_log('trash is '.$part_two);  // uncomment to debug
						
						// tracking var
						$track = '1';
						
						// check email against ban list
						// the ban list should be email addresses
						$email_ban = $foo['email_ban'];
						//error_log('email_ban is '.$email_ban);  // uncomment to debug
						if (strpos($email_ban, $part_one) !== false) {
							$track = '0';
							//error_log('track1 is '.$track);  // uncomment to debug
						}
						
						// check email against banned word list
						$word_ban = $foo['word_ban'];
						//error_log('word_ban is '.$word_ban.'');  // uncomment to debug
						
						// make it an array
						$word_ban_array = explode(PHP_EOL, $word_ban);
						// for each in list check in part_one
						foreach ($word_ban_array as $word) {
							if (strpos($part_one, trim($word)) !== false) {
								//error_log('Banned word: '.trim($word).' is in the email: '.$part_one.'.');  // uncomment to debug
								$track = '0';
								//error_log('track2 is '.$track);  // uncomment to debug
							}
						}
						
						//error_log('track is '.$track);  // uncomment to debug
						
						// Let's start building the email
						$user_email_default_id = $foo['email_from'];
						// since $foo[email_from] is the email id get actual FROM address from DB
						$get_email_addresses_from = "SELECT email FROM ".EMAIL_TABLE." WHERE email_id =" .$user_email_default_id."";
						if(($res4=db_query($get_email_addresses_from)) && db_num_rows($res4)) {
							while($row4=db_fetch_array($res4)){
								$user_email_default=$row4['email'];
								//error_log('default email id is '.$user_email_default);  // uncomment to debug
							}
						}
			
						// setup email message
						$email_msg = '';  // initialize the var before we use it.
			
						// create the subject
						$today_dated = new DateTime(); // get today
						$today_date = $today_dated->format('Y-m-d H:i:s');  // format the date
						$subject = '[notifyReject] Ticket Rejected on '.$today_date;  // make subject

						// uncomment these lines to troubleshoot mail
						//error_log("FROM: $user_email_default");  // uncomment to debug
						//error_log(" TO:  $part_one");  // uncomment to debug
						//error_log("SUBJECT: $subject");  // uncomment to debug
			
						// make the headers
						$headers = "To: $part_one"."\r\n"
							."From: $user_email_default"."\r\n"
							."Subject: $subject"."\r\n"
							.'MIME-Version: 1.0'."\r\n";
						$headers = $foo['html_toggle'] ? ($headers .= 'Content-type: text/html;charset=iso-8859-1'."\r\n") : ($headers .= 'Content-type: text/plain;charset=utf-8'."\r\n");

						
						// make message body
						if (!empty($foo[notify_message])) {
							$email_msg .= $foo[notify_message];
						}
						else {
							$email_msg .= "Greetings $part_one,<br>";
							$email_msg .= "We noticed that you tried to email us a ticket.  Unfortunately since you never registered for an account this<br>";
							$email_msg .= "email was rejected.  Please go to ".$foo['helpdesk_url']."<br>";
							$email_msg .= "and register for an account after which you can either open a ticket directly or resend your email.<br>";
							$email_msg .= "Thank you,<br>";
							$email_msg .= $foo['company']."<br>";
							$email_msg .= "- The notifyReject Plugin for osTicket<br>";
						}
						
						// change between HTML and plain text.
						$needles = array('<br>', '<b>', '</b>');
						$replacers = array('\r\n', '', '');
						$email_msg = $foo['html_toggle'] ? $email_msg : str_replace($needles, $replacers, $email_msg);
						
						//error_log('email msg is: '.$email_msg);  // uncomment to debug
						
						// send the email!
						mail($part_one, $subject, $email_msg, $headers, $user_email_default);
						
						// add the email to the message that will get logged once done.
						// todo: add a counter? "We emailed out # rejection messages?
						$log_msg .= "Email rejected from: $part_one<br>";
						
						// update the log entry. so it will not email this person again next time it runs.
						$updatesql = "UPDATE ".SYSLOG_TABLE." SET `logger`='1' WHERE `log_id`=".db_input($logid);
						if(!db_query($updatesql)) {
							// log if failure?
						}
						$commit = "COMMIT;";
						db_query($commit);
						db_autocommit(true);
												
					}
					
				}
				// check to see if email_toggle
				if($foo['email_toggle']) {
					//also send the email to the selected email address // osticket admin
					
					// create the subject
					$today_dated = new DateTime(); // get today
					$today_date = $today_dated->format('Y-m-d H:i:s');  // format the date
					$admin_subject = '[notifyReject] Ticket Rejections for '.$today_date;  // make subject
					
					// make the headers
					$admin_email = $foo['admin_email'];
					//error_log('foo[admin_email] is: '.$foo[admin_email]);  // uncomment to debug
					//error_log('admin_email is: '.$admin_email);  // uncomment to debug	
					$admin_headers = "To: $admin_email"."\r\n"
									."From: $user_email_default"."\r\n"
									."Subject: $subject"."\r\n"
									.'MIME-Version: 1.0'."\r\n";
					$admin_headers = $foo['html_toggle'] ? ($headers .= 'Content-type: text/html;charset=iso-8859-1'."\r\n") : ($headers .= 'Content-type: text/plain;charset=utf-8'."\r\n");
					
					//error_log('header is: '.$admin_headers);  // uncomment to debug	
							
					if(!$admin_msg) {
						$admin_msg = "Admin,<br><br>";
						$admin_msg .= "<b>*** This email is from the notifyReject Plugin for osTicket. ***</b><br><br>";
						$admin_msg .= "The following addresses were sent rejection notices:<br><br>";
						$admin_msg .= $log_msg;
						$admin_msg .= "<br><br>";
						$admin_msg .= "This notification was generated from ".$foo['helpdesk_url']."<br>";
						$admin_msg .= "- The notifyReject Plugin for osTicket<br>";
					}
						
					// change between HTML and plain text.
					$needles = array('<br>', '<b>', '</b>');
					$replacers = array('\r\n', '', '');
					$admin_msg = $foo['html_toggle'] ? $admin_msg : str_replace($needles, $replacers, $admin_msg);
					
					//die();
					// actually send the admin email.
					mail($admin_email, $admin_subject, $admin_msg, $admin_headers, $user_email_default);
				}
						
				// Log the results of this run.
				if($foo['log_toggle']) { 
					$ost->logWarning(_S('Plugin').' '.'- notifyReject: ', $admin_msg, false);
				}
			}


		// update the ost_config table plugin.# lastrun with now.
	
		//get plugin id from DB
		$idsql = "SELECT id FROM ".PLUGIN_TABLE." WHERE `name`='notifyReject';";
		if($idresult=db_query($idsql)) { 
			$num = db_num_rows($idresult);
		}
		if ($num >> 1) {
			// uh there should not be more than one notifyReject installed!
			error_log("<pre>Multiple notifyReject settings found (".$num.")</pre>");   //
			//$msg = "<pre>Multiple notifyReject settings found (".$num.")</pre>";
			//$ost->notifyReject_logWarning(_S('Plugin').' '.'- notifyReject: ', $msg, false);
			die();
		}
		else {
			$pluginid = db_result($idresult,0,"id");
			//error_log("<PRE>id is $pluginid</PRE>"); //uncomment to debug
			$namespace = "plugin.".$pluginid;
		}
		
		$updatesql = "UPDATE ".CONFIG_TABLE." SET `value`=".db_input($today_date)." WHERE `namespace`=".db_input($namespace)." AND `key`='lastrun';";
		//error_log('PLUGIN: '.$updatesql);  //uncomment to debug
		//if($updateresult=db_query($updatesql)) {
		if(db_query($updatesql)) {
			//log issue
			//error_log('PLUGIN: Affected rows: '.db_affected_rows());  //uncomment to debug
		}
	// seems like the _S shuts off autocommit.  so commit, and then turn it back on.
		$commit = "COMMIT;";
		db_query($commit);
		db_autocommit(true);
	return($msg);
    }
	
	function makeFoo() {
		require_once(INCLUDE_DIR.'class.plugin.php');
		require_once('config.php');
		require_once(INCLUDE_DIR.'class.app.php');
		
		// get and setup all the vars
		global $ost, $cfg;
		$ost = new osTicket();
		$config = $ost->getConfig();
		$foo['autocron'] = $config->get('enable_auto_cron');
		$foo['autocron_toggle'] = $this->getConfig()->get('autocron_toggle');
		$foo['num_hours'] = $this->getConfig()->get('num_hours');
		$foo['email_toggle'] = $this->getConfig()->get('email_toggle');
		$foo['html_toggle'] = $this->getConfig()->get('html_toggle');
		$foo['email_from'] = $this->getConfig()->get('email_from');
		$foo['notify_message'] = $this->getConfig()->get('notify_message');
		$foo['log_toggle'] = $this->getConfig()->get('log_toggle');
		$foo['email_ban'] = $this->getConfig()->get('email_ban');
		$foo['word_ban'] = $this->getConfig()->get('word_ban');
		$foo['helpdesk_url'] = $config->get('helpdesk_url');
		$foo['company'] = Format::htmlchars($ost->company);
		$foo['admin_email'] = $ost->getConfig()->getAdminEmail();
		//error_log('PLUGIN:admin_email: '.$foo['admin_email']);  //uncomment to debug
		$foo['namespace'] = $this->getConfig()->getNamespace();
		$foo['lastrun'] = $this->getConfig()->get('lastrun');
		return ($foo);
	}
	
	function bootstrap() {
		global $ost, $cfg;
		$foo = $this->makeFoo();
		
		//error_log(var_dump($this->getConfig())); die;  // uncomment to display the conf
		
        $autocron_toggle = $foo[autocron_toggle];
		
        if(($foo['autocron']=='0')&&($foo[autocron_toggle]=='1')) {
			$msg = 'Admin panel -> Emails -> Settings, Fetch on auto-cron is not enabled,';
			$msg .= 'But you checked Admin panel -> Manage -> Plugins -> notifyReject -> Use with Autocron';
			$msg .= '<br>If you want this plugin to run with with autocron you need to enable autocron.';
			$ost->logWarning(_S('Plugin').' '.'- notifyReject: ', $msg, false);
		}
		elseif(($foo['autocron']=='1')&&($foo[autocron_toggle]=='0')) {
			$msg = 'Admin panel -> Emails -> Settings, Fetch on auto-cron is enabled,';
			$msg .= 'But you did not check Admin panel -> Manage -> Plugins -> notifyReject -> Use with Autocron';
			$msg .= '<br>If you want this plugin to run with autocron you need to enable Use with Autocron.';
			$ost->logWarning(_S('Plugin').' '.'- notifyReject: ', $msg, false);
		}
		elseif(($foo['autocron']=='1')&&($foo[autocron_toggle]=='1')) {
            $self = $this;
            Signal::connect('cron', function($info) use ($self, $foo) {
                $self->maybeRunNotifyReject($foo);
            });
		}
		else { //do all the things!
            $self = $this;
            Signal::connect('cron', function($info) use ($self, $foo) {
                $self->maybeRunNotifyReject($foo);
            });
			//error_log('PLUGIN (notifyReject): '.$msg);  // uncomment to debug
		}
	
	}

}
?>