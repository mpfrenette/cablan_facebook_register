<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2010 Martin-Pierre Frenette <typo3@cablan.net>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

require_once(PATH_tslib.'class.tslib_pibase.php');

require_once(t3lib_extMgm::extPath('cablan_facebook_register').'lib/facebook.php');

/**
 * Plugin 'Facebook User Registration and Login' for the 'cablan_facebook_register' extension.
 *
 * this plugin allows users to register as Front-End users using Facebook (the API in place on 2010-08-04) 
 *
 * @author	Martin-Pierre Frenette <typo3@cablan.net>
 * @package	TYPO3
 * @subpackage	tx_cablanfacebookregister
 */
class tx_cablanfacebookregister_pi1 extends tslib_pibase {
	var $prefixId      = 'tx_cablanfacebookregister_pi1';		// Same as class name
	var $scriptRelPath = 'pi1/class.tx_cablanfacebookregister_pi1.php';	// Path to this script relative to the extension dir.
	var $extKey        = 'cablan_facebook_register';	// The extension key.
	var $pi_checkCHash = true;
	
	/**
	 * The main method of the PlugIn, this is the plugin which allows
	 * to register.
	 *
	 * @param	string		$content: The PlugIn content
	 * @param	array		$conf: The PlugIn configuration
	 * @return	The content that is displayed on the website
	 */
	function main($content, $conf) {
		$this->conf = $conf;
		$this->pi_setPiVarDefaults();
		$this->pi_loadLL();
		
		
		
		if ( $this->conf['usersPid'] == 0 && $this->conf['fbusersPid'] > 0 ) {
			$this->conf['usersPid'] = $this->conf['fbusersPid'];
		}
		if ( $this->conf['apiKey'] == 0 && $this->conf['fbapiKey'] > 0 ) {
			$this->conf['apiKey'] = $this->conf['fbapiKey'];
		}
		if ( $this->conf['secret'] == 0 && $this->conf['fbsecret'] > 0 ) {
			$this->conf['secret'] = $this->conf['fbsecret'];
		}
		
		
		$facebook = new Facebook(array(
			'appId'  => $this->conf['apiKey'],
			'secret' => $this->conf['secret'],
			'cookie' => true ));
		
		// We may or may not have this data based on a $_GET or $_COOKIE based session.
		//
		// If we get a session here, it means we found a correctly signed session using
		// the Application Secret only Facebook and the Application know. We dont know
		// if it is still valid until we make an API call using the session. A session
		// can become invalid if it has already expired (should not be getting the
		// session back in this case) or if the user logged out of Facebook.
		
		$session = $facebook->getSession();
		
		// this is the variable which will hold the result of the me api call to Facebook
		$me = null;

		// Session based API call.
		if ($session) {
		  try {
			$uid = $facebook->getUser();
			$me = $facebook->api('/me');
		  } catch (FacebookApiException $e) {
			error_log($e);
		  }
		}
		
		// login or logout url will be needed depending on current user state.
		if ($me) {
		  $logoutUrl = $facebook->getLogoutUrl();
		} else {
		  $loginUrl = $facebook->getLoginUrl();
		}

		
		$content = '  <!--
      We use the JS SDK to provide a richer user experience. For more info,
      look here: http://github.com/facebook/connect-js
    -->
    <div id="fb-root"></div>
    <script>
      window.fbAsyncInit = function() {
        FB.init({
          appId   : '.$facebook->getAppId().',
          session : '.json_encode($session).', // do not refetch the session when PHP already has it
          status  : true, // check login status
          cookie  : true, // enable cookies to allow the server to access the session
          xfbml   : true // parse XFBML
        });

        // whenever the user logs in, we refresh the page
        FB.Event.subscribe(\'auth.login\', function() {
          window.location.reload();
        });
      };

