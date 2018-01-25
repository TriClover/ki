<?php
namespace mls\ki\Widgets;

class DataTableGenerator
{
	public static function getUserAdmin()
	{
		/* todo: password hashing
		- add a radio button to password editing to select if the field contains hash or plaintext
		- for new row, add a third option "generate" 
		*/
		$userFields = array();
		$userFields[] = new DataTableField('id',            'ki_users', 'ID',             true, false, false);
		$userFields[] = new DataTableField('username',      'ki_users', 'Username',       true, false, true );
		$userFields[] = new DataTableField('email',         'ki_users', 'Email address',  true, true,  true );
		$userFields[] = new DataTableField('email_verified','ki_users', 'Email verified?',true, true,  false);
		$userFields[] = new DataTableField('password_hash', 'ki_users', 'Password (hash)',true, true,  true );
		$userFields[] = new DataTableField('enabled',       'ki_users', 'Enabled?',       true, true,  true );
		$userFields[] = new DataTableField('last_active',   'ki_users', 'Last Active',    true, false, false);
		$userFields[] = new DataTableField('lockout_until', 'ki_users', 'Lockout Until:', true, true,  false);
		
		return new DataTable('userAdmin','ki_users', $userFields, true, true, false, 50, true, false, false, false);
	}
}

?>