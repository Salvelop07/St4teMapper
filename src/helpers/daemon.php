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

function daemon(){
	global $smap;

	// CLI-only compile command
	if (!IS_CLI)
		die_error('This daemon is only for CLI purpose');

	if (!empty($smap['cli_args']) && count($smap['cli_args']) > 1 && $smap['cli_args'][1] == 'stop'){
		// stopping smoothly
		
		query('UPDATE spiders SET status = "stopped" WHERE status = "stopping"');
		query('UPDATE spiders SET status = "waiting" WHERE status = "active"');
		
		$i = 0;
		while (true){
			$count = 0;
			foreach (get_col('SELECT pid FROM workers') as $pid)
				if (is_active_pid($pid))
					$count++;
					
			if (!$count)
				break;
			
			if (!$i)
				echo 'Stopping the St4teMapper daemon..'.PHP_EOL;
			echo $count.' workers remaining..'.PHP_EOL;
			sleep(5);
			$i++;
		}
		echo 'All workers stopped'.PHP_EOL;
		exit(0);
	}

	// clean spiders
	foreach (query('SELECT id, pid FROM spiders WHERE status = "active"') as $w)
		if (!is_active_pid($w['pid']))
			query('UPDATE spiders SET status = "waiting" WHERE id = %s AND status = "active"', $w['id']);

	// clean workers		
	foreach (query('SELECT id, pid FROM workers') as $w)
		if (!is_active_pid($w['pid']))
			query('DELETE FROM workers WHERE id = %s', $w['id']);
			
	// throw spiders at needs
	$pids = array();
	while (true){
		foreach (query('SELECT id, bulletin_schema FROM spiders WHERE status = "waiting"') as $spider){
			if ($lock = wait_for_lock('spider-'.$spider['bulletin_schema'], 1)){
				
				query('UPDATE spiders SET status = "active" WHERE bulletin_schema = %s', $spider['bulletin_schema']);
				unlock($lock);
				
				$smap['query']['schema'] = $spider['bulletin_schema'];
			
				// throw a spider
				close_connection(); // leave this just before forking!
				$pid = pcntl_fork(); 
				if (!$pid){ 
					define('KAOS_SPIDER_ID', $spider['id']);
					//print_log('spider '.$spider['bulletin_schema'].' started', array('color' => 'lgreen', 'spider_schema' => $spider['schema']));

					// in a spider
					require(APP_PATH.'/spider/spider.php');
					exit(0);
				}
				
				// in the parent script
				query('UPDATE spiders SET pid = %s WHERE bulletin_schema = %s AND status = "active"', array($pid, $spider['bulletin_schema']));
				$pids[$pid] = $spider['bulletin_schema'];
			}
		}
		
		sleep(5);
		
		$begin = time();
		while (($pid = pcntl_waitpid(0, $status, WNOHANG)) != -1){ // -1 forno more pid to wait for
			if ($pid === 0) // get 0 for no pid returned
				break;
				
			$status = pcntl_wexitstatus($status); 
			$schema = $pids[$pid];
			if ($status !== 0){
				update('spiders', array('status' => 'failed'), array('bulletin_schema' => $query['schema']));
				print_log('spider '.$schema.' crashed', array('color' => 'red', 'spider_schema' => $schema));
			}
			unset($pids[$pid]);
			
			if (time() - $begin > 20) // allow many spiders at once
				break;
			
			$countPids = array_filter($pids, function($x){ 
				return !empty($x); 
			});
			if (!$countPids)
				break;
		} 
	}
	//echo getmypid(); // to fill the daemon lock
	exit(0);
}
