<?php
namespace mls\ki\Security;
use \mls\ki\Config;
use \mls\ki\Database;
use \mls\ki\Log;
use \mls\ki\Util;


/**
* Represents a nonce, a one-use key stored in the database to create a
* one to one correspondence between pairs of requests.
* Used for such purposes as verifying email account control and
* protecting forms from CSRF and double submit.
*/
class Nonce
{
	public static $allValid = array(); //maps Purposes to Nonce objects for all nonces successfully validated in the current request
	const msg_securityFault = 'Security fault.';
	
	public $nonceValue;          //The actual nonce contained in this object
	public $nonceHash;           //The hash of the nonce, which gets stored in the database
	public $user;                //User associated with the nonce
	public $sessionHash;         //Session ID associated with the nonce (as a hash since that's what is stored in the DB)
	public $require_user;        //If true, and $user is not null, nonce is only valid on requests where $user is logged in. 
	public $require_sessionHash; //If true, and $sessionHash is not null, nonce is only valid on requests in the session corresponding to $sessionHash
	public $purpose;             //The feature that uses the nonce
	public $createdTimestamp;    //When the nonce was added to the database
	
	/**
	* Create a new nonce and save it to the database.
	* Call this function when constructing a form that needs a nonce string.
	* @param purpose The feature that uses the nonce.
	* @param user The user id associated with this nonce; if NULL, and a user is logged in, use id of the currently logged in user.
	* @param require_user whether the nonce should only be valid on requests where $user (if not null) is logged in. Should usually be true, but can be set to false for features used outside of login like password recovery, where $user is used to know which user's password to recover, rather than to require prior auth as that user.
	* @param require_sessionHash whether the nonce should only be valid on requests attached to the current session, if there is one. Should usually be true but can be set to false for features where legitimate cross-session usage is likely, like nonces sent to emails
	* @return a new Nonce object, or false if there was an error in creating the new nonce
	*/
	public static function create(string $purpose,
	                              int $user=NULL,
								  bool $require_user=true,
								  bool $require_sessionHash=true)
	{
		$db = Database::db();
		/* If duplicate purpose, return false instead of the original
		because it might have different requirements and
		behavior should be consistent regardless of the requirements specified */
		static $allCreated = array();
		if(isset($allCreated[$purpose])) return false;
		
		if($user === NULL && Authenticator::$user !== NULL) $user = Authenticator::$user->id;
		$session = (Authenticator::$session === NULL) ? NULL : Authenticator::$session->id_hash;
		
		$obj = new Nonce();
		$idPair = Nonce::generateNonceId();
		$obj->nonceValue = $idPair[0];
		$obj->nonceHash = $idPair[1];
		$obj->user = $user;
		$obj->sessionHash = $session;
		$obj->require_user = $require_user;
		$obj->require_sessionHash = $require_sessionHash;
		$obj->purpose = $purpose;
		
		$nRes = $db->query('INSERT INTO `ki_nonces` SET `nonce_hash`=?, `user`=?,  `session`=?,      `requireUser`=?,   `requireSession`=?,       `purpose`=?,`created`=NOW()',
			array(                                      $obj->nonceHash,$obj->user,$obj->sessionHash,$obj->require_user,$obj->require_sessionHash,$obj->purpose),
			'adding nonce');
		if($nRes === false) return false;
		$created = $db->query('SELECT UNIX_TIMESTAMP(`created`) AS created FROM `ki_nonces` WHERE `nonce_hash`=? LIMIT 1', array($obj->nonceHash), 'getting created-date of just-created nonce');
		if($created !== false && !empty($created)) $obj->createdTimestamp = $created[0]['created'];
		$allCreated[$obj->purpose] = $obj;
		return $obj;
	}
	
