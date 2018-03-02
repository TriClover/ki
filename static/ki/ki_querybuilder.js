var ki_querybuilder_fields = ki_querybuilder_fields || new Object();

function ki_setupQueryBuilder(prefix)
{
	$('#' + prefix + ' ol').sortable();
	var previousResult = $('#' + prefix + '_filterResult').val();
	if(previousResult)
	{
		//previousResult = JSON.parse(previousResult);
		previousResult = ki_queryBuilderParseBinary(previousResult, prefix);
	}
	
	if(previousResult && previousResult['rootConditionGroup'])
	{
		$('#' + prefix + '_filter').html(ki_queryBuilderGroup(prefix, previousResult['rootConditionGroup']['boolOp'], previousResult['rootConditionGroup']['conditions'], true));
	}else{
		$('#' + prefix + '_filter').html(ki_queryBuilderGroup(prefix, 'AND', [], true));
	}
}

function ki_queryBuilder_serial2alias(prefix, serialNum)
{
	for(alias in ki_querybuilder_fields[prefix])
	{
		if(ki_querybuilder_fields[prefix][alias]['serialNum'] == serialNum)
			return alias;
	}
	return '';
}

function ki_queryBuilderParseBinary(data, prefix)
{
	var validBool = ['AND','OR','XOR'];
	var validOps = ['=','!=','<','<=','>','>=','contains','does not contain','contained in','not contained in','matches regex',"doesn't match regex",'is NULL','is NOT NULL'];
	
	data = base64ToBin(data);
	var out = new Object();
	
	out['fieldsToShow'] = [];
	var showCount = (data[0] << 4) + (data[1] >>> 4);
	for(var i=0; i < showCount; ++i)
	{
		var byteIndex = i * 1.5 + 1.5; // i * (12/8) + 1.5
		var intByteIndex = Math.floor(byteIndex);
		
		var fieldId = 0;
		if(Math.abs(byteIndex - intByteIndex) > 0.1)
		{
			//half-byte
			fieldId = ((data[intByteIndex] & 0b00001111) << 8) + data[intByteIndex+1];
		}else{
			//new byte
			fieldId = (data[intByteIndex] << 4) + (data[intByteIndex+1] >>> 4);
		}
		
		var alias = ki_queryBuilder_serial2alias(prefix, fieldId);
		out['fieldsToShow'].push(alias);
	}
	var showBytes = Math.ceil((12 + (12 * showCount)) / 8);
	
	out['sortOrder'] = [];
	var sortCount = (data[showBytes] << 4) + (data[showBytes+1] >>> 4);
	for(var i = 0; i < sortCount; ++i)
	{
		var sortItem = new Object();
		var byteIndex = showBytes + 2 + (2 * i);
		var fieldId = (data[byteIndex] << 4) + (data[byteIndex+1] >>> 4);
		var alias = ki_queryBuilder_serial2alias(prefix, fieldId);
		sortItem['field'] = alias;
		var ascending = (data[byteIndex+1] & 0b1000) == 0;
		sortItem['direction'] = ascending ? 'A' : 'D';
		out['sortOrder'].push(sortItem);
	}
	var sortBytes = 2 + (2 * sortCount);

	out['rootConditionGroup'] = new Object();
	var filterStart = showBytes + sortBytes;
	if(filterStart < data.length)
	{
		var filterNext = filterStart;
		var traversalArrayIndices = [];
		var traversalArrayLengths = [];
		while(true)
		{
			if(filterNext >= data.length)
			{
				//QueryBuilder input reader overran the data set length
				break;
			}
			var indexPath = ['rootConditionGroup'];
			for(var i of traversalArrayIndices)
			{
				indexPath.push('conditions');
				indexPath.push(i);
			}
			var isGroup = (data[filterNext] >>> 7) == 0;
			var descended = false;
			if(isGroup)
			{
				//group
				var boolOp = (data[filterNext] & 0b01100000) >>> 5;
				boolOp = validBool[boolOp];
				var conditions = data[filterNext] & 0b00011111;
				
				var boolOpIndexPath = indexPath.slice();
				boolOpIndexPath.push('boolOp');
				arrayDynamicAssign(out, boolOpIndexPath, boolOp);
				
				var conditionIndexPath = indexPath.slice();
				conditionIndexPath.push('conditions');
				arrayDynamicAssign(out, conditionIndexPath, []);
				
				traversalArrayIndices.push(0);
				traversalArrayLengths.push(conditions);
				descended = true;
				++filterNext;
			}else{
				//condition
				var valueStartingByte = ((data[filterNext] & 0b01111111) << 8) + data[filterNext+1];
				filterNext += 2;
				var valueLength = data[filterNext++];
				var colId = (data[filterNext] << 4) + (data[filterNext+1] >>> 4);
				++filterNext;
				var alias = ki_queryBuilder_serial2alias(prefix, colId);
				var opId = data[filterNext++] & 0b00001111;
				var operator = validOps[opId];
				var value = '';
				for(var byteIndex = valueStartingByte; byteIndex < valueStartingByte+valueLength; ++byteIndex)
				{
					value += String.fromCharCode(data[byteIndex]);
				}
				
				var fieldIndexPath = indexPath.slice();
				fieldIndexPath.push('field');
				arrayDynamicAssign(out, fieldIndexPath, alias);
				
				var operatorIndexPath = indexPath.slice();
				operatorIndexPath.push('operator');
				arrayDynamicAssign(out, operatorIndexPath, operator);

				var valueIndexPath = indexPath.slice();
				valueIndexPath.push('value');
				arrayDynamicAssign(out, valueIndexPath, value);
			}
			
			var reachedEnd = false;
			while(true && !descended)
			{
				if(traversalArrayIndices.length == 0)
				{
					reachedEnd = true;
					break;
				}
				
				if(traversalArrayIndices[traversalArrayIndices.length-1] >= (traversalArrayLengths[traversalArrayLengths.length-1]-1))
				{
					traversalArrayIndices.pop();
					traversalArrayLengths.pop();
				}else{
					traversalArrayIndices[traversalArrayIndices.length-1] += 1;
					break;
				}
			}
			if(reachedEnd) break;
		}
	}
	return out;
}

