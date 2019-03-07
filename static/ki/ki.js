//General Util
function objToString (obj,iter)
{
	if(iter === undefined) iter = 0;
	if((typeof obj) != "object") return obj;
	if(iter > 3) return 'Object';
	
	var spacer = '             '.substring(0,iter);
    var str = '';
    for(var p in obj) {
        if(obj.hasOwnProperty(p))
		{
			if((typeof obj[p]) == "object")
			{
				str += spacer + p + '::' + objToString(obj[p], iter+1) + '\n';
			}else{
				str += spacer + p + '::' + obj[p] + '\n';
			}
        }
    }
    return str;
}

//Tabber (hacks for missing CSS4 features)
$(document).ready(function()
{
	//set default tab if active tab was not specified
	if(window.location.hash.substr(1).length < 1)
	{
		$(".ki_tabber>div:first-child:not(:has(~div:target))>a").css("background-color","#FFFFFF");
		$(".ki_tabber>div:first-child:not(:has(~div:target))>a").css("z-index","5");
		$(".ki_tabber>div:first-child:not(:has(~div:target))>div").css("visibility","visible");
	}
	//remove indication of default tab once any tab is clicked
	$(".ki_tabber>div>a").click(function()
	{
		$(".ki_tabber>div:first-child:not(:has(~div:target))>a").css("background-color","");
		$(".ki_tabber>div:first-child:not(:has(~div:target))>a").css("z-index","");
		$(".ki_tabber>div:first-child:not(:has(~div:target))>div").css("visibility","");
	});
});

//Environment Indicator
function kiEnvIndClose()
{
	$('#ki_environmentIndicator').css('display','none');
}

//DataTable
function ki_setEditVisibility(btn, inputValues)
{
	var buttonFields = btn.parent().parent().find('.ki_table_input').not('.ws-inputreplace');
	var delBtn = btn.parent().parent().find('.ki_button_confirm_container');
	for(var i = 0; i < buttonFields.length; i++)
	{
		var control = $(buttonFields[i]);
		var changed = false;
		if(control.attr('type') == "checkbox")
		{
			changed = (control.prop('checked') == true) != (inputValues[control.attr('id')] == true);
		}
		else if(control.prop('tagName') == "SELECT" && typeof(control.attr("multiple")) != 'undefined')
		{
			changed = !((control.val().length === inputValues[control.attr('id')].length) && control.val().every(
				function(element, index)
				{
					return element === inputValues[control.attr('id')][index]; 
				}
			));
		}else{
			changed = control.val() != inputValues[control.attr('id')];
		}
		if(changed)
		{
			btn.css("z-index","30");
			return;
		}
	}
	btn.css("z-index","5");
}
$(function(){
	$('.ki_sorter select').selectCycle();
});

//SelectCycle
//Call .selectCycle() on a jquery tag object of a <select> to turn it into a cycler
(function( $ )
{
	$.fn.selectCycle = function()
	{
		this.filter( "select" ).each(function()
		{
			var sel = $( this );
			sel.css('visibility', 'hidden');
			sel.css('position', 'absolute');
			var cycler = '<div class="selectCycler" onclick="ki_cycleSelect(this);"></div>'
			sel.after(cycler);
			ki_updateSelectCycler(sel.next());
		});
        return this;
	};
}( jQuery ));

function ki_cycleSelect(cyc)
{
	var jCyc = $(cyc);
	var sel = jCyc.prev();
	var options = sel.children();
	var selectedOption = options.filter('option:selected');
	selectedOption.prop('selected', false);
	if(selectedOption == options.last())
	{
		options.first.prop('selected', true);
	}else{
		selectedOption.next().prop('selected', true);
	}
	ki_updateSelectCycler(jCyc);
}

function ki_updateSelectCycler(cyc)
{
	var sel = cyc.prev();
	var options = sel.children();
	var selectedOption = options.filter('option:selected');
	cyc.text(selectedOption.text());
}