<?php
namespace mls\ki\Setup;
use \mls\ki\Config;
use \mls\ki\Ki;
use \mls\ki\Util;

class SmAuth extends SetupModule
{
	private $msg = '';
	
	public function getFriendlyName() { return 'Root account config'; }
	
	protected function handleParamsInternal()
	{
		$config = Config::get();
		$liveConfigLocation = $_SERVER['DOCUMENT_ROOT'] . '/../config/' . $this->setup->siteName . '.json';

		if(empty($config['root']['enable_root']) || !$config['root']['enable_root'])
		{
			$this->msg = 'Root account disabled. This is good unless you need it to continue setup or create other users. To change this go to ' . $liveConfigLocation;
			return SetupModule::FAILURE;
		}else{
			$this->msg = 'Root account enabled. Once you have finished this setup and created a normal user account you should probably disable root.';
		}
		
		if(empty($config['root']['root_password']))
		{
			$pw = Util::random_str(12);
			$setRes = Config::set(array('root', 'root_password'), $pw);
			if($setRes === false)
			{
				$this->msg = 'Unable to generate/set/save root password.';
				return SetupModule::FAILURE;
			}
		}
		$config = Config::get();

		if(!empty($config['root']['root_ip']) && $config['root']['root_ip'] != $_SERVER['REMOTE_ADDR'])
		{
			$this->msg = 'Root login is restricted to a different IP than yours. Change the root_ip in the config file to continue. This is located at <tt>' . $liveConfigLocation . '</tt>';
			return SetupModule::FAILURE;
		}
		
		session_start();
		if($_SESSION['setupAuth'] !== 1)
		{
			$passwordForm = '<form method="post"><input type="password" name="password" placeholder="password" required /><input type="submit" value="Login"/></form>';
			if(!empty($_POST['password']))
			{
				if($config['root']['root_password'] == $_POST['password'])
				{
					$_SESSION['setupAuth'] = 1;
				}else{
					$this->msg = 'Wrong password. Look up the root_password in the config file to continue. This is located at <tt>' . $liveConfigLocation . '</tt><br/>' . $passwordForm;
					return SetupModule::FAILURE;
				}
			}else{
				$this->msg = 'Enter the root password to continue setup.<br/>'
					. 'It can be found at ' . $liveConfigLocation . '<br/>'
					. $passwordForm;
				return SetupModule::FAILURE;
			}
		}
		session_write_close();

		if(empty($config['root']['root_ip']))
		{
			if(empty($_POST['root_ip']))
			{
				$this->msg = 'Root account has no IP configured.<form method="post"><input type="submit" name="root_ip" value="Use Current IP"/></form>';
				return SetupModule::FAILURE;
			}else{
				Config::set(['root', 'root_ip'], $_SERVER['REMOTE_ADDR']);
			}
		}
		
		return SetupModule::WARNING;
	}
	
	protected function getHTMLInternal()
	{
		return $this->msg;
	}
}

?>