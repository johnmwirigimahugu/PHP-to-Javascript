<?php

function cVar($var) {
	return  str_replace('$', '', $var);
}

function cb_T_ARRAY($_matches) {
	$this->tmp++;
	if (strpos($_matches[0], ':') === FALSE) {
		return ($_matches[1].$this->tmp.':'.$_matches[2].$_matches[3].$_matches[4].$_matches[5]);
	} else {
		return $_matches[0];
	}
}


define('CONVERTER_STATE_DEFAULT', 	'CONVERTER_STATE_DEFAULT');
define('CONVERTER_STATE_ECHO', 		'CONVERTER_STATE_ECHO');
define('CONVERTER_STATE_ARRAY', 	'CONVERTER_STATE_ARRAY');
define('CONVERTER_STATE_CLASS', 	'CONVERTER_STATE_CLASS');
define('CONVERTER_STATE_FUNCTION', 	'CONVERTER_STATE_FUNCTION');
define('CONVERTER_STATE_FOREACH', 	'CONVERTER_STATE_FOREACH');
define('CONVERTER_STATE_PUBLIC', 	'CONVERTER_STATE_PUBLIC');
define('CONVERTER_STATE_VARIABLE',  'CONVERTER_STATE_VARIABLE');
define('CONVERTER_STATE_VARIABLE_GLOBAL',  'CONVERTER_STATE_VARIABLE_GLOBAL');
define('CONVERTER_STATE_VARIABLE_FUNCTION',  'CONVERTER_STATE_VARIABLE_FUNCTION');
define('CONVERTER_STATE_VARIABLE_CLASS',  'CONVERTER_STATE_VARIABLE_CLASS');


define('CONVERTER_STATE_STATIC', 	'CONVERTER_STATE_STATIC');
define('CONVERTER_STATE_STRING', 		'CONVERTER_STATE_STRING');
define('CONVERTER_STATE_T_PUBLIC', 		'CONVERTER_STATE_T_PUBLIC');
define('CONVERTER_STATE_T_PRIVATE', 'CONVERTER_STATE_T_PRIVATE');



abstract class CodeConverterState{

	/**
	 * @var ConverterStateMachine
	 */
	protected $stateMachine = NULL;

	function __construct(ConverterStateMachine $stateMachine){
		$this->stateMachine = $stateMachine;
	}

	function	changeToState($newState){
		$this->stateMachine->changeToState($newState);
	}

	public function		enterState($extraParams = array()){
	}
	public function		exitState($extraParams){
	}

	/**
	 * @param $name
	 * @param $value
	 * @return bool Whether the token should be reprocessed by the new state
	 */
	abstract function	processToken($name, $value, $parsedToken);

}


class CodeConverterState_Default extends CodeConverterState {

	/**
	 * @var array List of tokens that will trigger a change to the appropriate state.
	 */
	public $tokenStateChangeList = array(
		'T_ECHO' 		=>	CONVERTER_STATE_ECHO,
		'T_ARRAY'		=>	CONVERTER_STATE_ARRAY,
		'T_CLASS'		=>	CONVERTER_STATE_CLASS,
		'T_FUNCTION'	=>	CONVERTER_STATE_FUNCTION,
		'T_FOREACH'		=>	CONVERTER_STATE_FOREACH,
		'T_PUBLIC'		=>	NULL, 	//CONVERTER_STATE_PUBLIC,
		'T_VARIABLE'	=>	CONVERTER_STATE_VARIABLE,
		'T_STATIC'		=>	CONVERTER_STATE_STATIC,
		'T_STRING'		=>  CONVERTER_STATE_STRING,
		'T_VAR' 		=> CONVERTER_STATE_T_PUBLIC,
		'T_PRIVATE'		=> CONVERTER_STATE_T_PRIVATE,
	);

	function	processToken($name, $value, $parsedToken){
		if(array_key_exists($name, $this->tokenStateChangeList) == TRUE){
			$this->changeToState($this->tokenStateChangeList[$name]);
			return TRUE;
		}

		$js = $parsedToken;
		$this->stateMachine->addJS($js);
		return FALSE;
	}
}


class CodeConverterState_Echo extends CodeConverterState {

	public function		enterState($extraParams = array()){
		parent::enterState($extraParams);
	}

	function	processToken($name, $value, $parsedToken){
		$this->stateMachine->addJS('document.write('.$parsedToken);
		$this->stateMachine->setPendingSymbol(';', ")");
		$this->changeToState(CONVERTER_STATE_DEFAULT);
		return FALSE;
	}
}


class CodeConverterState_ARRAY extends CodeConverterState {

	private  		$arraySymbolRemap = array('('=>'{',	')'=>'}',);