function ki_queryBuilderGroup(prefix, boolOp, conditions, root)
{
	if(typeof(boolOp)     === 'undefined') boolOp = 'AND';
	if(typeof(conditions) === 'undefined') conditions = [];
	if(typeof(root)       === 'undefined') root = false;

	var content = '';
	for(var index in conditions)
	{
		var condition = conditions[index];
		var contentLine = '';
		if('boolOp' in condition)
		{
			contentLine += ki_queryBuilderGroup(prefix, condition['boolOp'], condition['conditions'], false);
		}else{
			contentLine += ki_queryBuilderCondition(prefix, condition['field'], condition['operator'], condition['value']);
		}
		content += '<li>' + contentLine + '</li>';
	}
	
	var out = '<select>';
	out += '<option ' + (boolOp == 'AND' ? 'selected' : '') + '>AND</option>';
	out += '<option ' + (boolOp == 'OR' ? 'selected' : '') + '>OR</option>';
	out += '<option ' + (boolOp == 'XOR' ? 'selected' : '') + '>XOR</option></select> &nbsp; ';
	out += '<button type="button" onclick="ki_queryBuilderAddCondition(this, \'' + prefix + '\');" title="Add Condition">✚</button> ';
	out += '<button type="button" onclick="ki_queryBuilderAddGroup(this, \'' + prefix + '\');" title="Add Group">✚⊆</button> &nbsp; ';
	if(!root)
		out += '<div>&nbsp;</div><button type="button" title="Delete" onclick="this.parentNode.parentNode.removeChild(this.parentNode);">❌</button>';
	out += '<ul>' + content	+ '</ul>';
	return out;
}

