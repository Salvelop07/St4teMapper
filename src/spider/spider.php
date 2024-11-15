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
//use \St4teMapper\BulletinParser as BulletinParser;
//use \St4teMapper\BulletinExtractor as BulletinExtractor;


global $smap;

if (!defined('BASE_PATH'))
	die();
	
/*
 * TODO: implement this vvvvvv
 * 
 * Spiders' CPU strategy:
 * 
 * if CPU is inferior by 15% and goal reached, increase goal by 10% (respecting CPU max)
 * if CPU is inferior by 3% to goal, throw 3 spiders at once then wait 20s. otherwise, wait 1min and recheck
 * if CPU is superior by 20% and no goal set or goal reached, decrease goal by 20%
 * if CPU is superior by 3% and no goal set or goal reached, decrease goal by 10%
 * 
 * 
 * 
 */

global $smap;

// spider script
$maxAttempts = 3;
$maxFixed = 3;

$query = $smap['query'];
$config = !KAOS_SPIDER_ID ? $smap['spider'] : get_spider_config(KAOS_SPIDER_ID);

/*
echo 'spider config: '.PHP_EOL;
print_r($smap['query']);
print_r($config);
echo PHP_EOL.PHP_EOL;
*/

$workers = $config['max_workers'];


if (empty($smap['query']['date']))
	$smap['query']['date'] = $query['date'] = $config['date_back'];

print_log('spider starting with '.$workers.' workers back until '.$config['date_back'].' (CPU rate: '.$config['max_cpu_rate'].'%)', array('color' => 'lgreen', 'spider_id' => KAOS_SPIDER_ID));

$pids = array();
$lastCPUCheck = $last_recheck = $goal = null;
$overload = false;

