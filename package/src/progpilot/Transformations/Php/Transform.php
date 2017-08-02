<?php

/*
 * This file is part of ProgPilot, a static analyzer for security
 *
 * @copyright 2017 Eric Therond. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */


namespace progpilot\Transformations\Php;

use PHPCfg\Block;
use PHPCfg\Func;
use PHPCfg\Op;
use PHPCfg\Script;
use PHPCfg\Visitor;
use PHPCfg\Assertion;
use PHPCfg\Operand;

use progpilot\Objects\MyAssertion;
use progpilot\Objects\MyFunction;
use progpilot\Objects\MyBlock;
use progpilot\Objects\MyDefinition;
use progpilot\Objects\MyExpr;
use progpilot\Objects\MyClass;
use progpilot\Objects\MyOp;
use progpilot\Objects\MyFile;

use progpilot\Code\MyInstruction;
use progpilot\Code\Opcodes;

use progpilot\Transformations\Php\FuncCall;
use progpilot\Transformations\Php\Expr;
use progpilot\Transformations\Php\Assign;
use progpilot\Transformations\Php\Common;
use progpilot\Transformations\Php\Context;

class Transform implements Visitor {

	private $s_blocks;
	private $array_includes;
	private $array_requires;
	private $context;
	private $block_if_to_be_resolved;
	private $current_myfile;

	public function __construct() 
	{
		$this->s_blocks = new \SplObjectStorage;
		$this->array_includes = [];
		$this->array_requires = [];
		$this->block_if_to_be_resolved = [];
		$this->current_myfile = null;
	}

	public function set_context($context) {

		$this->context = $context;
	}

	public function enterScript(Script $script) {

		$this->context->outputs->current_includes_file = [];
		
		$this->current_myfile = new MyFile($this->context->inputs->get_file(), 0, 0);
	}

	public function leaveScript(Script $script) {

		// creating edges for myblocks structure as block structure
		foreach($this->s_blocks as $block)
		{
			$myblock = $this->s_blocks[$block];
			foreach($block->parents as $block_parent)
			{
				$myblock_parent = $this->s_blocks[$block_parent];
				$myblock->addParent($myblock_parent);
			}
		}

		foreach($this->block_if_to_be_resolved as $block_resolved)
		{
			$instruction_if = $block_resolved[0];
			$block_if = $block_resolved[1];
			$block_else = $block_resolved[2];

			if($this->s_blocks->contains($block_if) && $this->s_blocks->contains($block_else))
			{
				$myblock_if = $this->s_blocks[$block_if];
				$myblock_else = $this->s_blocks[$block_else];

				$instruction_if->add_property("myblock_if", $myblock_if);
				$instruction_if->add_property("myblock_else", $myblock_else);
			}
		}

		if($this->context->outputs->get_resolve_includes())
			$this->context->outputs->write_includes_file();
			
        //var_dump($script);
	}

	public function enterBlock(Block $block, Block $prior = null) {

		$this->inside_include = false;
		if(!($this->context->get_current_op() instanceof Op\Expr\Include_))
		{
			$myblock = new MyBlock;
			$myblock->set_start_address_block(count($this->context->get_mycode()->get_codes()));
			$this->context->set_current_block($block);

			$this->s_blocks[$block] = $myblock;

			$inst_block = new MyInstruction(Opcodes::ENTER_BLOCK);
			$inst_block->add_property("myblock", $myblock);
			$this->context->get_mycode()->add_code($inst_block);

			// block is for himself a parent block (handle dataflow for first block)
			$block->addParent($block);

			$myblock->set_id(rand());
		}
		else
			$this->inside_include = true;
	}

	public function skipBlock(Block $block, Block $prior = null) {

	}

	public function leaveOp(Op $op, Block $block) {

		if($op instanceof Op\Expr\Include_)
		{
			$inst_end_include = new MyInstruction(Opcodes::END_INCLUDE);	
			$this->context->get_mycode()->add_code($inst_end_include);
		}
	}

