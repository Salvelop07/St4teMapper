<?php
/*
 * St4teMapper: worldwide, collaborative, public data reviewing and monitoring tool.
 * Copyright (C) 2017-2018  Salvador.h <salvador.h.1007@gmail.com>
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */ 
 
namespace St4teMapper;
	
if (!defined('BASE_PATH'))
	die();


function get_connection($closing = false){
	static $conn = null;
	if ($conn == null){
		try {
			$conn = new \mysqli(DB_HOST, DB_USER, DB_PASS);
		} catch (Exception $e){
			$conn = false;
			die_error('db connection failed: '.$e->getMessage());
		}
		if ($conn->connect_error){
			$err = $conn->connect_error;
			$conn = false;
			die_error('db connection failed: '.$err);
		}
		if (!\mysqli_select_db($conn, DB_NAME)){
			$conn = false;
			die_error('db '.DB_NAME.' not found');
		}

		execute_query($conn, 'SET sql_mode = ""');
		
	} else if ($conn === false)
		return false;
	
	if ($closing){
		\mysqli_close($conn);
		$conn = null;
		return true;
	}
	
	return $conn;
}

function close_connection(){
	return get_connection(true);
}

function get_var($query, $injectVars = array()){
	if (!preg_match('#\bLIMIT\s+[0-9]+\s*$#ius', $query))
		$query = $query.' LIMIT 1';
		
	$ret = query($query, $injectVars);
	if (is_array($ret) && !empty($ret)){
		$r = array_shift($ret[0]);
		if (is_numeric($r))
			return intval($r);
		return $r;
	}
	return null;
}

function get_col($query, $injectVars = array()){
	$ret = query($query, $injectVars);
	if (is_array($ret) && !empty($ret)){
		$values = array();
		foreach ($ret as $r){
			$v = array_shift($r);
			$values[] = is_numeric($v) ? intval($v) : $v;
		}
		return $values;
	}
	return array();
}

function get_row($query, $injectVars = array()){
	$ret = query($query, $injectVars);
	return $ret ? array_shift($ret) : null;
}

function prepare($query, $injectVars){
	if (!($conn = get_connection()) || is_error($conn))
		return $conn;
		
	if (!is_array($injectVars))
		$injectVars = array($injectVars);
		
	// TODO: protect against double injecting with %s in first injection: use pair number of quotes before %s in regexp
	foreach ($injectVars as $v)
		$query = preg_replace('/%s/', "'".\mysqli_real_escape_string($conn, $v)."'", $query, 1);

	//echo "FINAL QUERY: ".$query.PHP_EOL;		
	return $query;
}

function esc_like($str, $dir = 'both'){
	if (!($conn = get_connection()) || is_error($conn))
		return $conn;
	return "'".(in_array($dir, array('left', 'both')) ? '%' : '').\mysqli_real_escape_string($conn, $str).(in_array($dir, array('right', 'both')) ? '%' : '')."'";
}

function query($query, $injectVars = array(), $returnType = null){
	if (!($conn = get_connection()) || is_error($conn))
		return $conn;
		
	$query = prepare($query, $injectVars);
		
	$result = execute_query($conn, $query);
	if (!$result){
		$err = \mysqli_error($conn);
		$err = preg_replace('#\s*'.preg_quote('You have an error in your SQL syntax; check the manual that corresponds to your MySQL server version for the right syntax to use near ', '#').'(.*)\s*$#ius', 'syntax error near $1', $err);
		
		die_error('MySQL query error: <b>'.$err.'</b> about query <b>'.$query.'</b>');
		return false;
	}
	
	if ($returnType == 'num_rows')
		return \mysqli_num_rows($conn);
	
	if ($result === true)
		return true;
		
	$ret = array();
	while ($row = \mysqli_fetch_assoc($result))
		$ret[] = $row;
	return $ret;
}

function insert($table, $vars = array()){
	if (!($conn = get_connection()) || is_error($conn))
		return $conn;
		
//		print_json($vars);
	
	$values = array();
	foreach ($vars as $k => $v)
		$values[] = $v === null ? 'NULL' : "'".\mysqli_real_escape_string($conn, $v)."'";

	$query = 'INSERT INTO '.$table.' ( '.implode(', ', array_keys($vars)).' ) VALUES ( '.implode(', ', $values).' )';
		
	return execute_query($conn, $query, true);
}

function update_row($table, $data = array(), $where = array(), $notWhere = array(), $opts = array()){
	return update($table, $data, $where, $notWhere, $opts + array('limit' => 1));
}

function update($table, $data = array(), $where = array(), $notWhere = array(), $opts = array()){
	if (!($conn = get_connection()) || is_error($conn))
		return $conn;
	
	if (is_array($data)){
		$set = array();
		foreach ($data as $k => $v)
			$set[] = $k.' = '.($v === null ? 'NULL' : "'".\mysqli_real_escape_string($conn, $v)."'");
		$data = implode(', ', $set);
	}

	$w = array();
	foreach ($where as $k => $v)
		$w[] = $k.($v === null ? ' IS NULL' : " = '".\mysqli_real_escape_string($conn, $v)."'");
	foreach ($notWhere as $k => $v)
		$w[] = $k.($v === null ? ' IS NOT NULL' : " != '".\mysqli_real_escape_string($conn, $v)."'");

	$query = 'UPDATE '.$table.' SET '.$data;
	
	if ($w)
		$query .= ' WHERE '.implode(' AND ', $w);
	
	if (!empty($opts['limit']))
		$query .= ' LIMIT '.$opts['limit'];
		
	if (!empty($opts['debug']))
		echo $query.PHP_EOL;
		
	if (!execute_query($conn, $query))
		return false;
	return \mysqli_affected_rows($conn);
}

function execute_query($conn, $query, $returnInsertedId = false){
	global $smap, $smapDebug;
	if (empty($smapDebug['queries']))
		$smapDebug['queries'] = array();
	$begin = microtime(true);
	$ret = \mysqli_query($conn, $query);
	if ($returnInsertedId)
		$ret = $ret ? \mysqli_insert_id($conn) : false;

	$explain = array();
	if (!IS_CLI && is_dev()){
		if ($eRet = \mysqli_query($conn, 'EXPLAIN '.$query))
			while ($eRow = \mysqli_fetch_assoc($eRet))
				$explain[] = $eRow;
	}
			
	if (!IS_CLI){
		$smapDebug['queries'][] = array(
			'query' => $query,
			'explain' => $explain,
			'duration' => microtime(true) - $begin,
		);
	
	} else if (!empty($smap['debugQueries']))
		print_log('[SQL] '.$query.' ('.time_diff(microtime(true) - $begin, 0, true).')', array('color' => 'grey'));
	
	return $ret;
}

function clean_tables($all = false){
	if (IS_INSTALL)
		return;
		
	do_action('clean_tables', $all);
}