$begin = $lastConfigReload = time();
while (true){
	$starting = time() - $begin < 120; // 2min startup mode
	
	// reload the spider's config every 15 seconds
	if (KAOS_SPIDER_ID && $lastConfigReload < strtotime('-15 seconds')){
		$lastConfigReload = time();
		$config = get_spider_config(KAOS_SPIDER_ID);
	}
	
	// recheck from yesterday every 3 months, to fix errors during the spide
	if (!$last_recheck || strtotime($last_recheck) > strtotime('+3 months', strtotime($query['date']))){
		$last_recheck = $query['date'];
		$query['date'] = date('Y-m-d', strtotime('-1 day'));
	}
	
	// adjusting workers count according to CPU load
	$cpu = sys_getloadavg(); // may be replaced by http://php.net/manual/es/function.sys-getloadavg.php#118673 for linux+windows
	if ($cpu){
		$load = $cpu[0];

		if (!$lastCPUCheck || ($config['max_cpu_rate'] != 100 && ($lastCPUCheck < strtotime('-1 minute') || ($starting && $lastCPUCheck < strtotime('-20 seconds'))))){
			
			if (!$goal){
				$lastCPUCheck = time();
				
				if ($config['max_workers'] > 25 && $load > min($config['max_cpu_rate'] + 15, 95))
					$workers -= 5;
				else if ($config['max_workers'] > 15 && $load > min($config['max_cpu_rate'] + 5, 95))
					$workers -= 3;
				else if ($load < $config['max_cpu_rate'] - 15 && $workers < $config['max_workers'])
					$workers += 5;
					
				$goal = $workers;
			}
		}
		$overload = $load > min($config['max_cpu_rate'] + 15, 95);
	}
	
	$workers = max(min($workers, $config['max_workers']), 1);
	
	if (IS_CLI)
		print_log('workers goal: '.$workers.'/'.$config['max_workers'], array('spider_id' => KAOS_SPIDER_ID));
		
	clean_tables();
	
	$countPids = array_filter($pids, function($x){ 
		return !empty($x); 
	});
	
	
	if (count($countPids) < $workers && !$overload){
		
		// fill up $pid in $pids where first null
		$i = null;
		foreach ($pids as $ci => $p)
			if (empty($p)){
				$i = $ci;
				break;
			}
		if ($i === null)
			$i = count($pids);
		
		$lock = null;

		// calculate the next worker date
		while (true){

			$bulletinStatus = get_bulletin_status($query['schema'], $query['date']);
			$stop = true;

			// case fetching
			if (!($lock = lock('rewind-'.$query['schema'].'-'.$query['date']))){
				$stop = false;
				if (IS_CLI)
					print_log('skipping '.$query['date'].' due to lock', array('color' => 'lblue', 'spider_id' => KAOS_SPIDER_ID));
			
			// case too many retries
			} else if ($bulletinStatus == 'error'){
				
				if (get_bulletin_attempts($query['schema'], $query['date']) >= $maxAttempts){
					
					if (get_bulletin_fixes($query['schema'], $query['date']) >= $maxFixed){
						$stop = false;
						if (IS_CLI)
							print_log('skipping '.$query['date'].' due to max repairs', array('color' => 'lblue', 'spider_id' => KAOS_SPIDER_ID));
					
					} else {
						// fix
						if (IS_CLI)
							print_log('repairing '.$query['date'], array('color' => 'lblue', 'spider_id' => KAOS_SPIDER_ID));
						repair_bulletin($query['schema'], $query['date']);
					}
				
				} 
			
			// case extracted
			} else if (in_array($bulletinStatus, array('none', 'extracting', 'extracted')))
				$stop = false;
			
			// case fetched (and not extracting)
			else if (!$config['extract'] && in_array($bulletinStatus, array('fetched', 'parsed')))
				$stop = false;
				
			if ($stop) // important!
				break;
				
			if ($config['date_back'] && $query['date'] < $config['date_back'])
				break;
			
			// go to previous day
			$query['date'] = date('Y-m-d', strtotime('-1 day', strtotime($query['date'])));
		}

		$smap['query']['date'] = $query['date'];
		
		// stop at date_back
		if ($config['date_back'] && $query['date'] < $config['date_back']){
			unlock($lock);
			break;
		}
		
		if (KAOS_SPIDER_ID)
			$worker_id = insert('workers', array(
				'spider_id' => KAOS_SPIDER_ID,
				'type' => 'fetcher',
				'date' => $query['date'],
				'status' => 'starting',
				'pid' => null,
				'started' => date('Y-m-d H:i:s'),
			));
			
		close_connection(); // leave this just before forking!
		$pid = pcntl_fork(); 
		
		if (!$pid){ 
			// in worker
			
			unset($workers);
			
			print_log('worker '.($i+1).' started', array('color' => 'lgreen', 'worker_id' => $i));
			define('WORKER_ID', $i);
			
			if (KAOS_SPIDER_ID)
				update('workers', array(
					'status' => 'active',
				), array(
					'spider_id' => KAOS_SPIDER_ID, 
					'type' => 'fetcher',
					'date' => $query['date'],
				));
			
			$bulletinParser = new BulletinParser();
			
			print_log('starting fetch for '.$query['schema'].'/'.$query['date'].(!empty($query['id']) ? '/'.$query['id'] : '').($config['extract'] ? ' (extracting)' : ' (not extracting)'));
			$ret = $bulletinParser->fetch_and_parse($query);
			
			if (!$ret || is_error($ret))
				print_log('could not fetch '.$query['schema'].'/'.$query['date'].(!empty($query['id']) ? '/'.$query['id'] : '').($ret ? ': '.$ret->msg : ''), array('color' => 'red'));
			
			else {
				print_log('ended fetch for '.$query['schema'].'/'.$query['date'].(!empty($query['id']) ? '/'.$query['id'] : ''), array('color' => 'lgreen'));
				
				if ($ret === true)
					print_log('skipping extraction, bulletin not found nor expected');
				
				else if ($config['extract']){ // extract if no bulletin expected
					print_log('starting to extract');
		
					$extracter = new BulletinExtractor($ret);
					$ret = $extracter->extract($query, true);
					
					if ($ret === false || is_error($ret))
						print_log('could not extract '.$query['schema'].'/'.$query['date'].(!empty($query['id']) ? '/'.$query['id'] : '').($ret ? ': '.$ret->msg : ''), array('color' => 'red'));
					
					else 
						print_log('ended extraction of '.$query['schema'].'/'.$query['date'].(!empty($query['id']) ? '/'.$query['id'] : ''), array('color' => 'lgreen'));
				}
			}
			
			//$args['done'] = date('Y-m-d H:i:s');
			
			if (KAOS_SPIDER_ID)
				query('DELETE FROM workers WHERE id = %s', $worker_id);
			
			unlock($lock);
			unset($bulletinParser);
			
			exit(0); // worker done
		} 
		
		// in parent (spider)
		
		if (KAOS_SPIDER_ID)
			query('UPDATE workers SET pid = %s WHERE spider_id = %s AND type = "fetcher" AND date = %s AND status IN ( "starting", "active" )', array($pid, KAOS_SPIDER_ID, $query['date']));
		
		// go to previous day
		$query['date'] = date('Y-m-d', strtotime('-1 day', strtotime($query['date'])));
		
		$pids[$i] = $pid;
		
		if ($goal){
			$goal = null;
			sleep(5);
		
		} else if ($starting)
			sleep(2); // wait 2s on the startup
	}
	
	if (KAOS_SPIDER_ID){
		$status = get_spider_status(KAOS_SPIDER_ID);
		if (!in_array($status, array('active'))){
			print_log('spider status is now '.$status);
			break;
		}
	}
	
	$countPids = array_filter($pids, function($x){ 
		return !empty($x); 
	});
	
	if (count($countPids) >= $workers || $overload){
		worker_wait($pids, $workers);
		if ($goal && count($countPids) > $goal){
			$goal = null;
			sleep(20);
		}
	}
	
	if ($config['date_back'] && $query['date'] < $config['date_back'])
		break;
}
worker_wait($pids, null, true);

if (KAOS_SPIDER_ID && !in_array(get_spider_status(KAOS_SPIDER_ID), array('waiting')))
	update('spiders', array('status' => 'stopped'), array('id' => KAOS_SPIDER_ID));

print_log('spider '.$query['schema'].' ('.(KAOS_SPIDER_ID ? '#'.KAOS_SPIDER_ID : 'manual').') ended on '.$query['date'], array('color' => 'lgreen', 'spider_id' => KAOS_SPIDER_ID));
exit(0);
