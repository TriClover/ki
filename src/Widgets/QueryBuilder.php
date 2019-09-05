<?php
namespace mls\ki\Widgets;
use \mls\ki\Log;
use \mls\ki\Util;

/**
* An interface for building a query.
*/
class QueryBuilder extends Form
{
	//setup params
	protected $fields = [];
	protected $title = '';
	
	//setup, calculated
	protected $aliases = [];
	protected $alias2fq = [];
	protected $inPrefix = 'ki_querybuilder_';
	protected $serial2alias = [];
	
	//state
	public    $previousResultJSON = NULL;
	public    $previousResult = NULL;
	
	//child objects
	protected $saver = NULL;
	
	//consts from the javascript
	const validOps = ['=','!=','<','<=','>','>=','contains','does not contain','contained in','not contained in','matches regex',"doesn't match regex",'is NULL','is NOT NULL','contains any'];
	const validBool = ['AND','OR','XOR'];
	
	/**
	* @param fields an array of DataTableField objects. Should have all members filled, not just the ones that get filled from the constructor.
	* @param title Unique title to differentiate this QueryBuilder's inputs from others
	* @param saver Whether to use a FormSaver
	*/
	function __construct(array $fields, string $title, bool $saver = false)
	{
		foreach($fields as $key => $f)
			if($f->show === false)
				unset($fields[$key]);
		
		$this->fields = Util::arrayClone($fields);
		$this->title = preg_replace('/[^A-Za-z0-9_]/','',$title);
		$this->inPrefix .= $title;
		
		//Create a new state object with default values.
		//If anything was input then handleParamsInternal will override this.
		$aliasesCheckedByDefault = [];
		foreach($fields as $fq => $f)
		{
			$this->aliases[] = $f->alias;
			$this->alias2fq[$f->alias] = $fq;
			$this->serial2alias[$f->serialNum] = $f->alias;
			if($f->show === true) $aliasesCheckedByDefault[] = $f->alias;
		}
		$freshRes = new QueryBuilderResult($aliasesCheckedByDefault, new QueryBuilderConditionGroup('AND', []), []);
		$this->previousResult = $freshRes;
		
		if($saver)
		{
			$this->saver = new FormSaver('ki_querybuilder_'.$title, 'ki_queryBuilderGetSerialData', 'ki_setupQueryBuilder');
		}
	}
	