function ki_queryBuilderCondition(prefix, field, operator, value)
{
	var allFields = ki_querybuilder_fields[prefix];
	
	if(typeof(field)    === 'undefined') field = Object.keys(allFields)[0];
	if(typeof(operator) === 'undefined') operator = '';
	if(typeof(value)    === 'undefined') value = '';
	
	var out = '<select onchange="ki_queryBuilderUpdateOperator(this, \'' + prefix + '\');">';
	for(var alias in allFields)
	{
		if(allFields.hasOwnProperty(alias))
		{
			out += '<option' + (alias == field ? ' selected' : '') + '>' + alias + '</option>';
		}
	}
	
	out += '</select><select onchange="ki_queryBuilderUpdateInput(this, \'' + prefix + '\');">';
	out += ki_queryBuilderGenerateOperatorOptions(field, prefix, operator) + '</select>';
	var dataType = ki_querybuilder_fields[prefix][field]['dataType'];
	var inputDisplay = ki_queryBuilderCalculateInputDisplay(dataType, operator);
	var inputType = ki_queryBuilderCalculateInputType(dataType, operator);
	var checked = (inputType == 'checkbox' && value) ? ' checked' : '';
	out += '<input type="' + inputType + '" value="' + value + '" style="display:' + inputDisplay + ';"' + checked + '/>';
	out += '<div>&nbsp;</div>';
	out += '<button type="button" title="Delete" onclick="this.parentNode.parentNode.removeChild(this.parentNode);">❌</button>';
	return out;
}

function ki_queryBuilderGenerateOperatorOptions(alias, prefix, selected)
{
	if(typeof(selected) === 'undefined') selected = '';
	var type = ki_querybuilder_fields[prefix][alias]['dataType'];
	var nullable = ki_querybuilder_fields[prefix][alias]['nullable'] == 'YES';
	
	var ops = [];
	if(type == 'tinyint(1)')
	{
		ops = ['='];
	}else if((type.indexOf('int') !== -1) || (type.indexOf('date') !== -1)){
		ops = ['=','!=','<','<=','>','>='];
	}else{
		ops = ['=','!=','<','<=','>','>=','contains','does not contain','contained in','not contained in','matches regex',"doesn't match regex"];
	}
	if(nullable)
	{
		ops.push('is NULL');
		ops.push('is NOT NULL');
	}
	
	var html = '';
	for(var i = 0; i<ops.length; ++i)
	{
		var op =  ops[i];
		html += '<option' + (op == selected ? ' selected' : '') + '>' + op + '</option>';
	}
	return html;
}

function ki_queryBuilderUpdateOperator(field, prefix, selected)
{
	if(typeof(selected) === 'undefined') selected = '';
	var operator = field.nextElementSibling;
	var alias = $(field).val();
	operator.innerHTML = ki_queryBuilderGenerateOperatorOptions(alias, prefix, $(operator).val());
	ki_queryBuilderUpdateInput(operator, prefix);
}

function ki_queryBuilderUpdateInput(operator, prefix)
{
	operator = $(operator);
	var input = operator.next();
	var field = operator.prev();
	var alias = field.val();
	var op    = operator.val();
	var dataType = ki_querybuilder_fields[prefix][alias]['dataType'];

	input.css('display',ki_queryBuilderCalculateInputDisplay(dataType, op));
	input.attr('type',ki_queryBuilderCalculateInputType(dataType, op));
}

function ki_queryBuilderCalculateInputDisplay(dataType, op)
{
	if(op.toUpperCase() == 'IS NULL' || op.toUpperCase() == 'IS NOT NULL')
	{
		return 'none';
	}
	return 'inline-block';
}

function ki_queryBuilderCalculateInputType(dataType, op)
{
	if     (dataType == 'tinyint(1)'           ) return 'checkbox';
	else if(dataType.indexOf('int'     ) !== -1) return 'number';
	else if(dataType.indexOf('datetime') !== -1) return 'datetime-local';
	else if(dataType.indexOf('date'    ) !== -1) return 'date';
	return 'text';
}

function ki_queryBuilderAddCondition(btn, prefix)
{
	var newCond = document.createElement("LI");
	newCond.innerHTML = ki_queryBuilderCondition(prefix);
	btn.parentNode.lastChild.appendChild(newCond);
	$(newCond.firstChild).change();
}

function ki_queryBuilderAddGroup(btn, prefix)
{
	var newCond = document.createElement("LI");
	newCond.innerHTML = ki_queryBuilderGroup(prefix);
	btn.parentNode.lastChild.appendChild(newCond);
}

var ki_querybuilder_operators = ['=','!=','<','<=','>','>=','contains','does not contain','contained in','not contained in','matches regex',"doesn't match regex",'is NULL','is NOT NULL'];