	/**
	* Load and validate a nonce provided by the user. If valid, store it centrally and also return it.
	* Delete it if found, regardless of validity.
	* Call this function once per nonce when processing nonce values provided by the user.
	* @param value the nonce string provided by the user. If NULL, use the value of the default POST var.
	* @param requestContext the request in which to try loading the nonce. If NULL, use the actual request loaded during auth initialization.
	* @return a Nonce object, provided that the user supplied value matched a nonce in the database AND the current request satisfies the nonce's security requirements (user,session,expiry). Returns error string otherwise.
	*/
	public static function load(string $value = NULL, Request $requestContext = NULL)
	{
		if($value === NULL) $value = $_POST['ki_nonce'];
		if($requestContext === NULL) $requestContext = Authenticator::$request;
		
		$db = Database::db();
		$config = Config::get();
		$hash = Authenticator::pHash($value);
		$ret = 'Unknown error.';
		$nRes = $db->query('SELECT `user`,`session`,`requireUser`,`requireSession`,`purpose`,UNIX_TIMESTAMP(`created`) AS created FROM `ki_nonces` WHERE `nonce_hash`=? LIMIT 1',
			array($hash), 'getting data for supplied nonce');
		if($nRes === false)
		{
			$ret = 'Database error.';
		}
		elseif(empty($nRes))
		{
			$ret = 'Nonce not found. Certain forms and links cannot be used more than once per time they are loaded. Reload the page that brought you here before trying again.';
		}else{
			$nRes = $nRes[0];
			if($nRes['requireUser'] && $nRes['user'] !== NULL)
			{
				if(Authenticator::$user === NULL || Authenticator::$user->id != $nRes['user'])
					$ret = Nonce::msg_securityFault;
			}
			if($nRes['requireSession'] && $nRes['session'] !== NULL)
			{
				if(Authenticator::$session === NULL || Authenticator::$session->id_hash != $nRes['session'])
					$ret = Nonce::msg_securityFault;
			}
			$lifeSeconds = $config['limits']['nonceExpiry_hours'] * 60 * 60;
			$expiryTimestamp = $nRes['created'] + $lifeSeconds;
			if(time() > $expiryTimestamp) $ret = 'Expired link. Please get a new one and try again.';
			$ret = new Nonce();
			$ret->nonceValue          = $value;
			$ret->nonceHash           = $hash;
			$ret->user                = $nRes['user'];
			$ret->sessionHash         = $nRes['session'];
			$ret->require_user        = $nRes['requireUser'];
			$ret->require_sessionHash = $nRes['requireSession'];
			$ret->purpose             = $nRes['purpose'];
			$ret->createdTimestamp    = $nRes['created'];
			
			if(isset(Nonce::$allValid[$ret->purpose]))
				Log::warn('Duplicate nonce purpose "' . $ret->purpose . '". Overwriting.');
			Nonce::$allValid[$ret->purpose] = $ret;
		}

		//delete nonce regardless of validity
		$db->query('DELETE FROM `ki_nonces` WHERE `nonce_hash`=? LIMIT 1', array($hash), 'deleting used nonce');
		
		//If nonce was bad, log it
		if(!$ret instanceof Nonce)
		{
			$db->query('INSERT INTO ki_failedNonces SET `input`=?,`ip`=?,`when`=NOW()',
				array($value, $requestContext->ipId), 'logging invalid nonce attempt');
		}
		
		//If too many failed nonces, block ip
		$windowBegin = time() - ($config['limits']['nonceAttemptWindow_minutes']*60);
		$failedCount = $db->query('SELECT COUNT(*) as `total` FROM `ki_failedNonces` WHERE `ip`=? AND `when`>FROM_UNIXTIME(?)',
			array($requestContext->ipId, $windowBegin), 'counting failed nonce attempts');
		if($failedCount !== false && !empty($failedCount))
		{
			$failedCount = $failedCount[0]['total'];
			if($failedCount > $config['limits']['maxNonceAttempts'])
			{
				$blockUntil = time() + ($config['limits']['ipBlock_minutes']*60);
				$db->query('UPDATE `ki_IPs` SET `block_until`=FROM_UNIXTIME(?) WHERE `id`=?',
					array($blockUntil, $requestContext->ipId), 'blocking IP');
				$requestContext->ipBlocked = true;
			}
		}
		
		//If IP blocked, reject even if nonce was valid
		if($requestContext->ipBlocked)
		{
			return Authenticator::msg_maxAttemptsError;
		}
		
		return $ret;
	}
	
	/**
	* Generates a new nonce ID; guaranteed to be unique
	* because it checks against the database.
	* Does not write anything to the database.
	* @return an array($sessionID, $hashedSessionID)
	*/
	protected static function generateNonceId()
	{
		$db = Database::db();
		$sid = '';
		$sid_hash = '';
		do{
			$sid = Util::random_str(32);
			$sid_hash = Authenticator::pHash($sid);
			$dups = $db->query('SELECT `nonce_hash` FROM `ki_nonces` WHERE `nonce_hash`=? LIMIT 1',
				array($sid_hash), 'checking for duplicate nonce ID hash');
		}while(!empty($dups));
		return array($sid, $sid_hash);	
	}
	
	/**
	* @param inputName value of the "name" field of the input
	* @return an HTML hidden form input that delivers this nonce
	*/
	public function getHTML(string $inputName='ki_nonce')
	{
		return '<input type="hidden" name="' . $inputName . '" value="' . $this->nonceValue . '" />';
	}
}

?>