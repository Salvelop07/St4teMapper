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

// parses src/helpers/boot.php to get the legend of each helper
function get_helpers_list(){
	$helpers = array();
	$content = file_get_contents(APP_PATH.'/helpers/boot.php');
	if (preg_match('#LOAD\s+ALL\s+HELPERS(.*?)LOAD\s+ALL\s+HELPERS#ius', $content, $m)
		&& preg_match_all('#^.*\brequire[^"\']*(["\'])([a-z0-9_-]+)\.php["\'].*?(//\s*(.*)\s*)?$#ium', $m[1], $lines, PREG_SET_ORDER))
		foreach ($lines as $line)
			$helpers[$line[2]] = !empty($line[4]) ? $line[4] : '';
	foreach (ls_dir(APP_PATH.'/helpers') as $file)
		if (preg_match('#^(.*)\.php$#i', $file, $m) && !isset($helpers[$m[1]]))
			$helpers[$m[1]] = '';
	return $helpers;
}

// generate manuals and language files
function compile(){

	// CLI-only compile command
	if (!IS_CLI)
		die_error('This daemon is only for CLI purpose');
	
	// generate language file (from JSON to PHP)
	$labels = array();
	$keys = array('meaning', 'related', 'issuing', 'target', 'own', 'stats', 'required', 'note', 'amount', 'label');
	
	foreach (get_status_labels() as $type => $actions)
		foreach ($actions as $action => $c){
			$c = (array) $c;
			foreach ($keys as $k)
				if (isset($c[$k])){
					if (is_string($c[$k])){
						if (!is_numeric($c[$k]))
							$labels[] = '_("'.str_replace('"', '\\"', $c[$k]).'");';
					} else if (is_object($c[$k])){
						$c[$k] = (array) $c[$k];
						foreach ($keys as $k2)
							if (isset($c[$k][$k2]) && is_string($c[$k][$k2]) && !is_numeric($c[$k][$k2]))
								$labels[] = '_("'.str_replace('"', '\\"', $c[$k][$k2]).'");';
					}
				}
		}
						
	$dest_file = APP_PATH.'/languages/statuses.autogen.php';
	@unlink($dest_file);
	if (file_put_contents($dest_file, '<?php'."\n".get_disclaimer('php').'


/*
 * /!\\ AUTO-GENERATED TRANSLATION FILE, DO NOT MODIFY! 
 * Instead, you may edit schemas/statuses.json and regenerate this file entering "smap compile" in a console.
 */
 
exit(); // this file only serves for translation purpose
 

'.implode("\n", $labels)."\n\n"))
		echo 'generated status translation file src/languages/statuses.autogen.php from schemas/statuses.json'.PHP_EOL;

	// now, generate manuals
	echo 'generating manuals..'.PHP_EOL.PHP_EOL;

	// generate statuses table
	$count = 0;
	$statusTable = array();

	foreach (get_status_labels() as $type => $c)
		foreach ((array) $c as $action => $cc){
			$required = array();
			if (isset($cc->required))
				foreach ($cc->required as $k => $l)
					$required[] = $k.': '.$l;
					
			$icon = '';
			if (!empty($cc->icon))
				$icon = $cc->icon;
			$statusTable[] = '| '.($icon ? '<img src="'.PROD_APP_URL.'/addons/fontawesome_favicons/'.$icon.'.ico" valign="middle" />' : '').' | ```'.$type.'``` | ```'.$action.'``` | '.(isset($cc->meaning) ? $cc->meaning : '').' | '.implode("<br>", $required).' | ';
		}

	$statusTable = '

| | Type | Action | Meaning | Required attributes |
| ---- | ---- | ----- | ----- | ---- |
'.implode("\n", $statusTable).'

';

	$files = ls_dir(BASE_PATH.'/documentation/manuals/templates');
	usort($files, function($a, $b){
		if ($a == 'README.tpl.md')
			return -1;
		if ($b == 'README.tpl.md')
			return 1;
		return $a > $b;
	});

	// helpers table
	$helpersTable = array();
	foreach (get_helpers_list() as $h_id => $h_desc)
		$helpersTable[] = '| '.$h_id.' | '.$h_desc.' |'."\n";
	$helpersTable = '| Helper | Description |'."\n".'| ---- | ---- |'."\n".implode('', $helpersTable);
		
	// generate each .tpl.md manual template file, one by one
	foreach ($files as $file)
		if (preg_match('#^(.*)\.tpl\.md$#iu', $file, $fileParts)){
			$filename = $fileParts[1];
			$content = file_get_contents(BASE_PATH.'/documentation/manuals/templates/'.$file);
			
			$content = str_replace('{RepoRoot}', get_repository_url(), $content);
			
			$copy_to = array();
			
			// compile all manuals to documentation/manuals/
			if ($filename == 'README')
				$copy_to[] = 'README.md';
			else
				$copy_to[] = 'documentation/manuals/'.$filename.'.md';
				
			// first lines can be {CopyTo DEST} (to copy the compile manual to another location in the git)
			if (preg_match_all('#^(?:\s*\{CopyTo\s+([^\}]+)\s*\})*\s*\{CopyTo\s+([^\}]+)\s*\}#', $content, $copy_pathes, PREG_SET_ORDER)){
				$content = trim_any(preg_replace('#^'.preg_quote($copy_pathes[0][0], '#').'#', '', $content));
				foreach (array_slice($copy_pathes[0], 1) as $cpath)
					if (!empty($cpath)){
						if (!is_dir(BASE_PATH.'/'.dirname($cpath))){
							echo 'Bad {CopyTo ..} path "'.$cpath.'" in manual '.$file.PHP_EOL;
							exit(1);
						}
						$copy_to[] = $cpath;
					}
			}  
			// replace variables and inject templates
			if (preg_match_all('#\{\s*(Include(?:Inline)?)\s+([a-z0-9_-]+)(?:\((.*?)\))?\s*\}#ius', $content, $matches, PREG_SET_ORDER)){
				foreach ($matches as $m){
					
					$inputParams = array();
					if (isset($m[3])){
						$inputParamsStr = trim($m[3]);
						if ($inputParamsStr != '')
							foreach (explode(', ', $inputParamsStr) as $cinputParams)
								$inputParams[] = str_replace(array('\\(', '\\)'), array('(', ')'), trim($cinputParams));
					}
					
					// retrieve template part
					$subfile = $m[2].'.part.md';
					if (!file_exists($path = BASE_PATH.'/documentation/manuals/templates/parts/'.$subfile))
						die_error('missing '.$subfile.' in documentation/manuals/templates/'.$file);
						
					$part = file_get_contents($path);
					
					$part = str_replace('{RepoRoot}', get_repository_url(), $part);
					
					// inject call variables in the template part
					if (preg_match_all('#\{\s*\$([0-9]+)\s*\}#ius', $part, $vars, PREG_SET_ORDER))
						foreach ($vars as $var){
							$i = intval($var[1]);
							$part = str_replace($var[0], isset($inputParams[$i-1]) ? $inputParams[$i-1] : '', $part);
						}
					
					if (strtolower($m[1]) == 'includeinline')
						$part = preg_replace( "/\r|\n/", "", $part);
					$content = str_replace($m[0], $part, $content);
				}
			}
			
			// inject less complex (or static) variables
			$content = str_replace('{StatusTable}', $statusTable, $content);
			$content = str_replace('{HelpersTable}', $helpersTable, $content);

			$content = str_replace('{IncludeVar Version}', SMAP_VERSION, $content);
			$content = str_replace('{IncludeEncodedVar Version}', esc_attr(SMAP_VERSION), $content);
			$content = str_replace('{IncludeVar Slogan}', get_slogan(), $content);
			$content = str_replace('{IncludeVar License}', get_license(false), $content);
			$content = str_replace('{IncludeEncodedVar License}', esc_attr(get_license(false)), $content);
			$content = str_replace('{IncludeVar LicenseShort}', get_license(true), $content);
			$content = str_replace('{IncludeEncodedVar LicenseShort}', esc_attr(get_license(true)), $content);
			$content = str_replace('{IncludeVar CopyrightRange}', get_copyright_range(), $content);
			
			// convert {IncludeIcon ANY_FONTAWESOME_ICON}
			$content = str_replace('{IncludeIconRoot}', 'https://github.com/Salvelop07/St4teMapper/tree/master/src/addons/fontawesome_favicons/', $content);
			$content = preg_replace_callback('#{IncludeIcon (.*?)}#us', function($m){
				return 'https://github.com/Salvelop07/St4teMapper/tree/master/src/addons/fontawesome_favicons/'.$m[1].'.ico';
			}, $content);
			
			
			// really write the manual to disk
			if ($content != ''){
				foreach ($copy_to as $dest_path){
					echo '- '.$dest_path;
					if (!is_writable(dirname(BASE_PATH.'/'.$dest_path))){
						echo ' -> could NOT be written. Stopping.'.PHP_EOL;
						exit(1);
					}
					
					@unlink(BASE_PATH.'/'.$dest_path);
					if (!file_put_contents(BASE_PATH.'/'.$dest_path, $content)){
						echo ' -> could NOT be written. Stopping.'.PHP_EOL;
						exit(1);
					}
					echo PHP_EOL;
				}
				$count++;
			}
		}

	echo PHP_EOL.'generated '.number_format($count, 0).' manuals'.PHP_EOL;
	exit(0);
}