/**
* form: the form whose data will be serialized
* prefix: the ID prefix of the associated QueryBuilder
*/
function ki_queryBuilderSerialize(form, prefix)
{
	//get data from DOM into meaningful object oriented format
	var data = ki_queryBuilderSerializerGather(form, prefix);
	
	//calculate buffer size for binary serialization
	var showCount = data['fieldsToShow'].length;
	var sortCount = data['sortOrder'].length;
	var showBytes = Math.ceil((12 + (12 * showCount)) / 8);
	var sortBytes = 2 + (2 * sortCount);
	var filterBytes = 0;
	if(data['rootConditionGroup'] != null)
	{
		filterBytes = data['rootConditionGroup']['inlineBytes'] + data['rootConditionGroup']['valueBytes'];
	}
	
	var bytes = showBytes + sortBytes + filterBytes;
	var out = new Uint8Array(bytes);
	
	//binpack "fields to show"
	out[0] = showCount >>> 4;
	out[1] = showCount << 4;
	for(var i=0; i<showCount; ++i)
	{
		var byteIndex = i * 1.5 + 1.5; // i * (12/8) + 1.5
		var intByteIndex = Math.floor(byteIndex);
		var alias = data['fieldsToShow'][i];
		var fieldId = ki_querybuilder_fields[prefix][alias]['serialNum'];
		if(Math.abs(byteIndex - intByteIndex) > 0.1)
		{
			//half-byte
			out[intByteIndex]   += fieldId >>> 8;
			out[intByteIndex+1] = fieldId & 0b11111111;
		}else{
			//new byte
			out[intByteIndex]   = fieldId >>> 4;
			out[intByteIndex+1] = fieldId << 4;
		}
	}
	
	//binpack "sort order"
	out[showBytes]   = sortCount >>> 4;
	out[showBytes+1] = sortCount << 4;
	for(var i = 0; i < sortCount; ++i)
	{
		var byteIndex    = showBytes + 2 + (2 * i);
		var alias        = data['sortOrder'][i]['field'];
		var fieldId      = ki_querybuilder_fields[prefix][alias]['serialNum'];
		var ascending    = data['sortOrder'][i]['direction'] == 'A';
		out[byteIndex]   = fieldId >>> 4;
		out[byteIndex+1] = fieldId << 4;
		if(!ascending) out[byteIndex+1] += 0b1000;
	}
	
	//binpack "filtering"
	if(filterBytes > 0)
	{
		var filterStart = showBytes + sortBytes; //out[] index where filter data starts
		var valueStart = filterStart + data['rootConditionGroup']['inlineBytes']; //out[] index within filter data where r-values start (the first section being the grouping data)
		var filterNext = filterStart;            //the next available byte in out[] for the grouping data, advances as we go
		var valueNext = valueStart;              //the next available byte in out[] for the values, advances as we go
		var traverse = [data['rootConditionGroup']]; //stack of pointers into the object oriented rootConditionGroup down to where we are currently processing
		var traversalArrayIndices = [];
		while(true)
		{
			var node = traverse[traverse.length-1];
			if('boolOp' in node)
			{
				//group
				if(node['boolOp'] == 'AND')
				{
					out[filterNext] = 0;
				}
				else if(node['boolOp'] == 'OR')
				{
					out[filterNext] = out[filterNext] | 0b00100000;
				}else{ //xor
					out[filterNext] = out[filterNext] | 0b01100000;
				}
				//last 5 bits store number of conditions on this level
				out[filterNext] = out[filterNext] | (node['conditions'].length & 0b00011111);
				++filterNext;
				traverse.push(node['conditions'][0]);
				traversalArrayIndices.push(0);
			}else{
				//condition
				out[filterNext] = 0b10000000;
				out[filterNext] = out[filterNext] | (valueNext  << 1 >>> 9);
				++filterNext;
				out[filterNext] = valueNext & 0b11111111;
				++filterNext;
				out[filterNext] = node['value'].length & 0b11111111;
				++filterNext;
				var alias = node['field'];
				var fieldId = ki_querybuilder_fields[prefix][alias]['serialNum'];
				out[filterNext] = fieldId >>> 4;
				++filterNext;
				out[filterNext] = (fieldId << 4) & 0b11111111;
				var opId = 0;
				for(opId = 0; opId < ki_querybuilder_operators.length; ++opId)
				{
					if(ki_querybuilder_operators[opId].toUpperCase() == node['operator'].toUpperCase())
						break;
				}
				out[filterNext] = out[filterNext] | opId;
				++filterNext;
				
				for(var valByte = 0; valByte < node['value'].length; ++valByte)
				{
					out[valueNext + valByte] = node['value'].charCodeAt(valByte);
				}
				valueNext += node['value'].length;
				
				var reachedEnd = false;
				while(true)
				{
					//back up to the previous node -- the group we were in
					traverse.pop();
					var prevNode = traverse[traverse.length-1];
					
					//get which item of this group we were on
					var traversalArrayIndex = traversalArrayIndices[traversalArrayIndices.length-1];
					
					//check if we were already at the end
					if(traversalArrayIndex < (prevNode['conditions'].length)-1)
					{
						//if not, increment to the next item and descend into it
						traversalArrayIndices[traversalArrayIndices.length-1] = ++traversalArrayIndex;
						traverse.push(prevNode['conditions'][traversalArrayIndex]);
						break;
					}else{
						//if so, break out.
						//in addition, if this is the root group, break out of the outer loop too
						if(traverse.length == 1)
						{
							reachedEnd = true;
							break;
						}
						traversalArrayIndices.pop();
					}
				}
				if(reachedEnd) break;
			}
		}
	}
	
	out = binToBase64(out);
	$('#' + prefix + '_filterResult').val(out);
	
	//$('#' + prefix + '_filterResult').val(JSON.stringify(data));
}

