<?php
namespace mls\ki\Widgets;
use \mls\ki\Database;
use \mls\ki\Log;
use \mls\ki\Security\Authenticator;
use \mls\ki\Widgets\DataTable;
use \mls\ki\Widgets\DataTableField;
use \mls\ki\Widgets\DataTableEventCallbacks;

/**
* Widget that saves the contents of form inputs as a 'report setup' in the database
* with sharing and access control features
*/
class FormSaver extends Form
{
	//setup
	public $formName;
	public $serializer;    //name of Javascript function(name) that will return serialized form data
	public $deserializer;  //name of Javascript function(name, data) that will populate form from data
	
	//data from DB
	protected $dbId;       //ID of form this saver is for, in ki_savableForms.id
	
	//state
	protected $setupOK = true;
	protected $outputFormData = ''; //form data to output, may be loaded from DB or regurgitated
	protected $dataTablesDidSomething = false;
	
	//sub objects
	protected $dtSaver;    //DataTable showing the saved form configurations in the user's private stash
	protected $categorySavers = []; //array of DataTables for viewable categories
	
	//data passed between callbacks
	public static $permIdsToDeleteByCatId = [];
	
	function __construct(string $formName,
	                     string $serializer = NULL,
	                     string $deserializer = NULL)
	{
		$this->formName = preg_replace('/[^A-Za-z0-9_]/','',$formName);
		if(empty($serializer) && empty($deserializer))
		{
			//todo: set default serializer
		}
		elseif(!empty($serializer) && !empty($deserializer))
		{
			$this->serializer         = $serializer;
			$this->deserializer       = $deserializer;
		}else{
			$errMsg = 'FormSaver constructor: optional params must be either all NULL or all not NULL.';
			Log::error($errMsg);
			$this->setupOK = false;
			return;
		}
		
		$db = Database::db();
		$idRes = $db->query('SELECT `id` FROM `ki_savableForms` WHERE `name`=?', [$formName], 'getting ID of form');
		if($idRes === false)
		{
			$this->setupOK = false;
			return;
		}
		if(count($idRes) == 0)
		{
			$insertRes = $db->query('INSERT INTO `ki_savableForms` SET `name`=?', [$formName], 'adding new savable form');
			if($insertRes === false)
			{
				$this->setupOK = false;
				return;
			}
			$this->dbId = $insertRes->connection->insert_id;
		}else{
			$this->dbId = $idRes[0]['id'];
		}
		
		$table = 'ki_savedFormData';
		$formId = $this->dbId;
		$dataConstraints = ['type' => 'hidden'];
		$userId = Authenticator::$user->id;
		$saverName = 'saver_'.$formName;
		
		//format the DataTable cells for the Data column
		$dtFilter_data = function($contents, $type) use($saverName)
		{
			switch($type)
			{
				case 'show':
				case 'edit':
				$contents = '[Stored query]<input type="hidden" name="'.$saverName.'_regurgitator"/>';
				break;

				case 'add':
				$contents .= '[Current query]';
			}
			return $contents;
		};
		$loadCB = [$this, 'load'];
		$buttonCallbacks = ['Load'=>$loadCB];
		$onclick = 'ki_formsaver_deliverSerialization(this, "' . $this->serializer . '", "' . $this->formName . '");return true;';
		$eventCallbacks = new DataTableEventCallbacks($loadCB,NULL,NULL,NULL,NULL,NULL,$onclick,$onclick,$onclick);
		
		$saverFields = [];
		$saverFields[] = new DataTableField('id',           $table,'ID',            true, false,false,  [], NULL);
		$saverFields[] = new DataTableField('form',         $table,'Form',          false,false,$formId,[], NULL);
		$saverFields[] = new DataTableField('name',         $table,'Name',          true, true, true,   [], NULL);
		$saverFields[] = new DataTableField('data',         $table,'Data',          true, false,true,   $dataConstraints, $dtFilter_data);
		$saverFields[] = new DataTableField('created_by',   $table,'Created By',    false,false,$userId,[], NULL);
		$saverFields[] = new DataTableField('created_on',   $table,'Created On',    false,false,false,  [], NULL);
		$saverFields[] = new DataTableField('lastEdited_by',$table,'Last Edited By',false,false,$userId,[], NULL);
		$saverFields[] = new DataTableField('lastEdited_on',$table,'Last Edited On',false,false,false,  [], NULL);
		$saverFields[] = new DataTableField('owner',        $table,'Owner',         false,false,$userId,[], NULL);
		$saverFields[] = new DataTableField('category',     $table,'Category',      false,false,NULL,   [], NULL);
		$filter = '`owner`=' . $userId . ' AND `category` IS NULL';
		$this->dtSaver = new DataTable($saverName, $table, $saverFields, true, true, $filter, 1000, false, false, false, false, $eventCallbacks, $buttonCallbacks);
		
		$categoriesQuery = 'SELECT `id`,`name`,`permission_view`,`permission_edit`,`permission_addDel` FROM `ki_savedFormCategories`';
		$catRes = $db->query($categoriesQuery, [], 'getting report categories');
		if(!empty($catRes))
		{
			$perms = Authenticator::$user->permissionsById;
			foreach($catRes as $row)
			{
				if(!isset($perms[$row['permission_view']])) continue;
				
				$catSaverName = $saverName . '_' . $row['name'];
				$allowEdit = isset($perms[$row['permission_edit']]);
				$allowAddDel = isset($perms[$row['permission_addDel']]);
				$catId = $row['id'];
				
				$catSaverFields = [];
				$catSaverFields[] = new DataTableField('id',           $table,'ID',            true, false,     false,  [], NULL);
				$catSaverFields[] = new DataTableField('form',         $table,'Form',          false,false,     $formId,[], NULL);
				$catSaverFields[] = new DataTableField('name',         $table,'Name',          true, $allowEdit,true,   [], NULL);
				$catSaverFields[] = new DataTableField('data',         $table,'Data',          true, false,     true,   $dataConstraints, $dtFilter_data);
				$catSaverFields[] = new DataTableField('created_by',   $table,'Created By',    false,false,     $userId,[], NULL);
				$catSaverFields[] = new DataTableField('created_on',   $table,'Created On',    false,false,     false,  [], NULL);
				$catSaverFields[] = new DataTableField('lastEdited_by',$table,'Last Edited By',false,false,     $userId,[], NULL);
				$catSaverFields[] = new DataTableField('lastEdited_on',$table,'Last Edited On',false,false,     false,  [], NULL);
				$catSaverFields[] = new DataTableField('owner',        $table,'Owner',         false,false,     $userId,[], NULL);
				$catSaverFields[] = new DataTableField('category',     $table,'Category',      false,false,     $catId, [], NULL);
				$filter = '`category`=' . $catId;
				$catSaver = new DataTable($catSaverName, $table, $catSaverFields, $allowAddDel, $allowAddDel, $filter, 1000, false, false, false, false, $eventCallbacks, $buttonCallbacks);
				$this->categorySavers[$row['name']] = $catSaver;
			}
		}
	}
	