	public function leaveBlock(Block $block, Block $prior = null) {

		if(!$this->inside_include)
		{
			$myblock = $this->s_blocks[$block];
			$address_end_block = count($this->context->get_mycode()->get_codes());
			$myblock->set_end_address_block($address_end_block);

			$inst_block = new MyInstruction(Opcodes::LEAVE_BLOCK);
			$inst_block->add_property("myblock", $myblock);
			$this->context->get_mycode()->add_code($inst_block); 
		}
	}

	public function enterFunc(Func $func) {

		// blocks are set back to zero when entering new function
		$this->context->set_current_block(null);

		$myfunction = new MyFunction($func->name);
		$myfunction->set_start_address_func(count($this->context->get_mycode()->get_codes()));

		$inst_func = new MyInstruction(Opcodes::ENTER_FUNCTION);
		$inst_func->add_property("myfunc", $myfunction);
		$this->context->get_mycode()->add_code($inst_func);

		if(!is_null($func->class))
		{
			$class_name = $func->class->value;
			// at this moment class is defined
			$myclass = $this->context->get_classes()->get_myclass($class_name);

			if(!is_null($myclass))
			{
				$myclass->add_method($myfunction);

				$myfunction->set_visibility(Common::get_type_visibility($func->flags));
				$myfunction->set_type(MyOp::TYPE_METHOD);
				$myfunction->set_myclass($myclass);

				$mythisdef = new MyDefinition(0, 0, "this");
				$mythisdef->set_block_id(0);
				$mythisdef->set_type(MyOp::TYPE_INSTANCE);
				$mythisdef->set_assign_id(rand());
				$myfunction->set_this_def($mythisdef);
			}
		}

		foreach($func->params as $param)
		{
			$param_name = $param->name->value;
			$byref = $param->byRef;

			$mydef = new MyDefinition($param->getLine(), $param->getColumn(), $param_name);
			$mydef->set_is_ref($byref);
			$myfunction->add_param($mydef);

			$inst_def = new MyInstruction(Opcodes::DEFINITION);
			$inst_def->add_property("def", $mydef);
			$this->context->get_mycode()->add_code($inst_def);

			unset($mydef);
		}

		// because when we call (funccall) a function by name, it can be undefined
		$this->context->get_functions()->add_function($myfunction->get_name(), $myfunction);
		$this->context->set_current_func($myfunction);
	}

	public function leaveFunc(Func $func) {

		$mycode_codes = $this->context->get_mycode()->get_codes();
		$inst = $mycode_codes[count($mycode_codes) - 1];

		// we can have a block opened and we need to leave it
		if($inst->get_opcode() != Opcodes::LEAVE_BLOCK)
		{
			if(!is_null($this->context->get_current_block()))
			{
				$myblock = $this->s_blocks[$this->context->get_current_block()];
				$myblock->set_end_address_block(count($this->context->get_mycode()->get_codes()));

				$inst_block = new MyInstruction(Opcodes::LEAVE_BLOCK);
				$inst_block->add_property("myblock", $myblock);
				$this->context->get_mycode()->add_code($inst_block);
			}
		}

		$class_name = null;
		if(!is_null($func->class))
			$class_name = $func->class->value;

		$myfunction = $this->context->get_functions()->get_function($func->name, $class_name);
		$myfunction->set_last_line($this->context->get_current_line());
		$myfunction->set_last_column($this->context->get_current_column());
		$myfunction->set_last_block_id(-1);

		$address_func = count($this->context->get_mycode()->get_codes());
		$myfunction->set_end_address_func($address_func);

		$inst_func = new MyInstruction(Opcodes::LEAVE_FUNCTION);
		$inst_func->add_property("myfunc", $myfunction);
		$this->context->get_mycode()->add_code($inst_func);
	}

	public function parse_condition($inst_start_if, $cond)
	{  
		foreach($cond as $ops)
		{
			if($ops instanceof Op\Expr\BooleanNot)
			{
				$inst_start_if->add_property("not_boolean", true);
				$this->parse_condition($inst_start_if, $ops->expr->ops);
			}
		}
	}