function ki_queryBuilderSerializerGather(form, prefix)
{
	var show = [];
	form.children('.ki_showOrder').find('input:checked').each(function(){
		show.push($(this).val());
	});
	
	var sort = [];
	form.children('.ki_sorter').find('select').each(function(){
		var val = $(this).val();
		if(val != 'N')
		{
			var item = new Object();
			item['field'] = $(this).attr('name');
			item['direction'] = val;
			sort.push(item);
		}
	});
	
	var filter = ki_queryBuilderFilterRecurse($('#' + prefix + '_filter'));
	var out = new Object();
	out['fieldsToShow'] = show;
	out['sortOrder'] = sort;
	out['rootConditionGroup'] = filter;
	return out;
}

function ki_queryBuilderFilterRecurse(obj)
{
	var out = new Object();
	var subItems    = obj.children('ul').children('li');
	var isGroup     = subItems.length > 0;
	var isCondition = obj.children('select').length == 2;
	if(isGroup)
	{
		out['boolOp'] = obj.children('select').val();
		var conditions = [];
		subItems.each(function(){
			var aSubItem = ki_queryBuilderFilterRecurse($(this));
			if(aSubItem != null)
			{
				conditions.push(aSubItem);
			}
		});
		if(conditions.length == 0) return null;
		out['conditions'] = conditions;
		
		out['inlineBytes'] = 1;
		out['valueBytes'] = 0;
		for(var conKey in conditions)
		{
			var con = conditions[conKey];
			out['inlineBytes'] += con['inlineBytes'];
			out['valueBytes'] += con['valueBytes'];
		}
		
		return out;
	}else if(isCondition){
		var field = obj.children('select').first();
		var operator = field.next();
		var value = operator.next();
		out['field'] = field.val();
		out['operator'] = operator.val();
		out['value'] = (value.attr('type') == 'checkbox') ? (value.is(':checked')?'1':'0') : value.val();
		out['inlineBytes'] = 5;
		out['valueBytes'] = out['value'].length;
		return out;
	}else{
		return null;
	}
}

function binToBase64(uint8arr)
{
	return btoa(String.fromCharCode.apply(null, uint8arr));
}

function base64ToBin(str)
{
	return new Uint8Array(atob(str).split("").map(function(c){return c.charCodeAt(0);}));
}

function arrayDynamicAssign(a, indexList, val)
{
	var out = a;
	for(var i = 0; i < indexList.length-1; ++i)
	{
		if(!(indexList[i] in out)) out[indexList[i]] = new Object();
		out = out[indexList[i]];
	}
	out[indexList[indexList.length-1]] = val;

}
