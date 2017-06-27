<?php

/*
 * This file is part of ProgPilot, a static analyzer for security
 *
 * @copyright 2017 Eric Therond. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */


namespace progpilot\Analysis;

use PHPCfg\Block;
use PHPCfg\Func;
use PHPCfg\Op;
use PHPCfg\Script;
use PHPCfg\Visitor;
use PHPCfg\Operand;

class SecurityAnalysis {

	public static function is_safe($mydef, $mysink)
	{
		if($mydef->is_tainted())
		{
			if($mydef->is_sanitized())
			{
				if($mydef->is_type_sanitized($mysink->get_attack()))
					return true;
			}

			return false;
		}

		return true;
	}

	public static function funccall($context, $myfunc_call, $instruction)
	{
		$mysink = $context->inputs->get_sink_byname($myfunc_call->get_name());

		if($mysink != null)
		{
			$nb_params = $myfunc_call->get_nb_params();

			for($i = 0; $i < $nb_params; $i ++)
			{
				$mydef_arg = $instruction->get_property("argdef$i");
                SecurityAnalysis::call($myfunc_call, $context, $mysink, $mydef_arg);
			}
		}
	}

	public static function call($myfunc_call, $context, $mysink, $mydef)
	{
		$results = &$context->get_results();

		$temp["source"] = [];
		$temp["source_line"] = [];
		$temp["source_file"] = [];

		$nbtainted = 0;

		if(!SecurityAnalysis::is_safe($mydef, $mysink))
		{
			$tainted_expr = $mydef->get_taintedbyexpr();
			$defs_expr = $tainted_expr->get_defs();

			foreach($defs_expr as $def_expr)
			{
				if(!SecurityAnalysis::is_safe($def_expr, $mysink))
				{
					if(($def_expr->is_property() && $def_expr->get_visibility()) || !$def_expr->is_property()) 
					{
						$temp["source"][] = htmlentities($def_expr->get_name(), ENT_QUOTES, 'UTF-8');
						$temp["source_line"][] = $def_expr->getLine();
						$temp["source_file"][] = htmlentities($def_expr->get_source_file(), ENT_QUOTES, 'UTF-8');
					}
				}
			}

			$nbtainted ++;
		}

		if($nbtainted)
		{
			$temp["sink"] = htmlentities($mysink->get_name(), ENT_QUOTES, 'UTF-8');
			$temp["sink_line"] = $mydef->getLine();
			$temp["sink_file"] = htmlentities($myfunc_call->get_source_file(), ENT_QUOTES, 'UTF-8');
			$temp["vuln_name"] = htmlentities($mysink->get_attack(), ENT_QUOTES, 'UTF-8');
			$results[] = $temp;
		}
	}
}