      (function() {
        var e = document.createElement(\'script\');
        e.src = document.location.protocol + \'//connect.facebook.net/en_US/all.js\';
        e.async = true;
        document.getElementById(\'fb-root\').appendChild(e);
      }());
    </script>';
	
	// if we do not have a me result, put the Facebook login...
	if (!$me ){
		$content .= '<div>
			<a href="'.$loginUrl.'">
			  <img src="http://static.ak.fbcdn.net/rsrc.php/zB6N8/hash/4li2k73z.gif">
			</a>
		  </div>
		  ';	
	}
    
    // we have a Facebook API result, and a me result! We can try to register the user.
	if ( $session && $me){
		if ( !$this->ValidFacebookUser($uid)){
			if ( $row = $this->GetUserFromFacebookID($uid) ){
				
				
				$this->LoginUser($row);
				header('Location: '.$this->pi_getPageLink($GLOBALS['TSFE']->id));
				exit();				
				
			}else{
				
			$content .= '<h3>Register via Facebook!</h3>
		    <img src="https://graph.facebook.com/'.$uid.'/picture">
		    '. $me['name'];
			
				
			$values = array();
			
		    // If we have a location, show that we will store it.
			if ( $me['location']['name'] != ''){
				$content .= '<h3>Information we will store about you</h3>';
				$loc = $me['location']['name'];
				$content .= '<p><label>Location:</label>'.$loc .'</p>';
				$values['city'] = $loc;
				}
			
			// This is the result from the form... it does the actual creation.
			if ( $this->piVars['register_uid'] ){
				$content .= 'Registering';
				$values['tx_cablanfacebookregister_facebook_user'] = $uid;
				if ( t3lib_extMgm::isLoaded('fbconnect')){
					// we make sure to also set the Facebook id in the fbconnect extension,
					// so it will also work. It doesn't allow to register, but it does allow
					// to login.
					$values['tx_fbconnect_user'] = $uid;
				}
				$values['name'] = $me['name'];
				$values['usergroup'] = $this->conf['usergroups'];
				
				
				$user = $this->CreateUser($values);
				$this->LoginUser($user );
				header('Location: '.$this->pi_getPageLink($GLOBALS['TSFE']->id));
				exit();		
				
			}
				
				
			$content .='<form action="'.$this->pi_getPageLink($GLOBALS['TSFE']->id).'" method="POST">
						<input type="hidden" name="'.$this->prefixId.'[register_uid]" value="'.$uid.'"/>
						<input type="submit" name="'.$this->prefixId.'[submit_button]" value="'.htmlspecialchars($this->pi_getLL('register','Register')).'">
					</form>
					';
			$content .= '<br /><p><a href="'. $logoutUrl.'">
					<img src="http://static.ak.fbcdn.net/rsrc.php/z2Y31/hash/cxrz4k7j.gif">
				  </a></p>';
			
			
			}
			
		}
		else{
			$content .= '<h3>You are connected via Facebook</h3>
	    <img src="https://graph.facebook.com/'.$uid.'/picture">
	    '. $me['name'];
		
				$content .= '<p><a href="'. $logoutUrl.'">
				<img src="http://static.ak.fbcdn.net/rsrc.php/z2Y31/hash/cxrz4k7j.gif">
			  </a></p>';
		}
    }
	else{
     	$content .= '<strong>You are not Connected with Facebook.</strong>';
	}
    

		return $this->pi_wrapInBaseClass($content);
	}
	
	/**
	 * This function attempts to load a TYPO3 user based on the Facebook id.
	 *
	 * If the more generic Facebook connect extension is loaded, it will also search it,
	 * but fbconnect only allows to connect Facebook, it doesn't let you register.
	 * 
	 * @param int $id Facebook id field.
	 */
	function GetUserFromFacebookID($id){
	
		$where = '(tx_cablanfacebookregister_facebook_user="'. $id. '" ';
		if ( t3lib_extMgm::isLoaded('fbconnect')){
			$where .= ' OR tx_fbconnect_user="'. $id.'"';	
		}
		$where .= ') ';
	
	
		$result = $GLOBALS['TYPO3_DB']->exec_SELECTquery('*','fe_users',$where . $this->cObj->enableFields('fe_users'));
		
		if ($result && $GLOBALS['TYPO3_DB']->sql_num_rows($result ) > 0 ){
			return $GLOBALS['TYPO3_DB']->sql_fetch_assoc($result);
		}
		
		
	}
	
	/**
	 * This function returns true if the facebook user is already registered.
	 * @param [type] $uid [description]
	 */
	function ValidFacebookUser($uid){
		
		if ( $GLOBALS["TSFE"]->fe_user->user['uid'] > 0 &&
			($GLOBALS["TSFE"]->fe_user->user['tx_cablanfacebookregister_facebook_user'] == $uid ||
			$GLOBALS["TSFE"]->fe_user->user['tx_fbconnect_user'] == $uid )){
			return true;
		}
		else{
			return false;
		}
		
	}
	/**
	 * This function will login a user from either his uid or his row.
	 * @param int/array $user either the user is, or the row.
	 */
	function LoginUser($user){
		
		if ( is_array($user)){
			$row = $user;
		}
		else{
			$row = $this->getRecord('fe_users',$user );
		}
	
	 $GLOBALS["TSFE"]->fe_user->createUserSession($row );
	 $GLOBALS["TSFE"]->fe_user->loginSessionStarted = TRUE;
	 $GLOBALS["TSFE"]->fe_user->user = $GLOBALS["TSFE"]->fe_user->fetchUserSession();
	}
 
 
 	/**
 	 * This function creates a new user, using the passed array as the base,
 	 * and added the various required fields.
 	 *
 	 * If the Cablan.net feuser registration form is present, it will also fill in 
 	 * the IP and user agent fields!
 	 * 
 	 * @param array $values the created row.
 	 */
 	function CreateUser($values){
	
	
		$values['pid'] = $this->conf['usersPid'];
		$values['tstamp'] = time(); 	
		$values['username'] = $values['name']; 	 	 	 	 
		$values['disable'] = 0; 	 	
		$values['crdate'] = time(); 	
		$values['deleted'] = 0;	 	 	
		
		if ( t3lib_extMgm::isLoaded('cablan_feuser_register')){
			$values['tx_cablanfeuserregister_ip_address'] = t3lib_div::getIndpEnv('REMOTE_ADDR');
			$values['tx_cablanfeuserregister_user_agent'] = t3lib_div::getIndpEnv('HTTP_USER_AGENT');
			
		}		
		$GLOBALS['TYPO3_DB']->exec_INSERTquery('fe_users',$values);
		$uid = $GLOBALS['TYPO3_DB']->sql_insert_id();
		
		$values['uid'] = $uid; 	
		
		return $values;
	}

}



if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/cablan_facebook_register/pi1/class.tx_cablanfacebookregister_pi1.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/cablan_facebook_register/pi1/class.tx_cablanfacebookregister_pi1.php']);
}

?>