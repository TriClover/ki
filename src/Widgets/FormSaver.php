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
	
	//sub objects
	protected $dtSaver;    //DataTable showing the saved form configurations
	
	function __construct(string $formName,
	                     string $serializer = NULL,
	                     string $deserializer = NULL)
	{
		$this->formName = $formName;
		if(empty($serializer) && empty($deserializer))
		{
			#set default serializer
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
		$out = '<fieldset class="ki_formSaver"><legend>Saved Configurations</legend>';
		$out .= $this->dtSaver->getHTML();
		$out .= '<script>$(function(){' . $this->deserializer . '("' . $this->formName
			. '","' . $this->outputFormData . '");});</script>';
		$out .= '</fieldset>';
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
		$saver = $this->dtSaver;
		$saver->handleParams($post, $get);

		return $this->outputFormData;
	}
}

?>