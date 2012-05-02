<?php
/**
 * Asterisk SugarCRM Integration
 * (c) KINAMU Business Solutions AG 2009
 *
 * Parts of this code are (c) 2006. RustyBrick, Inc.  http://www.rustybrick.com/
 * Parts of this code are (c) 2008 vertico software GmbH 
 * Parts of this code are (c) 2009 abcona e. K. Angelo Malaguarnera E-Mail admin@abcona.de
 * http://www.sugarforge.org/projects/yaai/
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License version 3 as published by the
 * Free Software Foundation with the addition of the following permission added
 * to Section 15 as permitted in Section 7(a): FOR ANY PART OF THE COVERED WORK
 * IN WHICH THE COPYRIGHT IS OWNED BY SUGARCRM, SUGARCRM DISCLAIMS THE WARRANTY
 * OF NON INFRINGEMENT OF THIRD PARTY RIGHTS.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more
 * details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program; if not, see http://www.gnu.org/licenses or write to the Free
 * Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA
 * 02110-1301 USA.
 *
 * You can contact KINAMU Business Solutions AG at office@kinamu.com
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 */

 
if(!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

require_once('include/utils.php');
require_once('include/export_utils.php');

global $sugar_config;
global $locale;
global $current_user;

// TODO What are the chdir's for??? 
/*
chdir("../");
chdir("../");
chdir("../");
chdir("../");
*/

require_once('include/entryPoint.php');
require_once('modules/Contacts/Contact.php');

#require_once('modules/Users/User.php');
#session_start();

//include language
 
//$current_language = $_SESSION['authenticated_user_language'];
if(empty($current_language)) {
	$current_language = $sugar_config['default_language'];
}
require("custom/modules/Asterisk/language/" . $current_language . ".lang.php");


// Fetch Asterisk dialprefix - must strip this from inbound callerIDs if set
$calloutPrefix = $sugar_config['asterisk_prefix'];
$callinPrefix = $sugar_config['asterisk_dialinPrefix'];


//log_entry("$current_user->asterisk_ext_c is the extension...\n", "c:\callListenerLog.txt"); // FIXME remove this debug


// Original query that returns only "Active" Calls -- query below gives me ones that were updated in last hour (so user can still put notes on them).
//$query = " SELECT * FROM asterisk_log WHERE (callstate = 'Dial' OR callstate = 'Connected') AND (channel LIKE 'SIP/{$current_user->asterisk_ext_c}%')";

$lastHour = date('Y-m-d H:i:s',time() - 1*60*60);
$query = " SELECT * FROM asterisk_log WHERE \"$lastHour\" < timestampCall AND (uistate IS NULL OR uistate != \"Closed\") AND (channel LIKE 'SIP/{$current_user->asterisk_ext_c}%' OR channel LIKE 'Local%{$current_user->asterisk_ext_c}%')";

$resultSet = $current_user->db->query($query, false);
if($current_user->db->checkError()){
	trigger_error("checkForNewStates-Query failed: $query");
}

$response = array();
while($row = $current_user->db->fetchByAssoc($resultSet)){

	$item = array();
	$item['asterisk_id'] = $row['asterisk_id'];
	
	// All modstrings are in uppercase, so thats what toupper was added for... asterisk 1.6 returns camelcase states perhaps earlier versions didn't.
	$item['state'] = isset($mod_strings[$row['callstate']]) ? $mod_strings[strtoupper($row['callstate'])] : $row['callstate'];
	$item['state'] = "'" . $item['state'] . "'";

	$item['id'] = $row['id'];
	//for opening the relevant phone record when call has been answered
	$item['call_record_id'] = $row['call_record_id'];
	
	if($row['direction'] == 'I'){

		// this call is coming in from a remote phone partner
		$item['call_type'] = "ASTERISKLBL_COMING_IN";
		$item['direction'] = "Inbound";
		$callPrefix = $callinPrefix;
			
	}

	if($row['direction'] == 'O'){

		// this call is coming in from a remote phone partner
		$item['call_type'] = "ASTERISKLBL_GOING_OUT";
		$item['direction'] = "Outbound";
		#$item['phone_number'] = $row['callerID'];
		#$item['asterisk_name'] = $row['callerName'];
		$callPrefix = $calloutPrefix;
			
	}

	// Remove prepending dialout prefix if present

	$tmpCallerID = trim($row['callerID']);
	if ( (strlen($callPrefix) > 0)  && (strpos($tmpCallerID, $callPrefix) === 0) )
	{
		$tmpCallerID = substr($tmpCallerID, strlen($callPrefix));
	}
	$item['phone_number'] = $tmpCallerID;

	#$item['phone_number'] = $row['callerID'];
	$item['asterisk_name'] = $row['callerName'];
	$item['asterisk_id'] = $row['asterisk_id'];
	$item['timestampCall'] = $row['timestampCall'];
	
	
	if( !empty($row['timestampHangup']) ) {
		$to_time=strtotime($row['timestampHangup']);
	}
	else {
		$to_time = time();
	}
	
	$from_time=strtotime($row['timestampCall']);
	$duration = number_format(round(abs($to_time - $from_time) / 60,1),1);	
	$item['duration'] = $duration;

	// prepare phone number passed in
	$phoneToFind = $item['phone_number'];

	// delete leading zeros
	$phoneToFind = ltrim($phoneToFind, '0');

	$gravEmailAddress = ""; //clear address
	$found = array();
	if(strlen($phoneToFind) > 5) {
		$sqlReplace = "
			    replace(
			    replace(
			    replace(
			    replace(
			    replace(
			    replace(
			    replace(
			    replace(
			    replace(
			      %s,
			        ' ', ''),
			        '+', ''),
			        '.', ''),
			        '/', ''),
			        '(', ''),
			        ')', ''),
			        '[', ''),
			        ']', ''),
			        '-', '')
			        REGEXP '%s$' = 1
			";
			
		//$sqlReplace= "REGEXP '%s$' = 1";

        $selectPortion =  "SELECT c.id as contact_id, first_name,	last_name,phone_work, phone_home, phone_mobile, phone_other, a.name as account_name, account_id "
            . "FROM contacts c left join accounts_contacts ac on (c.id=ac.contact_id) left join accounts a on (ac.account_id=a.id) ";

        if( $row['contact_id'] ) {
            $wherePortion = " WHERE c.id='{$row['contact_id']}'";
          //  log_entry("Quick WHERE $selectPortion $wherePortion\n", "c:\callListenerLog.txt");

        }
        // We only do this expensive query if it's not already set!
        else {
           // log_entry("Expensive WHERE", "c:\callListenerLog.txt");
            $wherePortion = " WHERE (";
            $wherePortion .= sprintf($sqlReplace, "phone_work", $phoneToFind) . " OR ";
            $wherePortion .= sprintf($sqlReplace, "phone_home", $phoneToFind) . " OR ";
            $wherePortion .= sprintf($sqlReplace, "phone_other", $phoneToFind) . " OR ";
            $wherePortion .= sprintf($sqlReplace, "assistant_phone", $phoneToFind) . " OR ";
            $wherePortion .= sprintf($sqlReplace, "phone_mobile", $phoneToFind) . ") and c.deleted='0'";
        }

        $queryContact = $selectPortion . $wherePortion;
		$innerResultSet = $current_user->db->query($queryContact, false);

		while($contactRow = $current_user->db->fetchByAssoc($innerResultSet)){
            // TODO detect when there are multiple contacts returned here and then implement the radio button select.
			$found['$contactFullName'] = $contactRow['first_name'] . " " . $contactRow['last_name'];
			$found['$company'] = $contactRow['account_name'];
			$found['$contactId'] = $contactRow['contact_id'];
			$cid =  $contactRow['contact_id'];
			$found['$companyId'] = $contactRow['account_id'];

            // If we haven't saved contact_id to database table yet...
            if( empty( $row['contact_id'] ) ) {
                // TODO improve query security... sanitize inputs.
                $insertQuery = "UPDATE asterisk_log SET contact_id='{$contactRow['contact_id']}' WHERE call_record_id='{$row['call_record_id']}'";
		        $current_user->db->query($insertQuery, false);
            }
		}

		// TODO optimize this... can I grab this some other way? This is just to get the primary email address... might be a faster way to do this?
        if( !empty($cid) && $sugar_config['asterisk_gravatar_integration_enabled'])
        {
            $bean = new Contact();
            $bean->retrieve( $cid );
            $gravEmailAddress = $bean->emailAddress->getPrimaryAddress($bean);
            //log_entry(printrs($bean), "c:\callListenerLog.txt");
        }
	}

    // TODO when no contact is found we should present a menu,
    // Multiple contacts, let user pick.
    // No Contact matching have (+) icon,
    //'<a onclick="if ( DCMenu.menu ) DCMenu.menu(\'Contacts\',\'Create Contact\', true); return false;" href="#">Create Contact</a><BR>';
    // $createNewContactLink = '<a href="index.php?module=Contacts&action=EditView&phone_work=' . $phoneToFind .'">Create  Add To  Relate</a>';


    $item['full_name'] = isset($found['$contactFullName']) ? $found['$contactFullName'] : "";//$createNewContactLink;

	$item['company'] = isset($found['$company']) ? $found['$company'] : "";
	$item['contact_id'] = isset($found['$contactId']) ? $found['$contactId'] : "";
	$item['company_id'] = isset($found['$companyId']) ? $found['$companyId'] : "";
	
	//$item['sqlQuery'] = $queryContact; // Uncomment if you want to debug the query.	y

	$response[] = $item;
	}
	
$responseArray = array();
if(count($response) == 0){
	print json_encode(array("."));
}else{
	foreach($response as $item){
	
		ob_start();
		require("custom/modules/Asterisk/include/ShowCall.html");
		$item['html'] = ob_get_contents();
		$item['html'] = str_replace("\n", "", $item['html']);
		$item['html'] = str_replace("\t", "", $item['html']);
		$item['html'] = str_replace("\r", "", $item['html']);
		ob_clean();
		

        if( $sugar_config['asterisk_gravatar_integration_enabled'] ) {
		    if( !empty($gravEmailAddress) ) {
			    $gravHash = md5( strtolower( trim( $gravEmailAddress ) ) );
		    	$item['html'] .= '<img src="http://www.gravatar.com/avatar/'. $gravHash . '?s=160">';
		    }
		    $item['html'] .= '<a onclick="if ( DCMenu.menu ) DCMenu.menu(\'Contacts\',\'Create Contact\', true); return false;" href="#">Create Contact</a><BR>';
		    $item['html'] .= '<a href="index.php?module=Contacts&action=EditView&phone_work=' . $phoneToFind .'">Number2</a>';
        }

		$responseArray[] = $item;
	}
	print json_encode($responseArray);
	ob_flush();
}

sugar_cleanup();


// just for debugging purposes
function log_entry( $str, $file = "default" ) {
	$handle = fopen($file, 'a');
	fwrite($handle, "[" . date('Y-m-j H:i:s') . "] " . $str );
	fclose($handle);
}

/// printr to string
function printrs($data) {
  $str = "";
  if ($data) {
    $str = '<pre>\n';
    $str .= print_r($data, TRUE);
    $str .= '</pre>\n';
  }
  return $str;
}

?>