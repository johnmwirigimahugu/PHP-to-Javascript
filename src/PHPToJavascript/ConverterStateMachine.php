<?php

namespace PHPToJavascript;



function	unencapseString($string){
	if($string[0] == '"' ||
		$string[0] == "'"){
		$string = substr($string, 1);
	}

	$stringLength = strlen($string);

	if ($string[$stringLength - 1] == '"' ||
		$string[$stringLength - 1] == "'"){
		$string = substr($string, 0, -1);
	}

	return $string;
}



class	ConverterStateMachine{

	/**
	 * @var CodeConverterState
	 */
	public $currentState;

	/**
	 * @var string[]
	 */
	private $jsArray = array();

	/**
	 * @var CodeConverterState[]
	 */
	public $states = array();

	/**
	 * @var bool Are we inside static var or function?
	 */
	public $variableFlags = FALSE;

//	/**
//	 * @var TokenStream
//	 */
//	public $tokenStream;

	/**
	 * @var CodeScope
	 */
	public $rootScope = NULL;

	public $pendingSymbols = array();

	/** @var CodeScope */
	public $currentScope = NULL;

	/** @var CodeScope[] */
	public $scopesStack = array();

	public $defines = array();

	function	__construct(/* $tokenStream, */ /*$defaultState*/ ){

		//$this->tokenStream = $tokenStream;

		$this->pushScope(CODE_SCOPE_GLOBAL, 'GLOBAL');

		$this->states[CONVERTER_STATE_DEFAULT] = new CodeConverterState_Default($this);
		$this->states[CONVERTER_STATE_ECHO] = new CodeConverterState_Echo($this);
		$this->states[CONVERTER_STATE_ARRAY] = new CodeConverterState_ARRAY($this);
		$this->states[CONVERTER_STATE_CLASS] = new CodeConverterState_CLASS($this);
		$this->states[CONVERTER_STATE_FUNCTION] = new CodeConverterState_FUNCTION($this);

		$this->states[CONVERTER_STATE_FOREACH] = new CodeConverterState_TFOREACH($this);
		$this->states[CONVERTER_STATE_PUBLIC] = new CodeConverterState_TPUBLIC($this);
		$this->states[CONVERTER_STATE_VARIABLE] = new CodeConverterState_TVARIABLE($this);

		$this->states[CONVERTER_STATE_VARIABLE_GLOBAL] = new CodeConverterState_TVARIABLEGLOBAL($this);
		$this->states[CONVERTER_STATE_VARIABLE_FUNCTION] = new CodeConverterState_TVARIABLEFUNCTION($this);
		$this->states[CONVERTER_STATE_VARIABLE_CLASS] = new CodeConverterState_TVARIABLECLASS($this);

		$this->states[CONVERTER_STATE_VARIABLE_FUNCTION_PARAMETER] = new CodeConverterState_TVARIABLEPARAMETER($this);
		$this->states[CONVERTER_STATE_VARIABLE_ARRAY] = new CodeConverterState_TVARIABLEARRAY($this);


		$this->states[CONVERTER_STATE_STATIC] = new CodeConverterState_TSTATIC($this);
		$this->states[CONVERTER_STATE_STRING] = new CodeConverterState_TSTRING($this);

		$this->states[CONVERTER_STATE_T_PUBLIC] = new CodeConverterState_TPUBLIC($this);
		$this->states[CONVERTER_STATE_T_PRIVATE] = new CodeConverterState_TPRIVATE($this);

		$this->states[CONVERTER_STATE_DEFINE] = new CodeConverterState_define($this);

		$this->states[CONVERTER_STATE_T_EXTENDS] = new CodeConverterState_TEXTENDS($this);
		$this->states[CONVERTER_STATE_T_NEW] = new CodeConverterState_TNEW($this);

		$this->states[CONVERTER_STATE_VARIABLE_DEFAULT] = new CodeConverterState_VariableDefault($this);
		$this->states[CONVERTER_STATE_EQUALS] = new CodeConverterState_Equals($this);

		$this->states[CONVERTER_STATE_CLOSE_PARENS] = new CodeConverterState_CLOSEPARENS($this);

		$this->states[CONVERTER_STATE_IMPLEMENTS_INTERFACE] = new CodeConverterState_TIMPLEMENTSINTERFACE($this);
		$this->states[CONVERTER_STATE_INTERFACE] = new CodeConverterState_TINTERFACE($this);


		$this->states[CONVERTER_STATE_REQUIRE] = new CodeConverterState_REQUIRE($this);
		$this->states[CONVERTER_STATE_ABSTRACT] = new CodeConverterState_TABSTRACT($this);
		$this->states[CONVERTER_STATE_ABSTRACT_FUNCTION] = new CodeConverterState_TABSTRACTREMOVE($this);

		$this->states[CONVERTER_STATE_END_OF_CLASS] = new CodeConverterState_EndOfClass($this);
		$this->states[CONVERTER_STATE_VARIABLE_VALUE] = new CodeConverterState_VariableValue($this);

		$this->states[CONVERTER_STATE_OBJECT_OPERATOR] = new CodeConverterState_TOBJECTOPERATOR($this);

		$this->currentState = CONVERTER_STATE_DEFAULT;
	}