	/**
	* Loads a row in ki_savedFormData into a form.
	* @param pk the primary key, given as array
	*/
	public function load($pk)
	{
		$id = $pk['id'];
		$db = Database::db();
		$dataRes = $db->query('SELECT `data` FROM `ki_savedFormData` WHERE `id`=?', [$id], 'loading saved form data');
		if($dataRes === false || count($dataRes) == 0) return;
		$this->outputFormData = $dataRes[0]['data'];
	}
	
	/**
	* @return the HTML for this FormSaver
	*/
	protected function getHTMLInternal()
	{
		if(!$this->setupOK) return '';
		$drawerName = 'saver_' . $this->formName . '_drawer';
		$reportList = '<h1 style="background-color:#EEF;padding:0.5em;">Reports</h1>';
		$reportList .= '<div style="margin:1em;">';
		$reportList .= $this->dtSaver->getHTML();
		foreach($this->categorySavers as $name => $saver)
		{
			$reportList .= '<br/><h2>'.htmlspecialchars($name).'</h2>'.$saver->getHTML();
		}
		$reportList .= '<script>$(function(){' . $this->deserializer . '("' . $this->formName
			. '","' . $this->outputFormData . '");});</script></div>';
		
		$drawer = new Drawer($drawerName, $reportList, Drawer::EDGE_RIGHT, '☰ Reports', $this->dataTablesDidSomething);
		$out = '<fieldset style="float:left;text-align:center;">'
			. $drawer->getHTML()
			. '</fieldset>';
		
		return $out;
	}
	
