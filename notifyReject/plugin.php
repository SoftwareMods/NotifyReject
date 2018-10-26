<?php
/**
* notifyReject: plugin for osTicket 1.10+ to scan syslog and email anyone who tried to open a ticket with an invalid account
* NOTE: If you do not require registration this plugin is not needed.
* distributed via http://software-mods.com
* @copyright 2017-2018, All rights reserved.
*/
return array(
  'id' =>		'software-mods.com:notifyReject', # notrans
  'version' =>  '1.0',
  'name' => 	'notifyReject',
  'author' =>	'nst',
  'description' => 'notifies a User when their emailed ticket is rejected',
  'url' => 		'http://software-mods.com/notifyReject.html',
  'plugin' => 	'notifyReject.php:notifyReject'
);
?>