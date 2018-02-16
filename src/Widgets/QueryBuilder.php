<?php
namespace mls\ki\Widgets;
use \mls\ki\Log;
use \mls\ki\Util;

/**
* An interface for building a query.
*/
class QueryBuilder extends Form
{
	protected $fields = [];
	protected $title = '';
	
	protected $aliases = [];
	protected $alias2fq = [];
	protected $inPrefix = 'ki_querybuilder_';
	public    $previousResultJSON = NULL;
	public    $previousResult = NULL;
	
	/**
	* @param fields an array of DataTableField objects. Should have all members filled, not just the ones that get filled from the constructor.
	* @param title Unique title to differentiate this QueryBuilder's inputs from others
	*/
	function __construct(array $fields, string $title)
	{
		foreach($fields as $key => $f)
			if(!$f->show)
				unset($fields[$key]);
		
		$this->fields = $fields;
		$this->title = $title;
		$this->inPrefix .= $title;
		
		//Create a new state object with default values.
		//If anything was input then handleParamsInternal will override this.
		foreach($fields as $fq => $f)
		{
			$this->aliases[] = $f->alias;
			$this->alias2fq[$f->alias] = $fq;
		}
		$freshRes = new QueryBuilderResult($this->aliases, new QueryBuilderConditionGroup('AND', []), []);
		$this->previousResult = $freshRes;
	}
	
	/**
	* @return the HTML for this QueryBuilder
	*/
	protected function getHTMLInternal()
	{
		$out = '<form method="get" class="ki_querybuilder" id="' . $this->inPrefix . '" onsubmit="ki_queryBuilderSerialize(this, \'' . $this->inPrefix . '\');">';
		//field list
		$out .= '<fieldset class="ki_showOrder"><legend>Show Fields</legend><ol>';
		foreach($this->previousResult->fieldsToShow as $alias)
		{
			if(!in_array($alias, $this->aliases)) continue;
			$out .= '<li><label><input type="checkbox" value="' . htmlspecialchars($alias) . '" checked />'
				. htmlspecialchars($alias) . '</label></li>';
		}
		foreach($this->aliases as $alias)
		{
			if(in_array($alias, $this->previousResult->fieldsToShow)) continue;
			$out .= '<li><label><input type="checkbox" value="' . htmlspecialchars($alias) . '" '
				. (empty($this->previousResult->fieldsToShow) ? 'checked' : '')
				. ' />' . htmlspecialchars($alias) . '</label></li>';
		}
		$out .= '</ol></fieldset>';
		//sorter
		$sortName = $this->inPrefix . '_sort';
		$out .= '<fieldset class="ki_sorter"><legend>Sort</legend><ol>';
		foreach($this->previousResult->sortOrder as $alias => $direction)
		{
			if(!in_array($alias, $this->aliases)) continue;
			$out .= '<li><label><select name="' . htmlspecialchars($alias) . '">'
				. '<option value="N"' . ($direction == 'N' ? ' selected' : '') . '>&nbsp;</option>'
				. '<option value="A"' . ($direction == 'A' ? ' selected' : '') . '>▲</option>'
				. '<option value="D"' . ($direction == 'D' ? ' selected' : '') . '>▼</option>'
				. '</select>' . htmlspecialchars($alias) . '</label></li>';
		}
		foreach($this->aliases as $alias)
		{
			if(in_array($alias, array_keys($this->previousResult->sortOrder))) continue;
			$out .= '<li><label><select name="' . htmlspecialchars($alias) . '">'
				. '<option value="N">&nbsp;</option><option value="A">▲</option><option value="D">▼</option></select>'
				. htmlspecialchars($alias) . '</label></li>';
		}
		$out .= '</ol></fieldset>';
		//conditions
		$out .= '<fieldset class="ki_filter"><legend>Filter</legend><div id="' . $this->inPrefix . '_filter"></div></fieldset>';
		//closing
		$out .= '<br clear="all"/><input type="submit" value="Apply" />';
		$out .= '<input type="hidden" name="' . $this->inPrefix . '_filterResult" id="' . $this->inPrefix . '_filterResult" value="' . htmlspecialchars($this->previousResultJSON) . '"/>';
		$out .= '</form>';
		//script kickoff
		$out .= '<script>var ki_querybuilder_fields = ki_querybuilder_fields || new Object();';
		$fieldsJson = [];
		foreach($this->fields as $field)
		{
			$fieldsJson[$field->alias] = ['dataType' => $field->dataType, 'nullable' => $field->nullable];
		}
		$fieldsJson = json_encode($fieldsJson);
		$out .= 'ki_querybuilder_fields["' . $this->inPrefix . '"] = ' . $fieldsJson . ';';
		$out .= '$(function(){ ki_setupQueryBuilder("' . $this->inPrefix . '"); })';
		$out .= '</script>';
		return $out;
	}
	
	/**
	* @return QueryBuilderResult object built from the user's input
	*/
	protected function handleParamsInternal()
	{
		if(!isset($this->get[$this->inPrefix . '_filterResult'])) return null;
		$json = $this->get[$this->inPrefix . '_filterResult'];
		$this->previousResultJSON = $json;
		$data = json_decode($json, true, 512, JSON_BIGINT_AS_STRING|JSON_OBJECT_AS_ARRAY);
		$out = new QueryBuilderResult();
		//show order
		foreach($data['fieldsToShow'] as $f)
		{
			if(!in_array($f, $this->aliases)) continue;
			$out->fieldsToShow[] = $f;
		}
		//sort
		foreach($data['sortOrder'] as $sortItem)
		{
			if(!in_array($sortItem['field'], $this->aliases)       ) continue;
			if(!in_array($sortItem['field'], $data['fieldsToShow'])) continue;
			if(!in_array($sortItem['direction'], ['A','D'])        ) continue;
			$out->sortOrder[$sortItem['field']] = $sortItem['direction'];
		}
		//conditions
		if($data['rootConditionGroup'])
			$out->rootConditionGroup = $this->recurseConditions($data['rootConditionGroup']);
		
		$this->previousResult = $out;
		return $out;
	}
	
	/**
	* @param con associative array of condition data from the json
	* @return object oriented condition data
	*/
	function recurseConditions($con)
	{
		static $validOps = ['=','!=','<','<=','>','>=','contains','does not contain','contained in','not contained in','matches regex',"doesn't match regex",'is NULL','is NOT NULL'];
		static $validBool = ['AND','OR','XOR'];
		if(isset($con['field']))
		{
			if(!in_array($con['field'], $this->aliases)) return null;
			if(!in_array($con['operator'], $validOps))   return null;
			$fieldObj = $this->fields[$this->alias2fq[$con['field']]];
			return new QueryBuilderCondition($fieldObj,$con['operator'],$con['value']);
		}
		elseif(isset($con['boolOp']))
		{
			if(!in_array($con['boolOp'], $validBool)) return null;
			$subItems = [];
			foreach($con['conditions'] as $item)
			{
				$subItems[] = $this->recurseConditions($item);
			}
			return new QueryBuilderConditionGroup($con['boolOp'],$subItems);
		}else{
			Log::warn('QueryBuilder found invalid condition in input data: ' . Util::toString($con));
			return null;
		}
	}
}
?>