	function	addScopedVariable($variableName, $variableFlags){
		$this->currentScope->addScopedVariable($variableName, $variableFlags);
	}

	function	getVariableNameForScope($variableName, $isClassVariable, $variableFlags){
		return $this->currentScope->getScopedVariable($variableName, $isClassVariable, $variableFlags, TRUE);
	}

	function	findScopeType($type){
		foreach($this->scopesStack as $scope){
			//TODO How to convert this bad code into
			// $scope instanceof $variable - where $variable == classname
			if($scope->getType() == $type){
				return $scope;
			}
		}

		return NULL;
	}

	function	getJS(){
		return $this->rootScope->getJS();
	}

	public function addJS($jsString){
		if(PHPToJavascript::$TRACE){
			echo "$jsString \n";
		}
		$this->currentScope->addJS($jsString);
	}

	function	changeToState($newState, $extraParams = array()){
		if(array_key_exists($newState, $this->states) == FALSE){
			throw new \Exception("Unknown state [$newState], cannot changeState to it.");
		}

		$this->currentState = $newState;
		$this->states[$this->currentState]->enterState($extraParams);
	}

	function	clearVariableFlags(){
		$this->variableFlags = FALSE;
	}

	function	addVariableFlags($variableFlag){
		$this->variableFlags |= $variableFlag;
	}

	function	processToken($name, $value, $parsedToken){
		if(PHPToJavascript::$TRACE == TRUE){
			echo "SM ".get_class($this->getState())." token [$name] => [$value]  ".NL;
		}
		return $this->getState()->processToken($name, $value, $parsedToken);
	}

	function	getState(){
		return $this->states[$this->currentState];
	}

	function accountForOpenBrackets($name){
		if($name == "{"){
			$this->currentScope->pushBracket();
		}
		if($name == "("){
			$this->currentScope->pushParens();
		}
	}

	function accountForCloseBrackets($name){

		$scopeEnded = FALSE;

		if($name == "}"){
			$scopeEnded = $this->currentScope->popBracket();
		}
		else if($name == ")"){
			$scopeEnded = $this->currentScope->popParens();
		}


		if ($scopeEnded == TRUE){
			if(($this->currentScope instanceof GlobalScope) == FALSE){
				$poppedScope = $this->currentScope;

				$this->popCurrentScope();	//It was the last bracket for a function.

				if($poppedScope instanceof FunctionScope){
					$this->popCurrentScope();//Also pop the function paramters scope.
				}
			}
		}
	}

	function parseToken ($name, $value, $count) {

		$returnValue = $this->getPendingInsert($name);

		if($name == "T_VARIABLE"){
			$returnValue .= $value;
		}
		else if (in_array($name, array_keys(self::$_convert))) {
			if(empty(self::$_convert[$name]) == TRUE){
				$returnValue .= $name;		//keep key
			}
			else{
				$returnValue .= self::$_convert[$name];
			}
		}
		else if (in_array($name, self::$_keep)) {	//keep value
			$returnValue .= $name;
		}
		else if($name == 'T_STRING' && defined($value)){
			$returnValue .= constant($value);
		}
		else if (in_array($name, self::$_keepValue)) {
			$returnValue .= $value;
		}

		if($returnValue == 'NULL'){
			$returnValue = 'null';
		}

		return $returnValue;
	}


