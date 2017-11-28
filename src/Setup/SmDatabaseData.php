<?php
namespace mls\ki\Setup;
use \mls\ki\Database;
use \mls\ki\Ki;
use \mls\ki\Util;

class SmDatabaseData extends SetupModule
{
	protected $msg = '';
	protected $showForm = false;
	
	public function getFriendlyName() { return 'Database Data'; }
	
	protected function handleParamsInternal()
	{
		$db = Database::db();
		
		//make sure root account is in the DB so it can be used in the "full" auth system
		$res = $db->query('SELECT `id` FROM `ki_users` WHERE `username`="root" LIMIT 1',array(),'checking root account<br/>');
		if($res === false)
		{
			$this->msg = 'Database Error checking root account.';
			return SetupModule::FAILURE;
		}
		if(empty($res))
		{
			$res = $db->query('INSERT INTO `ki_users` SET `username`="root",`email`="no",`email_verified`=1,`password_hash`="no",`enabled`=1,`last_active`=NULL,`lockout_until`=NULL', array(), 'creating root user');
			if($res === false)
			{
				$this->msg = 'Database error creating root account.';
				return SetupModule::FAILURE;
			}
		}
		
		$this->msg = 'Root account available to the app.';
		return SetupModule::SUCCESS;
	}
	
	protected function getHTMLInternal()
	{
		return $this->msg;
	}
}