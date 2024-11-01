<?php
/**
 * @package WP_Copy
 * @version 1.2
 */
/*
Plugin Name: WP-Copy (Free)
Plugin URI: http://wpdev.me/
Description: WP-Copy allows you to easily copy your WordPress installation from one host to another
Version: 1.2
Author: WPDev Team
Author URI: http://wpdev.me/team
License: GPLv2 or later
*/

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

define('WPCOPY_DIST_VERSION', 'Free');

if( !defined('WPCOPY_PLUGIN_SLUG') ){

    include_once 'functions.php';

    register_activation_hook( __FILE__, 'wpcopy_plugin_activate' );
    register_deactivation_hook( __FILE__, 'wpcopy_plugin_deactivate' );

}