	function	getPendingInsert($symbolToCheck){
		foreach($this->pendingSymbols as $key => $pendingSymbol){

			$symbol = $pendingSymbol[0];
			$insert = $pendingSymbol[1];

			if($symbolToCheck == $symbol /*||
			   $symbol == '*any*' */) {
				unset($this->pendingSymbols[$key]);
				return $insert;
			}
		}

		return '';
 	}

	//TODO - HACK HACK HACK
	var $insertToken = FALSE;

	function	addSymbolAfterNextToken($symbol){
		$this->insertToken = $symbol;
	}

	function	setPendingSymbol($symbol, $insert){
		$this->pendingSymbols[] = array($symbol, $insert);
	}

	function	getScopeType(){
		return $this->currentScope->type;
	}

	function	getScopeName(){
		return $this->currentScope->getName();
	}

	function	pushScope($type, $name, $variableFlag = 0){
		if($this->currentScope != NULL){
			array_push($this->scopesStack, $this->currentScope);
		}

		switch($type){
			case(CODE_SCOPE_GLOBAL):{
				$newScope = new GlobalScope($name, $this->currentScope);
				break;
			}

			case(CODE_SCOPE_CLASS):{
				$newScope = new ClassScope($name, $this->currentScope);
				break;
			}

			case(CODE_SCOPE_FUNCTION_PARAMETERS):{
				$newScope = new FunctionParameterScope($name, $this->currentScope, $variableFlag);
				break;
			}

			case(CODE_SCOPE_FUNCTION):{
				$newScope = new FunctionScope($name, $this->currentScope);
				break;
			}

			default:{
				throw new \Exception("Unknown scope type [".$type."]");
				break;
			}
		}

		if($this->currentScope == NULL){
			$this->rootScope = $newScope;
		}
		else{
			$this->currentScope->addChild($newScope);
		}

		$this->currentScope = $newScope;

		if($type == CODE_SCOPE_CLASS){
			$this->methodsStartIndex = 0;
		}
	}

	function	popCurrentScope(){
		$previousScope = $this->currentScope;

		$this->currentScope = array_pop($this->scopesStack);

		if($previousScope instanceof ClassScope){
			$this->changeToState(CONVERTER_STATE_END_OF_CLASS, array('previousScope' => $previousScope));
		}

		if($previousScope instanceof GlobalScope){
			echo "We have left the global scope?";
		}
	}


	function	finalize(){
		$code = $this->getJS();
		return $code;
	}

	function	addDefine($name, $value){
		$name = unencapseString($name);
		$value = unencapseString($value);

		$this->defines[$name] = $value;
	}

	function	getDefine($name){
		if(array_key_exists($name, $this->defines) == TRUE){
			return $this->defines[$name];
		}

		return FALSE;
	}

	function	getClassName(){
		$scope = $this->findScopeType(CODE_SCOPE_CLASS);
		if($scope != NULL){
			return $scope->name;
		}

		throw new \Exception("Trying to get class but no class scope found.");
	}

//	function	pushBracket(){
//		$this->currentScope->pushBracket();
//	}

	function	addDefaultsForVariables(){
		$functionParametersScope = $this->findScopeType(CODE_SCOPE_FUNCTION_PARAMETERS);

		if($functionParametersScope == NULL){
			throw new \Exception("We're inside a function but we can't find the CODE_SCOPE_FUNCTION_PARAMETERS - that shouldn't be possible.");
		}

		$variablesWithDefaultParameters = $functionParametersScope->getVariablesWithDefaultParameters();

		foreach($variablesWithDefaultParameters as $variable => $default){
			$jsString = "\n";
			$jsString .= "\t\tif(typeof $variable === \"undefined\"){\n";
			$jsString .= "\t\t\t$variable = $default;\n";
			$jsString .= "\t\t}\n";

			$this->addJS($jsString);
		}
	}

