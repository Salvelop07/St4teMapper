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


ob_start();

?>St4teMapper Copyright (C) <?= get_copyright_range() ?>  Salvador.h <salvador.h.1007@gmail.com> 
  
  * This program comes with ABSOLUTELY NO WARRANTY; type the same command followed by "-l" for details.
  * This is free software, and you are welcome to redistribute it
  * under certain conditions; type the same command followed by "-l" for details.
	
  * This program is a redesign of Kaos155 <https://github.com/ingobernable/Kaos155> developped by the same Ingoberlab team.
  * It aims at providing a worldwide, collaborative, public data reviewing and monitoring tool.

[ Usage: ] _______________________________________________________________

<?php
	$commands = get_cli_commands();
	
	$cur_title = null;
	foreach ($commands as $k => $v){
		if (is_numeric($k)){
			echo ($v == '' ? '' : '  '.str_pad($v.': ', 100, '_'))."\n";
			if ($v != '')
				$cur_title = $v;
		} else
			echo '  '.($cur_title ? '  ' : '').str_pad($k != '"' ? 'smap '.$k : '', 48 + ($cur_title ? 0 : 2), ' ').($k != '"' ? '- ' : '  ').$v."\n";
	}

/*
?>
  
[ Schemas list: ] _________________________________________________________

<?php 		

cli_print_dir('  ', 47); 
*/

echo str_replace("\n", PHP_EOL, ob_get_clean()).PHP_EOL;

if (IS_CLI)
	exit(0);