	public function enterOp(Op $op, Block $block) {

		$this->context->set_current_op($op);
		$this->context->set_current_block($block);

		// for theses objects getline et getcolumn methods exists except for assertion	
		if($op instanceof Op\Stmt || 
				($op instanceof Op\Expr && !($op instanceof Op\Expr\Assertion)) || 
				$op instanceof Op\Terminal)
		{
			if($op->getLine() != -1 && $op->getColumn() != -1)
			{
				$this->context->set_current_line($op->getLine());
				$this->context->set_current_column($op->getColumn());
			}
		}

		if($op instanceof Op\Stmt\JumpIf)
		{
			$inst_start_if = new MyInstruction(Opcodes::COND_START_IF);	
			$this->context->get_mycode()->add_code($inst_start_if);

			$this->block_if_to_be_resolved[] = [$inst_start_if, $op->if, $op->else];

			$this->parse_condition($inst_start_if, $op->cond->ops);
		}
		/*
		   const TYPE_INCLUDE = 1;
		   const TYPE_INCLUDE_OPNCE = 2;
		   const TYPE_REQUIRE = 3;
		   const TYPE_REQUIRE_ONCE = 4;
		 */
		else if($op instanceof Op\Expr\Include_ && $this->context->get_analyze_includes())
		{
			if(!isset($this->context->get_current_op()->expr->value))
			{
				$continue_include = false;

				$myinclude = $this->context->inputs->get_include_bylocation(
						$this->context->get_current_line(),
						$this->context->get_current_column(),
						$this->context->get_first_file());

				if(!is_null($myinclude))
				{
					$continue_include = true;
					$name = $myinclude->get_value();
				}
			}
			else
			{
				$continue_include = true;
				$name = $this->context->get_current_op()->expr->value;
			}

			if($continue_include)
			{
				$file = $this->context->get_path()."/".$name;

				if((!in_array($file, $this->array_includes, true) && $op->type == 2)
						|| (!in_array($file, $this->array_requires, true) && $op->type == 4)
						|| ($op->type == 1 || $op->type == 3))
				{
					if($op->type == 2)
						$this->array_includes[] = $file;

					if($op->type == 4)
						$this->array_requires[] = $file;
						
                    $myfile = new MyFile($file, 
                        $this->context->get_current_line(),
                            $this->context->get_current_column());
                    $myfile->set_included_from_myfile($this->current_myfile);
                    
                    $this->current_myfile = $myfile;

					$inst_start_include = new MyInstruction(Opcodes::START_INCLUDE);	
					$inst_start_include->add_property("myfile", $myfile);	
					$this->context->get_mycode()->add_code($inst_start_include);

					$analyzer = new \progpilot\Analyzer;
					$this->context->inputs->set_file($file);
					$scriptbis = $analyzer->parse($this->context);

					// another option is to $analyser->transform() to obtain mycode of included file 
					// and replace all include opcode in the main mycode by sub mycode
					if(isset($scriptbis->main->cfg))
						$op->included = $scriptbis->main->cfg;
				}
			}
			else
			{
				if($this->context->outputs->get_resolve_includes())
				{
					$myfile_temp = new MyFile($this->context->get_first_file(),
                        $this->context->get_current_line(),
                            $this->context->get_current_column());

					$this->context->outputs->current_includes_file[] = $myfile_temp;
				}
			}
		}

		if($op instanceof Op\Expr\Include_)
		{
			$myexpr = new MyExpr($this->context->get_current_line(), $this->context->get_current_column());
			$this->context->get_mycode()->add_code(new MyInstruction(Opcodes::START_EXPRESSION));

			$inst_funcall_main = new MyInstruction(Opcodes::FUNC_CALL);
			$inst_funcall_main->add_property("funcname", "include");

			$myfunction_call = new MyFunction("include");
			$myfunction_call->setLine($this->context->get_current_line());
			$myfunction_call->setColumn($this->context->get_current_column());
			$myfunction_call->set_nb_params(1);

			FuncCall::argument($this->context, rand(), $this->context->get_current_op()->expr, $inst_funcall_main, "include", 0);

			$inst_funcall_main->add_property("myfunc_call", $myfunction_call);
			$inst_funcall_main->add_property("expr", $myexpr);
			$inst_funcall_main->add_property("arr", false);
			$this->context->get_mycode()->add_code($inst_funcall_main);

			$inst_end_expr = new MyInstruction(Opcodes::END_EXPRESSION);
			$inst_end_expr->add_property("expr", $myexpr);
			$this->context->get_mycode()->add_code($inst_end_expr);
		}

		/*
		   const MODE_NONE = 0;
		   const MODE_UNION = 1;  ==> "|" '&'
		   const MODE_INTERSECTION = 2; ==> "!"
		 */
		else if($op instanceof Op\Expr\Assertion)
		{
			$arr = null;

			$name = Common::get_name_definition($this->context->get_current_op()->result);
			$type = Common::get_type_definition($this->context->get_current_op()->result);

			$mydef = new MyDefinition($this->context->get_current_line(), $this->context->get_current_column(), $name);

			// because it's obligatory in resolve defs
			$myexpr = new MyExpr($this->context->get_current_line(), $this->context->get_current_column());
			$mydef->add_expr($myexpr);
			$mydef->set_source_myfile($this->current_myfile);

			if($type == MyOp::TYPE_ARRAY)
			{
				$arr = BuildArrays::build_array_from_ops($this->context->get_current_op()->result, false);
				$mydef->set_arr_value($arr);
				$mydef->set_type(MyOp::TYPE_ARRAY);
			}

			if($op->assertion instanceof Assertion\NegatedAssertion)
				$op = $op->assertion->value[0];

			else if($op->assertion instanceof Assertion\TypeAssertion)
				$op = $op->assertion;

			if($op instanceof Assertion\TypeAssertion)
				$type = $op->value->value;

			$myassertion = new MyAssertion($mydef, $type);

			// the assertion is true when in the block
			$myblock = $this->s_blocks[$block];
			$myblock->add_assertion($myassertion);
		}
		else if($op instanceof Op\Terminal\Return_)
		{
			Assign::instruction($this->context, true);

			$inst = new MyInstruction(Opcodes::RETURN_FUNCTION);	
			$inst->add_property("return_defs", $this->context->get_current_func()->get_return_defs());
			$this->context->get_mycode()->add_code($inst);
		}
		else if($op instanceof Op\Expr\Eval_)
		{
			$myexpr = new MyExpr($this->context->get_current_line(), $this->context->get_current_column());
			$this->context->get_mycode()->add_code(new MyInstruction(Opcodes::START_EXPRESSION));

			$inst_funcall_main = new MyInstruction(Opcodes::FUNC_CALL);
			$inst_funcall_main->add_property("funcname", "eval");

			$myfunction_call = new MyFunction("eval");
			$myfunction_call->setLine($op->getLine());
			$myfunction_call->setColumn($op->getColumn());
			$myfunction_call->set_nb_params(1);

			FuncCall::argument($this->context, rand(), $op->expr, $inst_funcall_main, "eval", 0);

			$inst_funcall_main->add_property("myfunc_call", $myfunction_call);
			$inst_funcall_main->add_property("expr", $myexpr);
			$inst_funcall_main->add_property("arr", false);
			$this->context->get_mycode()->add_code($inst_funcall_main);

			$inst_end_expr = new MyInstruction(Opcodes::END_EXPRESSION);
			$inst_end_expr->add_property("expr", $myexpr);
			$this->context->get_mycode()->add_code($inst_end_expr);
		}
		else if($op instanceof Op\Terminal\Echo_)
		{
			$myexpr = new MyExpr($this->context->get_current_line(), $this->context->get_current_column());
			$this->context->get_mycode()->add_code(new MyInstruction(Opcodes::START_EXPRESSION));

			$inst_funcall_main = new MyInstruction(Opcodes::FUNC_CALL);
			$inst_funcall_main->add_property("funcname", "echo");

			$myfunction_call = new MyFunction("echo");
			$myfunction_call->setLine($op->getLine());
			$myfunction_call->setColumn($op->getColumn());
			$myfunction_call->set_nb_params(1);

			FuncCall::argument($this->context, rand(), $op->expr, $inst_funcall_main, "echo", 0);

			$inst_funcall_main->add_property("myfunc_call", $myfunction_call);
			$inst_funcall_main->add_property("expr", $myexpr);
			$inst_funcall_main->add_property("arr", false);
			$this->context->get_mycode()->add_code($inst_funcall_main);

			$inst_end_expr = new MyInstruction(Opcodes::END_EXPRESSION);
			$inst_end_expr->add_property("expr", $myexpr);
			$this->context->get_mycode()->add_code($inst_end_expr);
		}
		else if($op instanceof Op\Expr\Print_)
		{
			$myexpr = new MyExpr($this->context->get_current_line(), $this->context->get_current_column());
			$this->context->get_mycode()->add_code(new MyInstruction(Opcodes::START_EXPRESSION));

			$inst_funcall_main = new MyInstruction(Opcodes::FUNC_CALL);
			$inst_funcall_main->add_property("funcname", "print");

			$myfunction_call = new MyFunction("print");
			$myfunction_call->setLine($op->getLine());
			$myfunction_call->setColumn($op->getColumn());
			$myfunction_call->set_nb_params(1);

			FuncCall::argument($this->context, rand(), $op->expr, $inst_funcall_main, "print", 0);

			$inst_funcall_main->add_property("myfunc_call", $myfunction_call);
			$inst_funcall_main->add_property("expr", $myexpr);
			$inst_funcall_main->add_property("arr", false);
			$this->context->get_mycode()->add_code($inst_funcall_main);

			$inst_end_expr = new MyInstruction(Opcodes::END_EXPRESSION);
			$inst_end_expr->add_property("expr", $myexpr);
			$this->context->get_mycode()->add_code($inst_end_expr);
		}
		else if($op instanceof Op\Expr\FuncCall)
		{
			if(Common::is_funccall_withoutreturn($op))
			{
				// expr of type "assign" to have a defined return
				$myexpr = new MyExpr($this->context->get_current_line(), $this->context->get_current_column());
				$this->context->get_mycode()->add_code(new MyInstruction(Opcodes::START_EXPRESSION));

				FuncCall::instruction($this->context, $myexpr, rand(), false);

				$inst_end_expr = new MyInstruction(Opcodes::END_EXPRESSION);
				$inst_end_expr->add_property("expr", $myexpr);
				$this->context->get_mycode()->add_code($inst_end_expr);
			}
		}
		else if($op instanceof Op\Expr\MethodCall)
		{
			if(Common::is_funccall_withoutreturn($op))
			{
				$class_name = Common::get_name_definition($op->var);

				$myexpr = new MyExpr($this->context->get_current_line(), $this->context->get_current_column());

				$this->context->get_mycode()->add_code(new MyInstruction(Opcodes::START_EXPRESSION));

				FuncCall::instruction($this->context, $myexpr, rand(), false, true);

				$inst_end_expr = new MyInstruction(Opcodes::END_EXPRESSION);
				$inst_end_expr->add_property("expr", $myexpr);
				$this->context->get_mycode()->add_code($inst_end_expr);
			}
		}
		else if($op instanceof Op\Expr\Assign || $op instanceof Op\Expr\AssignRef)
		{
			Assign::instruction($this->context);
		}
		else if($op instanceof Op\Stmt\Class_)
		{
			$class_name = Common::get_name_definition($op);

			$myclass = new MyClass($this->context->get_current_line(), $this->context->get_current_column(), $class_name);
			$this->context->get_classes()->add_myclass($myclass);

			foreach($op->stmts->children as $property)
			{
				// if($property instanceof Op\Stmt\ClassMethod)
				if($property instanceof Op\Stmt\Property)
				{
					$property_name = Common::get_name_definition($property);
					$visibility = Common::get_type_visibility($property->visiblity);

					$mydef = new MyDefinition($property->getLine(), $property->getColumn(), "this");
					$mydef->property->set_visibility($visibility);
					$mydef->property->set_name($property_name);
					$mydef->set_class_name($class_name);

					// it's necessary for securityanalysis (visibility)
					$mydef->set_type(MyOp::TYPE_PROPERTY);
					$myclass->add_property($mydef);

					unset($mydef);
				}
			}

			$inst_class = new MyInstruction(Opcodes::CLASSE);
			$inst_class->add_property("myclass", $myclass);
			$this->context->get_mycode()->add_code($inst_class);
		}
	}
}

?>
