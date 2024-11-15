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


function convert_currency($value, $currency, $destCurrency = 'USD'){
	$ret = array(
		'value' => $value,
		'unit' => strtoupper($currency)
	);
	
	if (!empty($currency)){

		do {
			
			$config = null;
			foreach (get_currencies() as $cCurrency => $cConfig)
				if (in_array($ret['unit'], $cConfig['labels'])){
					$config = $cConfig;
					if (!isset($ret['original_unit'])){
						$ret['original_unit'] = $cCurrency;
						$ret['original_value'] = $ret['value'];
					}
					break;
				}
				
			if (!$config || empty($config['rate']) || $ret['unit'] == $destCurrency)
				return $ret;
			
			// convert from one currency to another + force grabbing real currencies (not anything after the amount) -> gen a list of currencies
			
			foreach ($config['rate'] as $cdestCurrency => $destRate){
				if (!isset($config['rate'][$destCurrency]) || $destCurrency == $cdestCurrency){
					$ret['value'] = $destRate * $ret['value'];
					$ret['unit'] = $cdestCurrency;
					break;
				}
			}
		
		} while (true);
		
		return $ret;
	}
	return $value;
}

function get_currencies(){
	return array(

		'EUR' => array(
			'singular' => 'Euro',
			'plural' => 'Euros',
			'labels' => array('EUROS', 'EURO', 'EUR', 'E', '€'),
			'rate' => array('USD' => 1.16),
		),
		
		'ESP' => array(
			'singular' => 'Peseta',
			'plural' => 'Pesetas',
			'labels' => array('PESETAS', 'PESETA', 'PTS', 'PTAS', 'PTA', '€'),
			'rate' => array('EUR' => 166.386),
		),
		
		'USD' => array(
			'singular' => 'US Dollar',
			'plural' => 'US Dollars',
			'labels' => array('US DOLLARS', 'US DOLLAR', 'DOLLARS', 'DOLLAR', 'USD', 'US$', '$'),
			'rate' => array('EUR' => 1/1.16),
		),

	);
}

function convert_amount($amount, $schema = null){
/*
	if ($amount['type'] == 'currency'){
		print_r($amount);
	}
*/
	if (!isset($amount['amount']) || !isset($amount['unit'])){
		if (IS_CLI)
			print_log('bad formed amount given: '.print_r($amount, true), array('color' => 'red')); // TODO: should log this somewhere visible
		return null;
	}
		
	$v = $amount['amount'];
		
	$ret = array(
		'original_value' => $v * 100,
		'original_unit' 	=> $amount['unit'],
		'value' 		=> $v * 100,
		'unit' 			=> $amount['unit'],
	);
	switch ($amount['type']){
		case 'currency':
			$ret = convert_currency($v * 100, $amount['unit']) + $ret;
			break;
	}
/*
	if ($amount['type'] == 'currency'){
		print_r($ret);
		die();
	}
*/
	return $ret;
}

// money amount pattern
function get_amount_pattern($currency = true){
	$labels = array();
	foreach (get_currencies() as $c)
		$labels = array_merge($labels, $c['labels']);
	return '((?:[0-9\s]{1,3})(?:[,\.\s]?[0-9\s]{3})*(?:[\.,][0-9\s]+)?)'.($currency ? '(\s*(?:'.implode('|', $labels).'))?' : '');
}

function format_number_nice($count, $decimals = true, $sep = '', $remove_decimal_if_big = true){
	if ($count < 1000)
		return number_format($count, 0);
	return format_sci($count, $decimals ? 1 : 0, $sep, 1000, $remove_decimal_if_big);
	//$count = $count/1000;
	//return number_format(floor($count), 0).($decimals && $count < 100 ? '.'.floor(($count - floor($count)) * 10) : '').'k';
}

function format_sci($size, $precision = 0, $sep = '', $base_num = 1000, $remove_decimal_if_big = true){ 
    $base = log($size, $base_num);
    $suffixes = array('', 'K', 'M', 'G', 'T');   
    
    $integer = pow($base_num, $base - floor($base));
    if ($remove_decimal_if_big && $integer >= 100)
		$precision = 0;
    return number_format($integer, $precision) . $sep . $suffixes[floor($base)];
}