	/**
	* @return the serialized form data that will be used, in case any server side processing wants it
	*/
	protected function handleParamsInternal()
	{
		//check preconditions
		if(!$this->setupOK) return false;
		
		//interpret arguments
		$post = $this->post;
		$get  = $this->get;
		
		//regurgitate if necessary
		$saverName = 'saver_'.$this->formName;
		$re_input = $saverName.'_regurgitator';
		if(isset($post[$re_input])) $this->outputFormData = $post[$re_input];
		
		//process datatable new/edit/delete/load
		if($this->dtSaver->handleParams($post, $get))
		{
			$this->dataTablesDidSomething = true;
		}
		foreach($this->categorySavers as $saver)
		{
			if($saver->handleParams($post, $get))
			{
				$this->dataTablesDidSomething = true;
			}
		}

		return $this->outputFormData;
	}
	
	/**
	* @return a DataTable that provides an admin interface for editing report categories
	*/
	public static function getCategoryAdmin()
	{
		$permFilter = function($in, $type){
			if($type == 'add')
			{
				return $in . ' reports in [name]';
			}
			return $in;
		};
		$fields = [];
		$fields[] = new DataTableField('id',          'ki_savedFormCategories',          'ID',                    true, false,false);
		$fields[] = new DataTableField('name',        'ki_savedFormCategories',          'Name',                  true, true, true);
		$fields[] = new DataTableField('description', 'ki_savedFormCategories',          'Description',           true, true, true);
		$fields[] = new DataTableField('name',        'ki_permissions_permission_view',  'View Permission',       true, true, 'View',       [], $permFilter);
		$fields[] = new DataTableField('name',        'ki_permissions_permission_edit',  'Edit Permission',       true, true, 'Edit',       [], $permFilter);
		$fields[] = new DataTableField('name',        'ki_permissions_permission_addDel','Add/Delete Permission', true, true, 'Add/Delete', [], $permFilter);
		$fields[] = new DataTableField(NULL,          'ki_savedFormCategories',          '',                      false,false,false);
		//For a new row, give the permissions unique names based on the category name
		//so they don't fail the Unique key
		$beforeAdd = function(&$row){
			$suffix = ' reports in ' . $row['ki_savedFormCategories.name'];
			$row['ki_permissions_permission_view.name']   .= $suffix;
			$row['ki_permissions_permission_edit.name']   .= $suffix;
			$row['ki_permissions_permission_addDel.name'] .= $suffix;
			return true;
		};
		$beforeDelete = function($row){
			$id = $row['ki_savedFormCategories.id'];
			if(!isset(FormSaver::$permIdsToDeleteByCatId[$id]))
				FormSaver::$permIdsToDeleteByCatId[$id] = [];

			$db = Database::db();
			$query = 'SELECT `permission_view`,`permission_edit`,`permission_addDel` FROM `ki_savedFormCategories` WHERE `id`=?';
			$res = $db->query($query, [$id], 'getting permission IDs of form category that is about to be deleted');
			if(!empty($res))
			{
				$row = $res[0];
				FormSaver::$permIdsToDeleteByCatId[$id][] = $row['permission_view'];
				FormSaver::$permIdsToDeleteByCatId[$id][] = $row['permission_edit'];
				FormSaver::$permIdsToDeleteByCatId[$id][] = $row['permission_addDel'];
			}
			return true;
		};
		$onDelete = function($pk){
			$catId = $pk['ki_savedFormCategories.id'];
			$query = 'DELETE FROM `ki_permissions` WHERE `id` IN('
				. implode(',', FormSaver::$permIdsToDeleteByCatId[$catId])
				. ') LIMIT 3';
			$db = Database::db();
			$db->query($query, [], 'cleaning up permissions for report category being deleted');
		};
		$callbacks = new DataTableEventCallbacks(NULL, NULL, $onDelete, $beforeAdd, NULL, $beforeDelete);
		return new DataTable('catAdmin', ['ki_savedFormCategories','ki_permissions'], $fields, true, true, '', 100, false, false, false, false, $callbacks);
	}
}

?>