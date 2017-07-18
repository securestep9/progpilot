<?php

/*
 * This file is part of ProgPilot, a static analyzer for security
 *
 * @copyright 2017 Eric Therond. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */


namespace progpilot\Dataflow;

use progpilot\Objects\MyInstance;
use progpilot\Objects\MyCode;
use progpilot\Objects\ArrayStatic;
use progpilot\Objects\MyDefinition;
use progpilot\Objects\MyClass;

use progpilot\Dataflow\Definitions;
use progpilot\Code\Opcodes;

class VisitorDataflow {

	private $defs;
	private $blocks;
	private $current_block_id;
	private $old_current_file;
	private $current_file;

	public function __construct() {

	}	

	protected function getBlockId($myblock) {

		if (isset($this->blocks[$myblock])) 
			return $this->blocks[$myblock];

		return -1;
	}

	protected function setBlockId($myblock) {

		if(!isset($this->blocks[$myblock]))
			$this->blocks[$myblock] = count($this->blocks);
	}

	public function analyze($context) {

		$this->old_current_file = $context->get_first_file();
		$this->current_file = $context->get_first_file();
		$mycode = $context->get_mycode();
		$index = $mycode->get_start();
		$code = $mycode->get_codes();
		
		$blocks_stack_id = [];
		$last_block_id = 0;

		do
		{
            if(isset($code[$index]))
            {
                $instruction = $code[$index];
                switch($instruction->get_opcode())
                {
                    case Opcodes::CLASSE:
                        {
                            $myclass = $instruction->get_property("myclass");
                            foreach($myclass->get_properties() as $property)
                                $property->set_source_file($this->current_file);

                            break;
                        }

                    case Opcodes::ENTER_FUNCTION:
                        {
                            $myfunc = $instruction->get_property("myfunc");

                            $defs = new Definitions();
                            $defs->create_block(0); 

                            $myfunc->set_defs($defs);

                            $this->defs = $defs;

                            $this->blocks = new \SplObjectStorage;

                            $this->current_block_id = 0;
                            
                            if($myfunc->is_method())
                            {
                                $thisdef = $myfunc->get_this_def();
                                
                                $this->defs->adddef($thisdef->get_name(), $thisdef);
                                $this->defs->addgen($thisdef->get_block_id(), $thisdef);
                            }

                            break;
                        }

                    case Opcodes::ENTER_BLOCK:
                        {
                            $myblock = $instruction->get_property("myblock");

                            $this->setBlockId($myblock);
                            $myblock->set_id($this->getBlockId($myblock));

                            $blockid = $myblock->get_id();
                            
                            array_push($blocks_stack_id, $blockid);
                            $this->current_block_id = $blockid;

                            if($blockid != 0)
                                $this->defs->create_block($blockid);  

                            $assertions = $myblock->get_assertions();
                            foreach($assertions as $assertion)
                            {
                                $mydef = $assertion->get_def();		
                                $mydef->set_block_id($blockid);
                            }
                            
                            break;
                        }

                    case Opcodes::LEAVE_BLOCK:
                        {
                            $myblock = $instruction->get_property("myblock");

                            $blockid = $myblock->get_id();
                            
                            $pop = array_pop($blocks_stack_id);
                            
                            if(count($blocks_stack_id) > 0)
                                $this->current_block_id = $blocks_stack_id[count($blocks_stack_id) - 1];

                            $this->defs->computekill($blockid);
                            
                            $last_block_id = $blockid;

                            break;
                        }


                    case Opcodes::LEAVE_FUNCTION:
                        {
                            $this->defs->reachingDefs($this->blocks);

                            $myfunc = $instruction->get_property("myfunc");
                            $myfunc->set_last_block_id($last_block_id);

                            break;
                        }

                    case Opcodes::START_INCLUDE:
                        {
                            $this->old_current_file = $this->current_file;
                            $this->current_file = $instruction->get_property("file");

                            break;
                        }

                    case Opcodes::END_INCLUDE:
                        {
                            $this->current_file = $this->old_current_file;

                            break;
                        }

                    case Opcodes::FUNC_CALL:
                        {
                            $myfunc_call = $instruction->get_property("myfunc_call");
                            $myfunc_call->set_block_id($this->current_block_id);
                            $myfunc_call->set_source_file($this->current_file);
                            
                            if($myfunc_call->is_instance())
                            {
                                $mybackdef = $myfunc_call->get_back_def();
                                $mybackdef->set_block_id($this->current_block_id);
                                $mybackdef->set_instance(true);
                                
                                $new_myclass = new MyClass($myfunc_call->getLine(), 
                                    $myfunc_call->getColumn(),
                                        $myfunc_call->get_name_instance());
                                $mybackdef->set_myclass($new_myclass);
                                
                                $this->defs->adddef($mybackdef->get_name(), $mybackdef);
                                $this->defs->addgen($mybackdef->get_block_id(), $mybackdef);
                            }

                            break;
                        }

                    case Opcodes::TEMPORARY:
                        {
                            $mydef = $instruction->get_property("temporary");
                            $mydef->set_block_id($this->current_block_id);

                            unset($mydef);

                            break;
                        }

                    case Opcodes::DEFINITION:
                        {
                            $mydef = $instruction->get_property("def");
                            $mydef->set_block_id($this->current_block_id);	
                            $mydef->set_source_file($this->current_file);
                                
                            $this->defs->adddef($mydef->get_name(), $mydef);
                            $this->defs->addgen($mydef->get_block_id(), $mydef);
                            
                            if($mydef->is_instance())
                            {
                                $myclass = $context->get_classes()->get_myclass($mydef->get_class_name());
                                if(!is_null($myclass))
                                    $mydef->set_myclass($myclass);
                                else
                                {
                                    $new_myclass = new MyClass($mydef->getLine(), 
                                        $mydef->getColumn(),
                                            $mydef->get_class_name());
                                    $mydef->set_myclass($new_myclass);
                                }
                            }
                            unset($mydef);

                            break;
                        }
                }

                $index = $index + 1;
            }
		}
		while(isset($code[$index]) && $index <= $mycode->get_end());
	}
}
