<?php
namespace mls\ki\Security;
use \mls\ki\Database;
/**
* Data about the current HTTP request.
*/
class Request
{
	//true when the user has submitted their correct password with this request.
	//Useful for security sensitive actions requiring re-auth like changing the password
	public $verifiedPassword = false;
	//Contains a userid when user supplies a special password reset nonce
	public $verifiedEmailForPasswordReset = NULL;
	public $verifiedPasswordResetFinal = NULL;
	//important action responses to be shown to the user
	public $systemMessages = array();
	//Combined misc information identifying the user agent configuration
	public $fingerprint = '';
	public $ip = NULL;
	
	/**
	* Returns the CSRF token for the given purpose for this request;
	* creates one the first time it's called within a request.
	* @param purpose The purpose for which the token is valid.
	* @return the CSRF token
	*/
	public static function getCsrfToken($purpose)
	{
		static $csrf = array();
		if(!isset($csrf[$purpose]))
		{
			$db = Database::db();
			$user = (Authenticator::$user === NULL) ? NULL : Authenticator::$user->id;
			$session = (Authenticator::$session === NULL) ? NULL : Authenticator::$session->id_hash;
			$idPair = Authenticator::generateNonceId();
			$csrf[$purpose] = $idPair[0];
			$hash = $idPair[1];
			$db->query('INSERT INTO `ki_nonces` SET `nonce_hash`=?,`user`=?,`session`=?,`created`=NOW(),`purpose`=?',
				array($hash, $user, $session, $purpose), 'adding csrf nonce');
		}
		return $csrf[$purpose];
	}
	
	/**
	* @param purpose The purpose for which the token is valid.
	* @return HTML for a hidden input containing the CSRF token and having the default name expected by the framework.
	*/
	public static function getCsrfInput($purpose)
	{
		return '<input type="hidden" name="ki_csrf" value="' . Request::getCsrfToken($purpose) . '" />';
	}
	
	/**
	* Check if the request included a CSRF token that is valid
	* for the given purpose, current session, and current user.
	* If the token is found, it is deleted regardless of if its validity
	* @param purpose The purpose that the token must have in the DB in order to count for the check you are doing
	* @param token The token value provided in the request; if NULL, uses the default POST data location
	* @return boolean indicating success
	*/
	public static function validateCsrfToken($purpose, $token = NULL)
	{
		if($token === NULL) $token = $_POST['ki_csrf'];
		$hash = Authenticator::pHash($token);
		
		$db = Database::db();
		$user = NULL;
		$session = NULL;
		$checkUser    = Authenticator::$user === NULL;
		$checkSession = Authenticator::$session === NULL;
		if($checkUser)    $user    = Authenticator::$user->id;
		if($checkSession) $session = Authenticator::$session->id_hash;
		
		$params = array($hash, $purpose);
		$qryValid = 'DELETE FROM `ki_nonces` WHERE `nonce_hash`=? AND `purpose`=? ';
		$qryValid .= 'AND (`user` IS NULL';
		if($checkUser)   {$qryValid .=  ' OR `user`=?';    $params[] = Authenticator::$user->id;}
		$qryValid .= ') ';
		$qryValid .= 'AND (`session` IS NULL';
		if($checkSession){$qryValid .=  ' OR `session`=?'; $params[] = Authenticator::$session->id_hash;}
		$qryValid .= ') ';
		$res = $db->query($qryValid, $params, 'checking for csrf token');
		
		query('DELETE FROM `ki_nonces` WHERE `nonce_hash`=?', array($hash), 'removing any invalid csrf token');
		return $res !== false && $res > 0;
	}
}
?>