	public $requireFilename = NULL;

	function requireFile($requireFilename){

		$requireFilename = str_replace('"', '', $requireFilename);
		$requireFilename = str_replace("'", '', $requireFilename);

		$this->requireFilename = $requireFilename;
	}

	function getRequiredFile(){
		$value = $this->requireFilename;
		$this->requireFilename = NULL;
		return $value;
	}



		/** @var array these token keys will be converted to their values */
	public static $_convert = array (
		'T_IS_EQUAL'=>'==',
		'T_IS_GREATER_OR_EQUAL'=>'>=',
		'T_IS_SMALLER_OR_EQUAL'=>'<=',
		'T_IS_IDENTICAL'=>'===',
		'T_IS_NOT_EQUAL'=>'!=',
		'T_IS_NOT_IDENTICAL'=>'!==',
		'T_IS_SMALLER_OR_EQUA'=>'<=',
		'T_BOOLEAN_AND'=>'&&',
		'T_BOOLEAN_OR'=>'||',
		'T_CONCAT_EQUAL'=>'+= ',
		'T_DIV_EQUAL'=>'/=',
		'T_DOUBLE_COLON'=>'.',
		'T_INC'=>'++',
		'T_MINUS_EQUAL'=>'-=',
		'T_MOD_EQUAL'=>'%=',
		'T_MUL_EQUAL'=>'*=',
		'T_OBJECT_OPERATOR'=>'.',
		'T_OR_EQUAL'=>'|=',
		'T_PLUS_EQUAL'=>'+=',
		'T_SL'=>'<<',
		'T_SL_EQUAL'=>'<<=',
		'T_SR'=>'>>',
		'T_SR_EQUAL'=>'>>=',
		'T_START_HEREDOC'=>'<<<',
		'T_XOR_EQUAL'=>'^=',
			//'T_NEW'=>'new',
		'T_ELSE'=>'else',
		'.'=>' + "" + ',
		'T_IF'=>'if',
		'T_RETURN'=>'return',
		'T_AS'=>'in',
		'T_WHILE'=>'while',
		'T_LOGICAL_AND' => 'AND',
		'T_LOGICAL_OR' => 'OR',
		'T_LOGICAL_XOR' => 'XOR',
		'T_EVAL' => 'eval',
		'T_ELSEIF' => 'else if',
		'T_BREAK' => 'break',
		'T_DOUBLE_ARROW' => ':',
	);

	/** @var array these tokens stays the same */
	public static $_keep = array(
			//'=',
		',', '}', '{',
		';',
		'(', //')',
		'*',
		'/', '+', '-', '>',
		'<', '[', ']', "\"",
		"'", ":",
	);

	/** @var array these tokens keeps their value */
	public static $_keepValue = array (
			//'T_CONSTANT_ENCAPSED_STRING',
		'T_STRING',
		'T_COMMENT',
		'T_ML_COMMENT',
		'T_DOC_COMMENT',
		'T_LNUMBER',
		'T_ENCAPSED_AND_WHITESPACE',
		'T_WHITESPACE',
		'T_SWITCH',
		'T_CASE',
		'T_DEFAULT',
		'T_THROW',
		'T_FOR'
	);

	function     generateFile($outputFilename, $originalFilename, $jsOutput) {

		$outputDirectory = pathinfo($outputFilename, PATHINFO_DIRNAME);

		ensureDirectoryExists($outputDirectory);

		$fileHandle = fopen($outputFilename, "w");

		if ($fileHandle == FALSE) {
			throw new \Exception("Failed to open file [$outputFilename] for writing.");
		}

		fwrite($fileHandle, "//Auto-generated file by PHP-To-Javascript at ".date(DATE_RFC822).NL);
		fwrite($fileHandle, "//\n");
		fwrite($fileHandle, "//DO NOT EDIT - all changes will be lost.\n");
		fwrite($fileHandle, "//\n");
		fwrite($fileHandle, "//Please edit the file " . $originalFilename . " and then reconvert to make any changes\n");
		fwrite($fileHandle, "\n");

		fwrite($fileHandle, $jsOutput);

		fclose($fileHandle);
	}

}








?>