	function	processToken($name, $value, $parsedToken){		//until ;

		if(array_key_exists($parsedToken, $this->arraySymbolRemap) == TRUE){
			 $parsedToken = $this->arraySymbolRemap[$parsedToken];//change name to other value
		}

		$this->stateChunk .= $parsedToken;

		if($name == ';'){
			$js = $this->stateChunk;

			if (strpos($js, ':') === FALSE) {
				//$this->tmp = -1;
				$js = preg_replace_callback ('/([{, \t\n])(\'.*\')(|.*:(.*))([,} \t\n])/Uis', 'cb_T_ARRAY', $js);
			}

			$this->stateMachine->addJS($js);
			$this->changeToState(CONVERTER_STATE_DEFAULT);
		}
	}
}



class CodeConverterState_CLASS extends CodeConverterState {

	function	processToken($name, $value, $parsedToken){
		if($name == "T_STRING"){
			$this->stateMachine->addJS("function $value()");
			$this->changeToState(CONVERTER_STATE_DEFAULT);
			$this->stateMachine->pushScope(CODE_SCOPE_CLASS, $value);
		}
	}
}

class CodeConverterState_FUNCTION extends CodeConverterState {

	function	processToken($name, $value, $parsedToken){

		if($name == "T_STRING"){
			if($this->stateMachine->currentScope->type == CODE_SCOPE_CLASS){

				$this->stateMachine->markMethodsStart();

				$this->stateMachine->addJS("this.$value = function");
			}
			else{
				$this->stateMachine->addJS("function $value ");
			}

			if($value == "__construct"){
				$this->stateMachine->markConstructorStart();
			}

			$this->stateMachine->pushScope(CODE_SCOPE_FUNCTION, $value);

			$this->changeToState(CONVERTER_STATE_DEFAULT);
		}
	}
}



class CodeConverterState_T_FOREACH extends CodeConverterState {

	var $chunkArray = array();

	public function		enterState($extraParams = array()){
		parent::enterState($extraParams);
		$this->chunkArray = '';
	}

	//till the {
	function	processToken($name, $value, $parsedToken){

		if ($name == 'T_VARIABLE'){
			$this->chunkArray[] = cVar($value);
		}

		if ($name == '{') {
			if (count($this->chunkArray) == 2) {
				$array = $this->chunkArray[0];
				$val = $this->chunkArray[1];
				$this->stateMachine->addJS( "for (var {$val}Val in $array) {".
					"		\n                        $val = $array"."[{$val}Val];");
			}
			if (count($this->chunkArray) == 3) {
				$array = $this->chunkArray[0];
				$key = $this->chunkArray[1];
				$val = $this->chunkArray[2];
				$this->stateMachine->addJS("for (var $key in $array) {".
					"\n                        $val = $array"."[$key];");
			}
			$this->changeToState(CONVERTER_STATE_DEFAULT);
		}
	}
}


/*
class CodeConverterState_T_PUBLIC extends CodeConverterState {

	var	$stateChunk = '';

	public function		enterState($extraParams = array()){
		parent::enterState($extraParams);
		$this->stateChunk = '';
	}

	function	processToken($name, $value, $parsedToken){

		$type = $this->findFirst(array('T_VARIABLE', 'T_FUNCTION'));

		if ($type == 'T_FUNCTION'){
			$this->changeToState(CONVERTER_STATE_DEFAULT);
			return;
		}

		//$parsedToken = $this->parseToken($name, $value);
		$this->stateChunk .= $parsedToken;

		if ($name == ';') {
			$js = str_replace(array(' '), '', $this->stateChunk);
			$result = 'this.'.$this->stateChunk;
			$this->changeToState(CONVERTER_STATE_DEFAULT);
			return $result;
		}

		if($name == '='){
			$this->changeToState('Default');
			$js = str_replace(array(' ','='), '', $this->stateChunk);
			$result = 'this.'.$js.' =';
			$this->changeToState(CONVERTER_STATE_DEFAULT);
			return $result;
		}
	}
} */

class CodeConverterState_T_VARIABLE extends CodeConverterState {

	function	processToken($name, $value, $parsedToken){

		if($this->stateMachine->currentScope->type == CODE_SCOPE_GLOBAL){
			$this->changeToState(CONVERTER_STATE_VARIABLE_GLOBAL);
			return TRUE;
		}

		if($this->stateMachine->currentScope->type == CODE_SCOPE_FUNCTION){
			$this->changeToState(CONVERTER_STATE_VARIABLE_FUNCTION);
			return TRUE;
		}

		if($this->stateMachine->currentScope->type == CODE_SCOPE_CLASS){
			$this->changeToState(CONVERTER_STATE_VARIABLE_CLASS);
			return TRUE;
		}
	}
}

class CodeConverterState_T_VARIABLE_GLOBAL extends CodeConverterState {

