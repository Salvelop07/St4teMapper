<?php
/*
 * StateMapper: worldwide, collaborative, public data reviewing and monitoring tool.
 * Copyright (C) 2017-2018  StateMapper.net <statemapper@riseup.net>
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
 
	
if (!defined('BASE_PATH'))
	die();
	
	
function is_admin(){
	return IS_CLI || !empty($_SESSION['smap_authed']);
}

function get_visitor_ip(){
	if (IS_CLI)
		return 'localhost';
		
    $client  = @$_SERVER['HTTP_CLIENT_IP'];
    $forward = @$_SERVER['HTTP_X_FORWARDED_FOR'];

    if (filter_var($client, FILTER_VALIDATE_IP))
        return $client;
    if (filter_var($forward, FILTER_VALIDATE_IP))
        return $forward;
	return @$_SERVER['REMOTE_ADDR'];
}