	/**
	* @return the HTML for this QueryBuilder
	*/
	protected function getHTMLInternal()
	{
		$saver = '';
		if($this->saver != NULL)
		{
			$saver = '<div style="float:right;">' . $this->saver->getHTML() . '</div>';
		}
		
		$out = '<div class="ki_querybuilder" id="' . $this->inPrefix . '">' . $saver;
		//field list
		$out .= '<fieldset class="ki_showOrder"><legend>Show Fields</legend><ol>';
		foreach($this->previousResult->fieldsToShow as $alias)
		{
			if(!in_array($alias, $this->aliases)) continue;
			$out .= '<li><label><input type="checkbox" value="' . htmlspecialchars($alias)
				. '" checked />' . htmlspecialchars($alias) . '</label></li>';
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
		$out .= '<form method="get" onsubmit="ki_queryBuilderSerialize($(\'#' . $this->inPrefix . '\'), \'' . $this->inPrefix . '\');">';
		$out .= '<br style="clear:both;"/><input type="submit" value="Apply" />';
		$out .= '<input type="hidden" name="' . $this->inPrefix . '_filterResult" id="' . $this->inPrefix . '_filterResult" value="' . htmlspecialchars($this->previousResultJSON) . '"/>';
		$out .= '</form></div>';
		//script kickoff
		$out .= '<script>var ki_querybuilder_fields = ki_querybuilder_fields || new Object();';
		$fieldsJson = [];
		foreach($this->fields as $field)
		{
			$fieldsJson[$field->alias] = ['dataType' => $field->dataType, 'nullable' => $field->nullable, 'serialNum' => $field->serialNum, 'dropdownOptions' => $field->dropdownOptions];
			if($field->dataType == 'virtual' && $field->manyToMany !== false)
			{
				$fieldAlias = $field->alias;
				$fieldMMDropdownOptions = $field->manyToMany->dropdownOptions;
				$fieldsJson[$fieldAlias]['dropdownOptions'] = $fieldMMDropdownOptions;
			}
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
		$post = $this->post;
		$get = $this->get;
		
		$formDataFromSaver = '';
		if($this->saver) $formDataFromSaver = $this->saver->handleParams($post, $get);
		if($formDataFromSaver != '')
		{
			$get[$this->inPrefix . '_filterResult'] = $formDataFromSaver;
		}
		elseif(isset($post[$this->inPrefix . '_filterResult']))
		{
			$get[$this->inPrefix . '_filterResult'] = $post[$this->inPrefix . '_filterResult'];
		}

		if(!isset($get[$this->inPrefix . '_filterResult'])) return null;
		$json = $get[$this->inPrefix . '_filterResult'];
		$this->previousResultJSON = $json;
		//$data = json_decode($json, true, 512, JSON_BIGINT_AS_STRING|JSON_OBJECT_AS_ARRAY);
		$data = array_values(unpack('C*', base64_decode($json)));
		$data = $this->bin2Obj($data);
		
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
	protected function recurseConditions($con)
	{
		if(isset($con['field']))
		{
			if(!in_array($con['field'], $this->aliases)) return null;
			if(!in_array($con['operator'], QueryBuilder::validOps))   return null;
			$fieldObj = $this->fields[$this->alias2fq[$con['field']]];
			return new QueryBuilderCondition($fieldObj,$con['operator'],$con['value']);
		}
		elseif(isset($con['boolOp']))
		{
			if(!in_array($con['boolOp'], QueryBuilder::validBool)) return null;
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
	
	/**
	* @param data binary serialization of query data
	* @return Object-oriented form of the user's query, same as what you'd get from decoding the json form
	*/
	protected function bin2Obj(array $data)
	{
		$out = [];
		
		$out['fieldsToShow'] = [];
		$showCount = ($data[0] << 4) + Util::unsignedRightShift($data[1], 4);
		for($i=0; $i<$showCount; ++$i)
		{
			$byteIndex = $i * 1.5 + 1.5; // i * (12/8) + 1.5
			$intByteIndex = (int)$byteIndex;
			
			if(abs($byteIndex - $intByteIndex) > 0.1)
			{
				//half-byte
				$fieldId = (($data[$intByteIndex] & 0b00001111) << 8) + $data[$intByteIndex+1];
			}else{
				//new byte
				$fieldId = ($data[$intByteIndex] << 4) + Util::unsignedRightShift($data[$intByteIndex+1],4);
			}
			
			$alias = $this->serial2alias[$fieldId];
			$out['fieldsToShow'][] = $alias;
		}
		$showBytes = (int)ceil((12 + (12 * $showCount)) / 8);
		
		$out['sortOrder'] = [];
		$sortCount = ($data[$showBytes] << 4) + Util::unsignedRightShift($data[$showBytes+1], 4);
		for($i = 0; $i < $sortCount; ++$i)
		{
			$sortItem = [];
			$byteIndex = $showBytes + 2 + (2 * $i);
			$fieldId = ($data[$byteIndex] << 4) + Util::unsignedRightShift($data[$byteIndex+1],4);
			$alias = $this->serial2alias[$fieldId];
			$sortItem['field'] = $alias;
			$ascending = ($data[$byteIndex+1] & 0b1000) == 0;
			$sortItem['direction'] = $ascending ? 'A' : 'D';
			$out['sortOrder'][] = $sortItem;
		}
		$sortBytes = 2 + (2 * $sortCount);

		$out['rootConditionGroup'] = [];
		$filterStart = $showBytes + $sortBytes;
		if($filterStart < count($data))
		{
			$filterNext = $filterStart;
			$traversalArrayIndices = [];
			$traversalArrayLengths = [];
			while(true)
			{
				if($filterNext >= count($data))
				{
					Log::error('QueryBuilder input reader overran the data set length');
					break;
				}
				$indexPath = [];
				foreach($traversalArrayIndices as $i)
				{
					$indexPath[] = 'conditions';
					$indexPath[] = $i;
				}
				$node =& Util::arrayDynamicRef($out["rootConditionGroup"], $indexPath);
				$isGroup = Util::unsignedRightShift($data[$filterNext], 7) == 0;
				$descended = false;
				if($isGroup)
				{
					//group
					$boolOp = Util::unsignedRightShift($data[$filterNext] & 0b01100000, 5);
					$boolOp = QueryBuilder::validBool[$boolOp];
					$conditions = $data[$filterNext] & 0b00011111;
					
					$node["boolOp"] = $boolOp;
					$node["conditions"] = [];
					
					$traversalArrayIndices[] = 0;
					$traversalArrayLengths[] = $conditions;
					$descended = true;
					++$filterNext;
				}else{
					//condition
					$valueStartingByte = (($data[$filterNext] & 0b01111111) << 8) + $data[$filterNext+1];
					$filterNext += 2;
					$valueLength = $data[$filterNext++];
					$colId = ($data[$filterNext] << 4) + Util::unsignedRightShift($data[$filterNext+1],4);
					++$filterNext;
					$alias = $this->serial2alias[$colId];
					$opId = $data[$filterNext++] & 0b00001111;
					$operator = QueryBuilder::validOps[$opId];
					$value = '';
					for($byteIndex = $valueStartingByte; $byteIndex < $valueStartingByte+$valueLength; ++$byteIndex)
					{
						$value .= chr($data[$byteIndex]);
					}
					$node["field"]    = $alias;
					$node["operator"] = $operator;
					$node["value"]    = $value;
				}
				
				$reachedEnd = false;
				while(true && !$descended)
				{
					if(empty($traversalArrayIndices))
					{
						$reachedEnd = true;
						break;
					}
					
					if(end($traversalArrayIndices) >= (end($traversalArrayLengths)-1))
					{
						array_pop($traversalArrayIndices);
						array_pop($traversalArrayLengths);
					}else{
						$traversalArrayIndices[count($traversalArrayIndices)-1] += 1;
						break;
					}
				}
				if($reachedEnd) break;
			}
		}
		return $out;
	}
}
?>