	function    processToken($name, $value, $parsedToken) {
		$variableName = cVar($value);
		$this->stateMachine->addScopedVariable($variableName, 0);
		$this->stateMachine->addJS($variableName);

		$this->stateMachine->clearVariableFlags();

		$this->changeToState(CONVERTER_STATE_DEFAULT);
	}
}

class CodeConverterState_T_VARIABLE_FUNCTION extends CodeConverterState {

	var $isClassVariable;

	public function		enterState($extraParams = array()){
		parent::enterState($extraParams);
		$this->isClassVariable = FALSE;
	}

	function    processToken($name, $value, $parsedToken) {

		if($value == '$this'){
			$this->isClassVariable = TRUE;
			return;
		}

		if($name == 'T_OBJECT_OPERATOR'){
			//This is skipped as private class variables are converted from
			//$this->var to var - for the joy of Javascript scoping.
			return;
		}

		$variableName = cVar($value);
		if($this->stateMachine->variableFlags & DECLARATION_TYPE_STATIC){
			$scopeName = $this->stateMachine->getScopeName();
			$this->stateMachine->addJS("if (typeof ".$scopeName.".$variableName == 'undefined') ");
		}

		$this->stateMachine->addScopedVariable($variableName, $this->stateMachine->variableFlags);

		if($this->isClassVariable == TRUE){
			$scopedVariableName = $this->stateMachine->getVariableNameForScope(CODE_SCOPE_CLASS, $variableName, $this->isClassVariable);
		}
		else{
			$scopedVariableName = $this->stateMachine->getVariableNameForScope(CODE_SCOPE_FUNCTION, $variableName, $this->isClassVariable);
		}

		$this->stateMachine->addJS($scopedVariableName);

		 $this->isClassVariable = FALSE;
		$this->stateMachine->clearVariableFlags();
		$this->changeToState(CONVERTER_STATE_DEFAULT);
	}
}

class CodeConverterState_T_VARIABLE_CLASS extends CodeConverterState {

	function    processToken($name, $value, $parsedToken) {

		$variableName = cVar($value);

		if($this->stateMachine->variableFlags & DECLARATION_TYPE_PRIVATE){
			$this->stateMachine->addJS("var ");
		}
		else if($this->stateMachine->variableFlags & DECLARATION_TYPE_PUBLIC){
			$this->stateMachine->addJS("this.");
		}
		else if($this->stateMachine->variableFlags & DECLARATION_TYPE_STATIC){
			$this->stateMachine->addJS($this->stateMachine->currentScope->getName().".");
		}


		$this->stateMachine->addScopedVariable($variableName, $this->stateMachine->variableFlags);
		$this->stateMachine->addJS($variableName);

		$this->stateMachine->clearVariableFlags();
		$this->changeToState(CONVERTER_STATE_DEFAULT);
	}
}


class CodeConverterState_T_STATIC extends CodeConverterState{

	function	processToken($name, $value, $parsedToken){
		$this->stateMachine->variableFlags |= DECLARATION_TYPE_STATIC;
		$this->changeToState(CONVERTER_STATE_DEFAULT);
	}
}



class CodeConverterState_T_PUBLIC extends CodeConverterState{

	function	processToken($name, $value, $parsedToken){

		//echo "CodeConverterState_T_PUBLIC ".NL;

		$this->stateMachine->variableFlags |= DECLARATION_TYPE_PUBLIC;
		$this->changeToState(CONVERTER_STATE_DEFAULT);
	}
}


class CodeConverterState_T_PRIVATE extends CodeConverterState{

	function	processToken($name, $value, $parsedToken){
		//echo "CodeConverterState_T_PRIVATE".NL;
		$this->stateMachine->variableFlags |= DECLARATION_TYPE_PRIVATE;
		$this->changeToState(CONVERTER_STATE_DEFAULT);
	}
}




class CodeConverterState_T_STRING extends CodeConverterState{

	function	processToken($name, $value, $parsedToken){
		if($value == 'define'){
			$this->stateMachine->addJS("// ".$value);
		}
		else if(defined($value)){
			$this->stateMachine->addJS(constant($value));
		}
		else{
			$this->stateMachine->addJS($value);
		}
		$this->changeToState(CONVERTER_STATE_DEFAULT);
	}
}




//
//class CodeConverterState_Skip extends CodeConverterState{
//
//	private $tokensToSkip = 0;
//
//	public function		enterState($extraParams = array()){
//		$this->tokensToSkip = $extraParams['tokensToShip'];
//	}
//
//	function	processToken($name, $value, $parsedToken){
//		$this->tokensToSkip--;
//
//		if($this->tokensToSkip <= 0){
//			$this->changeToState(CONVERTER_STATE_DEFAULT);
//		}
//	}
//}



?>