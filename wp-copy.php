<?php
/**
 * WP-Copy Free - automates your WordPress development ;)
 * @author adrian@wpdev.me
 * @version 0.7.2
 * @release-date 2014-02-15
 * @support-email support@wpdev.me
 */

//---- Configuration Directives ---//

define('WPD_INSTALL_TIME', '0');
define('WPD_VERSION', '0.7.2');

define('WPD_AUTH_KEY', 'auth_key_here');  // Secure Auth Key (use a random string)
define('WPD_DATETIME_FORMAT', 'Y-m-d H:i:s');   //  Desired date and time format

define('WPD_CACHEDIR_SEED', 'cahchedir_seed_here');  //Seed used for generating cache folder names
define('WPD_ENCRYPT_SEED', 'encrypt_seed_here');   //Seed for saving encrypted files

define('WPD_ALLOW_IGNORE_USER_ABORT', false);   //allows the script to run, even after the client connection stops (experimental)
define('WPD_LOCAL_BACKUP', false);                       //saves the zip package before uploading, into the cache folder
define('WPD_DEBUG_MODE', 0);
//---- Configuration Directives ---//

define('WPD_MAX_MEMORY_LIMIT', '64MB');
define('WPD_ASCII_FILE_EXTS', 'php,html,css');

define('WPD_SQL_CHUNK_MARK_END', '#wpd-sqlend');
define('WPD_CDN_BASEURL', '');

error_reporting(E_ALL ^ E_NOTICE);


class wpDeployCMD{

    public static $finish_wp_install   = 'finish_install_wp';

    public static $wp_deploy            = 'wp_deploy';
    public static $finish_wp_deploy  = 'finish_deploy_wp';

    public static $wp_flush_htaccess = 'wp_flush_htaccess';

    public static $clean_script_files = 'clean_script_files';

}


class wpDeploy{

    private $version = '1.4';

    private $auhorized = false;

    private $session                 = null;
    private $session_lock_time = 600;

    /**
     * An array of commands progresses
     * @var array
     */
    private $cmd_progress;

    /**
     * An array of command states (in-progress|finished)
     * @var array
     */
    private $cmd_state;

    /**
     * An array of commands messages
     * @var array
     */
    private $cmd_msgstack;

    /**
     * An array of commands last read status
     * @var array
     */
    private $cmd_last_read_status = array();

    public $cmd_status_note = 'note';
    public $cmd_status_warn = 'warning';
    public $cmd_status_error = 'error';

    private $cmd_state_busy = 'in-progress';
    private $cmd_state_free = 'finished';

    private $inline_scripts = array();

    private $localURL     = 'unknown';
    private $remoteURL = 'unknown';

    private $debug_file = 'debug.txt';

    public $path;
    public $abspath;

    public $dbconn      = null;
    public $ftpconn     = null;
    public $sftpconn    = null;
    public $curlconn    = null;

    private $cachedir = '.deployment';

    private $backups_cache_file = '.backups';
    private $servers_cache_file  = '.servers';

    private $temporaryFiles = null;
    private $asciiFilesList    = array();

    private $db_export_file = 'database.sql';

    private $ftp_root_path = '/';

    /**
     * The WordPress Download url (always use the .zip)
     * @var string
     */
    private $wp_download_url = '/wordpress-3.6.zip';

    public $is_wp_installed         = false;
    public $is_wp_environement = false;

    public $wp_version = 0;

    public $baseURL = null;

    public function __construct(){

        $this->baseURL  = basename(__FILE__);
        $this->path        = __FILE__;
        $this->abspath   = dirname(__FILE__);

        $this->cachedir = $this->get_path( $this->cachedir . WPD_CACHEDIR_SEED );

        $this->db_export_file = $this->get_tmp_file('db-backup');

        $this->is_wp_environement = $this->is_wp_environement();
        $this->is_wp_installed         = $this->is_wp_installed();
        $this->wp_version              = $this->get_wp_version();

        $this->asciiFilesList = @explode(',', WPD_ASCII_FILE_EXTS);

        $this->curlconn = curl_init();

        $this->temporaryFiles = array();

        @session_start();

        $this->session = session_id();

        $this->init();

    }

    /**
     * Init WP Deploy
     */
    private function init(){

        $now = time();

        //--- init the cache files ---//

        if( !is_dir( $this->cachedir ) ){

            $this->check_environment_sanity( true );

            $installTime = intval( WPD_INSTALL_TIME );
            $installFile   = basename( __FILE__ );

            //--- not yet installed ---//

            if( $installTime > 0 ){
                if( @mkdir($this->cachedir, 0755) ); else ui_fatal_error('Could not create the cache folder. Please check the folder permissions and try again.');
            }
            else{
                if( $this->reinstall( $installFile ) ) {
                    header('Location: ' . $installFile ); exit;
                }
                else
                    ui_fatal_error('Could not run the script installer. Please check the folder permissions and try again.');
            }

            //--- check htaccess file ---//
            if( !file_exists( $this->cachedir . DIRECTORY_SEPARATOR . '.htaccess' ) ) $this->htaccess_deny( $this->cachedir );
            //--- check htaccess file ---//

        }else{

            $lsession = $this->get_cache('.session');
            $ltime    = intval( $this->get_cache('.timestamp') ) + $this->session_lock_time;


            //--- somebody is already deploying ---//
            if( ( $lsession != $this->session ) and ($ltime > $now) ){
                $wait = ( floor( $this->session_lock_time / 60 ) +1 );
                ui_fatal_error('You have to be authenticated to access this page.', 'warning', 'Oups:', 'Warning');
            }
            //--- somebody is already deploying ---//

        }

        //--- check if we can write the session file ---//
        if( !$this->save_cache('.session', $this->session) )
            ui_fatal_error('The session file cannot be written. Please check the folder permissions and try again.', 'error', 'Error:', 'Warning');
        //--- check if we can write the session file ---//

        $this->save_cache('.timestamp', $now);

        //--- init the cache files ---//

        //--- check memory limit ---//

        $mem_limit         = intval( @ini_get('memory_limit') );
        $max_mem_limit = intval(WPD_MAX_MEMORY_LIMIT);

        if( $mem_limit < $max_mem_limit ) @ini_set('memory_limit', WPD_MAX_MEMORY_LIMIT);

        //--- check memory limit ---//

        //--- check environment fs access ---//
        if( ( WPD_INSTALL_TIME == 0 ) or ( ( time() - WPD_INSTALL_TIME ) < 70 ) ) $this->check_environment_sanity();
        //--- check environment fs access ---//

        $this->debug_file = $this->get_path( $this->debug_file );

        $this->cmd_msgstack = isset($_SESSION['wpd_cmd_msgstack']) ? $_SESSION['wpd_cmd_msgstack'] : array();
        $this->cmd_progress  = isset($_SESSION['wpd_cmd_progress']) ? $_SESSION['wpd_cmd_progress'] : array();
        $this->cmd_state       = isset($_SESSION['wpd_cmd_states']) ? $_SESSION['wpd_cmd_states'] : array();

        $this->cmd_last_read_status  = isset($_SESSION['wpd_cmd_readsindex']) ? $_SESSION['wpd_cmd_readsindex'] : array();

    }

    public function get_cache_dir(){
        return $this->cachedir;
    }

    public function check_authorization(){
        //--- check if user is authorized ---//

        if($this->is_wp_environement() and is_user_logged_in() and current_user_can('manage_options') ); else{
            //--- redirect to the login page ---//
            $login_url = wp_login_url($this->get_path_url(__FILE__)); header('Location: ' . $login_url); exit;
            //--- redirect to the login page ---//
        }

        //--- check if user is authorized ---//
    }

    /**
     * Checks if the current environment has all of the prerequisites for running the script
     */
    private function check_environment_sanity( $php_only=false ){

        //--- check php version ---//
        if( version_compare(PHP_VERSION, '5.3', '<') )
            ui_fatal_error('WP-Copy Free requires at least PHP 5.3. Please update your <a href="http://www.php.net/" target="_blank">PHP</a> version and access this page again or you can get the <a href="http://wpdev.me/downloads/wp-copy/">PHP 5.2 compatible version</a>.', 'error', 'Error:', 'Warning');

        //--- check for missing extensions ---//
        $required     = array('zip', 'curl', 'session');
        $missing       = array();

        foreach($required as $name) if( !extension_loaded($name) ) $missing[] = ucwords($name);

        if( count($missing) > 0 ){

            $mscount = count($missing);

            $missing = ($mscount > 1) ? implode(", ", $missing) : implode(" ", $missing);
            $missing = ($mscount > 1) ? substr_replace($missing, ' and ', strrpos($missing, ','), 1) : $missing;

            $missing = ($mscount > 1) ? ( ' following extensions: ' . $missing . '; are') : ( $missing . ' extension is ');

            ui_fatal_error('The ' . $missing . ' missing or disabled from your PHP environment. Please install the required extensions and then visit this page again.', 'error', 'Error:', 'Warning');

        }
        //--- check for missing extensions ---//

        if( $php_only ) return true;

        if( !is_writable( basename( __FILE__ ) ) )
            ui_fatal_error('The script could not be installed properly. Please make the file ' . basename( __FILE__ ) . ' writable for the php process.', 'error', 'Error:', 'Warning');

        if( !is_dir($this->cachedir) )
            ui_fatal_error('The cache directory could not be created. Please check the folder permissions and try again.', 'error', 'Error:', 'Warning');

        if( !$this->get_cache('.session') )
            ui_fatal_error('The session file cannot be read. Please check the folder permissions and try again(3).', 'error', 'Error:', 'Warning');

    }

    public function debug($var, $message=''){

        if( !defined('WPD_DEBUG_MODE') or !WPD_DEBUG_MODE ) return false;

        $now = date('Y-m-d H:i:s');

        if( is_object($var) or is_array($var) ) $var = @print_r($var, true);

        if( empty($message) ) $message = 'Variable debug output:';

        $line = "------------------------------------------------------------\n";
        $line.= ($now . ' ' . $message . "\n");
        $line.= $var;
        $line.= "\n------------------------------------------------------------\n\n";

        @file_put_contents( $this->debug_file, $line, FILE_APPEND );

    }

    public function check_requirements(){
        //TODO
    }

    public function is_https(){
        if( isset($_SERVER['HTTPS']) and $_SERVER['HTTPS'] and ( $_SERVER['HTTPS'] != 'off' ) ) return true; return false;
    }

    public function get_path($file){

        //---- sanitize ---//
        $file = str_replace('/', DIRECTORY_SEPARATOR, $file);
        $file = str_replace('//', DIRECTORY_SEPARATOR, $file);
        $file = str_replace('\\', DIRECTORY_SEPARATOR, $file);

        //---- sanitize ---//

        if( strpos($file, $this->abspath) === false ){
            $file = trim($file, DIRECTORY_SEPARATOR); return ($this->abspath . DIRECTORY_SEPARATOR . $file);
        }

        return $file;

    }

    public function get_tmp_file($prefix){
        $tempfile = tempnam( sys_get_temp_dir(), $prefix ); $this->temporaryFiles[] = $tempfile;  return $tempfile;
    }

    public function get_rel_path($path){
        $path = str_replace($this->get_path('/'), '', $this->get_path($path)); return ( empty($path) or ( $path == '/' ) ) ? '.' : $path;
    }

    public function get_path_url($path='/'){
        $path = $this->get_path($path); $path = str_replace($this->get_path('/'), $this->get_base_url(), $path); return $path;
    }

    public function get_base_url(){

        $url = $_SERVER['SERVER_NAME'];
        $url = $this->is_https() ? ( 'https://' . $url ) : ( 'http://' . $url );

        if( ( $_SERVER['SERVER_PORT'] != '80' ) and ( $_SERVER['SERVER_PORT'] != '443' ) ) $url.= (':' . $_SERVER['SERVER_PORT']); //non-standard http(s) ports

        $docroot   = rtrim($_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR);

        $url  = str_replace($docroot, $url, $this->get_path('/'));

        return $url;
    }

    public function htaccess_deny($dir_path){
        if( is_dir($dir_path) ) return @file_put_contents( ($dir_path . DIRECTORY_SEPARATOR . '.htaccess'), 'deny from all');
    }

    public function remote_deploy_index_file(){

        $fpath = $this->get_tmp_file('wpd-rindexfile');

        $contents = '
            <!--- Generated by WP Deploy v' . WPD_VERSION . ' --->
            <h2 align="center">A deployment is currently taking place... .</h2>
            <p style="text-align: center;">
                If you are seeing this, most likely the deployment has failed. <br/><br/>
                <a href="http://wpdev.me/forums">Click here</a> for further support .
            </p>
            <!--- ' . date('Y-m-d H:i:s') . ' --->
        ';

        @file_put_contents($fpath, $contents);

        return $fpath;

    }

    public function get_cache($file){
        $path = $this->get_path($this->cachedir . '/' . $file); return @file_exists($path) ? @file_get_contents($path) : null;
    }

    public function clear_cache($file){
        $path = $this->get_path($this->cachedir . '/' . $file); @unlink($path);
    }

    public function clear_session(){

        $this->clear_cache('.session');
        $this->clear_cache('.timestamp');

        $params = @session_get_cookie_params();

        @setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);

        @session_destroy();

    }

    public function save_cache($file, $data){
        $path = $this->get_path($this->cachedir . '/' . $file);  return @file_put_contents($path, $data);
    }

    public function is_cached($file){
        $path = $this->get_path($this->cachedir . '/' . $file); return @file_exists($path);
    }

    public function clean_temporary_files(){
        if( is_array($this->temporaryFiles) and count($this->temporaryFiles) ) foreach($this->temporaryFiles as $path) @unlink($path);
    }

    public function random_alphanum_string($length=24){
        $str = md5( time() + rand(-9999, 9999) ); while( strlen($str) < $length ) $str.= $this->random_string($length - strlen($str)); return substr($str, 0, $length);
    }

    public function random_string($length=32, $ignore_chr=''){
        $str = ""; $i = 0; if( is_array($ignore_chr) ) $ignore_chr = @implode('', $ignore_chr); while ( $i < $length ){
            $chr = chr( mt_rand(32, 126) ); if( strpos($ignore_chr, $chr) === false ); else continue; $chr = rand(0, 1) > 0 ? strtoupper($chr) : $chr; $str.=$chr; $i++;
        };

        return $str;
    }

    function replace_php_define_value($contents, $define, $replacement=''){

        //--- sanitize replacement of $x ---//
        $replacement = preg_replace('/\\$\\d+/', '\\\\$0', $replacement);
        //--- sanitize replacement of $x ---//

        if( strpos($replacement, 'define(') === false )
            $replacement = "define('{$define}', '{$replacement}')";

        return preg_replace("/define\('{$define}',(\s+|\r+|\t+?)'(.*)'\)/", $replacement, $contents, 1);

    }

    public function get_string_byte_length($string, $threshold=15){
        if( function_exists('mb_detect_encoding') ){
            $encoding = mb_detect_encoding($string, array('ASCII', 'UTF-8')); $threshold =0;
        }
        else $encoding = 'ASCII';

        return $encoding == 'UTF-8' ? ( mb_strlen($string) + $threshold ) : ( strlen($string) + $threshold );
    }

    public function http_download($url, $filename){

        $filename = $this->get_path( $filename ); $fhandle = @fopen($filename, 'w');

        curl_setopt($this->curlconn, CURLOPT_URL, $url);

        @curl_setopt($this->curlconn, CURLOPT_RETURNTRANSFER, true);
        @curl_setopt ($this->curlconn, CURLOPT_SSL_VERIFYPEER, false);
        @curl_setopt($this->curlconn, CURLOPT_FILE, $fhandle);

        @curl_exec($this->curlconn);

        $curl_errno = curl_errno($this->curlconn);
        $curl_error = curl_error($this->curlconn);

        if( $curl_errno or $curl_error ) {
            $this->debug($curl_errno. ': ' . $curl_error, 'cURL Error:'); return false;
        }

        return $filename;

    }


    public function replace_serialized_urls($file, $searchURL, $replaceURL){

        return true;

    }

    /**
     * Re-Installs the script under the desired filename
     * @param $filename
     * @return bool|int
     */
    public function reinstall($filename=false){

        $filename = empty($filename) ? __FILE__ : $this->get_path($filename);
        $content   = @file_get_contents(__FILE__);

        //--- generate and replace defines ---//
        $content = $this->replace_php_define_value($content, 'WPD_AUTH_KEY', substr( md5( $this->random_string()), 0, 12 ) );
        $content = $this->replace_php_define_value($content, 'WPD_CACHEDIR_SEED', substr( md5( $this->random_string()), 0, 12 ) );
        $content = $this->replace_php_define_value($content, 'WPD_ENCRYPT_SEED', substr( md5( $this->random_string()), 0, 12 ) );

        $content = $this->replace_php_define_value($content, 'WPD_INSTALL_TIME', time() );
        //--- generate and replace defines ---//

        return @file_put_contents($filename, $content);


    }

    /**
     * Checks if safe mode is active
     * @param string $ini_get_callback
     * @return bool
     */
    public static function is_safe_mode_active( $ini_get_callback = 'ini_get' ) {
        if ( ( $safe_mode = @call_user_func( $ini_get_callback, 'safe_mode' ) ) && strtolower( $safe_mode ) != 'off' ) return true; return false;
    }

    /**
     * Check whether shell_exec has been disabled.
     * @return bool
     */
    public function is_shell_exec_available() {

        // Are we in Safe Mode
        if ( self::is_safe_mode_active() ) return false;

        // Is shell_exec or escapeshellcmd or escapeshellarg disabled?
        if ( array_intersect( array( 'shell_exec', 'escapeshellarg', 'escapeshellcmd' ), array_map( 'trim', explode( ',', @ini_get( 'disable_functions' ) ) ) ) )
            return false;

        // Can we issue a simple echo command?
        if ( ! @shell_exec( 'echo wpdeploy' ) ) return false;

        return true;

    }

    /**
     * Attempts to find zip command path
     * @return string
     */
    public function get_zip_command_path() {

        // Check shell_exec is available
        if ( ! self::is_shell_exec_available() ) return false;

        // Does zip work
        if ( is_null( shell_exec( 'hash zip 2>&1' ) ) ) {
            return 'zip';
        }

        // List of possible zip locations
        $zip_locations = array(
            '/usr/bin/zip'
        );

        // Find the one which works
        foreach ( $zip_locations as $location ) if ( @is_executable( $location ) ) return $location;

        return false;

    }

    public function sftp_do_connect($host, $username, $password, $port=21){

        return true;

    }

    public function ftp_do_connect($host, $username, $password, $port=21, $passive=false, $ssl=false){

        $this->ftpconn = $ssl ? @ftp_ssl_connect($host, $port) : @ftp_connect($host, $port);

        if( $this->ftpconn ){

            if( $this->ftpconn and @ftp_login( $this->ftpconn, $username, $password ) ){

                if( $passive ) @ftp_pasv ($this->ftpconn, true);

                return true;

            }

        }

        return false;
    }

    public function ftp_do_upload($local_path, $remote_path){

        $hasdirs = strpos(trim($remote_path, '/'), '/') === false ? false : true; $dirs = false; if( $hasdirs ) $dirs = substr($remote_path, 0, strrpos($remote_path, '/'));

        if( $dirs ){
            $this->ftp_mksubdirs($this->ftpconn, $this->ftp_root_path, $dirs); @ftp_chdir($this->ftpconn, $this->ftp_root_path);
        }

        return ftp_put($this->ftpconn, $remote_path, $local_path, FTP_BINARY); //TODO determine is ascii or binary

    }

    public function ftp_do_disconnect(){
        return @ftp_close($this->ftpconn);
    }

    /**
     * Creates remote directories on
     * @param $ftpcon
     * @param $ftpbasedir
     * @param $ftpath
     */
    public function ftp_mksubdirs($ftpcon, $ftpbasedir, $ftpath){

        @ftp_chdir($ftpcon, $ftpbasedir); $parts = explode('/',trim($ftpath, '/')); // 2013/06/11/username

        foreach($parts as $part){
            if(!@ftp_chdir($ftpcon, $part)){
                ftp_mkdir($ftpcon, $part);
                ftp_chdir($ftpcon, $part);
                //ftp_chmod($ftpcon, 0777, $part);
            }
        }
    }

    public function generate_wp_secret_keys(){

        $keys = array(); $avoid_chars = array("'", '\\');

        $keys['auth_key']            = $this->random_string(64, $avoid_chars);
        $keys['secure_auth_key'] = $this->random_string(64, $avoid_chars);
        $keys['logged_in_key']    = $this->random_string(64, $avoid_chars);
        $keys['nonce_key']         = $this->random_string(64, $avoid_chars);

        $keys['auth_salt']              = $this->random_string(64, $avoid_chars);
        $keys['secure_auth_salt']   = $this->random_string(64, $avoid_chars);
        $keys['logged_in_salt']      = $this->random_string(64, $avoid_chars);
        $keys['nonce_salt']           = $this->random_string(64, $avoid_chars);

        return $keys;

    }

    public function generate_wp_config($settings, $sample_path='wp-config-sample.php'){

        $wp_config_sample   = $this->get_path($sample_path);

        if( count($settings) and @file_exists($wp_config_sample) ){

            $keys      = $this->generate_wp_secret_keys();
            $settings = array_merge($settings, $keys);

            $contents = @file_get_contents($wp_config_sample); //read the file

            //--- replace with values from settings ---//
            foreach($settings as $define=>$value){
                $define = strtoupper($define); $contents = $this->replace_php_define_value($contents, $define, $value);
            }
            //--- replace with values from settings ---//

            return @file_put_contents($this->get_path( 'wp-config.php' ), $contents);
        }

        return false;
    }

    public function file_search_and_replace($file, $search, $replace){

        $tmppath = $this->get_tmp_file('tempfile-');
        $tmpfile   = @fopen( $tmppath, 'w' );

        if( $ofreader = @fopen($file, 'r+') ) while( !feof($ofreader) ) {
            $line = fgets($ofreader); $line = str_replace($search, $replace, $line); fputs($tmpfile, $line);
        }

        @fclose($tmpfile);
        @fclose($ofreader);

        return @copy($tmppath, $file);
    }

    public function remote_connect($host, $username, $password, $port=null, $type='ftp'){

        $supportedTypes = array('local', 'ftp', 'ftp-passive', 'ftpes', 'ftpes-passive');
        $defaultPorts      = array(0, 21, 21, 21, 21);

        if( !in_array($type, $supportedTypes) ) return false;

        switch($type){

            case $supportedTypes[0]: //local
                return true;
            break;

            case $supportedTypes[1]: //ftp
                if( empty($port) ) $port = $defaultPorts[1]; return $this->ftp_do_connect($host, $username, $password, $port);
            break;

            case $supportedTypes[2]: //ftp-passive
                if( empty($port) ) $port = $defaultPorts[2]; return $this->ftp_do_connect($host, $username, $password, $port, true);
            break;

            case $supportedTypes[3]: //ftpes
                if( empty($port) ) $port = $defaultPorts[3]; return $this->ftp_do_connect($host, $username, $password, $port, false, true);
             break;

            case $supportedTypes[4]: //ftpes-passive
                if( empty($port) ) $port = $defaultPorts[4]; return $this->ftp_do_connect($host, $username, $password, $port, true, true);
            break;

            default: break;
        }

        return false; //no suitable connection type found

    }

    public function remote_disconnect(){

        if( $this->ftpconn )
            $this->ftp_do_disconnect();

        if( $this->dbconn )
            $this->disconnect_db();

    }

    public function remote_upload($local_path, $remote_path=false){

        if( empty($remote_path) ) $remote_path = $this->get_rel_path($local_path);

        if( $this->ftpconn )
            return $this->ftp_do_upload($local_path, $remote_path);

    }

    public function connect_db($host='localhost', $username='root', $password='', $database='test', $charset='utf8', $port=false){

        $this->dbconn = @mysqli_connect( $host, $username, $password, false, $port ); if ( ! $this->dbconn ) return false;

        @mysqli_set_charset($this->dbconn, $charset); return @mysqli_select_db($this->dbconn, $database);
    }

    public function disconnect_db(){
        return @mysql_close($this->dbconn);
    }

    public function get_mysql_max_allowed_packet($dbconn){

        $result = mysqli_query($dbconn, 'SELECT @@global.max_allowed_packet'); $maxp = 1024;

        if( mysqli_num_rows($result) ) {
            $row = mysqli_fetch_array($result, MYSQLI_NUM); $maxp = intval( $row[0] );
        }

        mysqli_free_result($result);

        return $maxp;
    }

    public function db_multi_sql($sql_string, $dbconn=false){

        if( empty($dbconn) ) $dbconn = $this->dbconn;

        if( strlen($sql_string) ){

            if( mysqli_multi_query($dbconn, $sql_string) ){

                $i = 0; do{ $i++; if ( !mysqli_more_results($dbconn) ) break; } while ( mysqli_next_result( $dbconn ) );

                return $i; //return the number of successful queries

            }
            else $error = @mysqli_error( $dbconn );

            if( @mysqli_errno( $dbconn ) )  $error = @mysqli_error($dbconn);

            return $error;

        }

        return FALSE;
    }

    /**
     * Imports a database from an sql file
     * @param $sql_file
     * @param $dbconn
     * @return bool|array
     */
    public function import_db( $sql_file, $dbconn=false){

        $dbconn = empty($dbconn) ? $this->dbconn : $dbconn;

        $chunk_sep          = WPD_SQL_CHUNK_MARK_END;
        $chunk_sep_len    = strlen($chunk_sep);
        $chunk                = "";
        $max_chunk_size = $this->get_mysql_max_allowed_packet($dbconn);
        $result                = 0;

        $max_chunk_size = $max_chunk_size - floor(( $max_chunk_size/10 ));

        if( $ofreader = @fopen($sql_file, 'r+') ) while( !feof($ofreader) ) {

            $line = fgets($ofreader); $chunk.=$line; $chunk_size = $this->get_string_byte_length($chunk);

            if( $chunk_size >= $max_chunk_size  ){

                //--- find query delimiters and import chunk into database ---//

                $ichunk = substr($chunk, 0, strrpos($chunk, $chunk_sep)+$chunk_sep_len);
                $left     = substr($chunk, strrpos($chunk, $chunk_sep)+$chunk_sep_len, $chunk_size);

                //--- import ckunk in db ---//
                $result = $this->db_multi_sql($ichunk, $dbconn); if( intval($result) < 1 ) return false;
                //--- import ckunk in db ---//

                $chunk = $left;

                //--- find query delimiters and import chunk into database ---//

            }
        }

        if( strlen($chunk) ) $result = $this->db_multi_sql($chunk, $dbconn);

        return intval($result) >= 1 ? true : false;

    }


    /**
     * Drops all tables with the given prefix from the database
     * @param string $prefix
     * @param mixed $dbconn
     * @return int
     */
    public function db_drop_tables($prefix='wp_', $dbconn=false){

        if( empty($dbconn) ) $dbconn = $this->dbconn; $droppedcnt = 0;

        if( empty($prefix) ) return false;

        if ( function_exists( 'mysql_set_charset') ) @mysqli_set_charset($dbconn, DB_CHARSET );

        $mresult= @mysqli_query($dbconn, 'SHOW TABLES', MYSQLI_USE_RESULT );
        $tables  = array();

        if( $mresult ) while($row = mysqli_fetch_array($mresult, MYSQLI_NUM) ) $tables[] = $row[0];

        if( count($tables) ) foreach($tables as $table) {

            if( strpos($table, $prefix) === false ) continue; elseif( strpos($table, $prefix) == 0 ){
                $dropped = @mysqli_query($dbconn, 'DROP TABLE `' . $table . '`', MYSQLI_USE_RESULT); if( $dropped ) $droppedcnt++;
            }

        }

        return $droppedcnt;

    }

    /**
     * Write the SQL file
     *
     * @access private
     * @param string $sql
     * @return bool
     */
    private function save_to_database_export_file( $sql ) {

        $sqlfilename = $this->db_export_file;

        if ( is_writable( $sqlfilename ) || ! file_exists( $sqlfilename ) ) {

            if ( ! $handle = @fopen( $sqlfilename, 'a' ) ) return false;

            if ( ! fwrite( $handle, $sql ) ) return false;

            @fclose( $handle );

            return true;

        }

        return false;

    }

    /**
     * Add backquotes to tables and db-names in SQL queries. Taken from phpMyAdmin.
     *
     * @access private
     * @param mixed $a_name
     * @return mixed
     */
    private function sql_backquote( $a_name ) {

        if ( ! empty( $a_name ) && $a_name !== '*' ) {

            if ( is_array( $a_name ) ) {

                $result = array();

                reset( $a_name );

                while ( list( $key, $val ) = each( $a_name ) )
                    $result[$key] = '`' . $val . '`';

                return $result;

            } else {

                return '`' . $a_name . '`';

            }

        } else {

            return $a_name;

        }

    }

    /**
     * Better addslashes for SQL queries.
     * Taken from phpMyAdmin.
     *
     * @access private
     * @param string $a_string. (default: '')
     * @param bool $is_like. (default: false)
     * @return string
     */
    private function sql_addslashes( $a_string = '', $is_like = false ) {

        if ( $is_like )
            $a_string = str_replace( '\\', '\\\\\\\\', $a_string );

        else
            $a_string = str_replace( '\\', '\\\\', $a_string );

        $a_string = str_replace( '\'', '\\\'', $a_string );

        return $a_string;
    }

    /**
     * Reads the Database table in $table and creates
     * SQL Statements for recreating structure and data
     * Taken partially from phpMyAdmin and partially from
     * Alain Wolf, Zurich - Switzerland
     * Website: http://restkultur.ch/personal/wolf/scripts/db_backup/
     *
     * @access private
     * @param string $sql_file
     * @param string $table
     */
    private function db_make_sql( $sql_file, $table ) {

        $chunk_mark = WPD_SQL_CHUNK_MARK_END; //chunk mark when an SQL statement ends

        // Add SQL statement to drop existing table
        $sql_file .= "\n";
        $sql_file .= "\n";
        $sql_file .= "#\n";
        $sql_file .= "# Delete any existing table " . $this->sql_backquote( $table ) . "\n";
        $sql_file .= "#\n";
        $sql_file .= "\n";
        $sql_file .= "DROP TABLE IF EXISTS " . $this->sql_backquote( $table ) . ";\n";

        /* Table Structure */

        // Comment in SQL-file
        $sql_file .= "\n";
        $sql_file .= "\n";
        $sql_file .= "#\n";
        $sql_file .= "# Table structure of table " . $this->sql_backquote( $table ) . "\n";
        $sql_file .= "#\n";
        $sql_file .= "\n";

        // Get table structure
        $query = 'SHOW CREATE TABLE ' . $this->sql_backquote( $table );
        $result = mysql_query( $query, $this->dbconn );

        if ( $result ) {

            if ( mysql_num_rows( $result ) > 0 ) {
                $sql_create_arr = mysql_fetch_array( $result );
                $sql_file .= $sql_create_arr[1];
            }

            mysql_free_result( $result );
            $sql_file .= ' ;';

        }

        /* Table Contents */

        // Get table contents
        $query = 'SELECT * FROM ' . $this->sql_backquote( $table );
        $result = mysql_query( $query, $this->dbconn );

        if ( $result ) {
            $fields_cnt = mysql_num_fields( $result );
            $rows_cnt   = mysql_num_rows( $result );
        }

        // Comment in SQL-file
        $sql_file .= "\n";
        $sql_file .= "\n";
        $sql_file .= "#\n";
        $sql_file .= "# Data contents of table " . $table . " (" . $rows_cnt . " records)\n";
        $sql_file .= "#\n";

        // Checks whether the field is an integer or not
        for ( $j = 0; $j < $fields_cnt; $j++ ) {

            $field_set[$j] = $this->sql_backquote( mysql_field_name( $result, $j ) );
            $type = mysql_field_type( $result, $j );

            if ( $type === 'tinyint' || $type === 'smallint' || $type === 'mediumint' || $type === 'int' || $type === 'bigint'  || $type === 'timestamp')
                $field_num[$j] = true;

            else
                $field_num[$j] = false;

        }

        // Sets the scheme
        $entries = 'INSERT INTO ' . $this->sql_backquote( $table ) . ' VALUES (';
        $search   = array( '\x00', '\x0a', '\x0d', '\x1a' );  //\x08\\x09, not required
        $replace  = array( '\0', '\n', '\r', '\Z' );
        $current_row = 0;
        $batch_write = 0;

        while ( $row = mysql_fetch_row( $result ) ) {

            $current_row++;

            // build the statement
            for ( $j = 0; $j < $fields_cnt; $j++ ) {

                if ( ! isset($row[$j] ) ) {
                    $values[]     = 'NULL';

                } elseif ( $row[$j] === '0' || $row[$j] !== '' ) {

                    // a number
                    if ( $field_num[$j] )
                        $values[] = $row[$j];

                    else
                        $values[] = "'" . str_replace( $search, $replace, $this->sql_addslashes( $row[$j] ) ) . "'";

                } else {
                    $values[] = "''";

                }

            }

            $sql_file .= " \n" . $entries . implode( ', ', $values ) . ") ;";

            //--- add the chunk mark end after the sql ---//
            if( $current_row%10 == 0 ) $sql_file.= ("\n" . $chunk_mark );
            //--- add the chunk mark end after the sql ---//

            // write the rows in batches of 100
            if ( $batch_write === 100 ) {
                $batch_write = 0;
                $this->save_to_database_export_file( $sql_file );
                $sql_file = '';
            }

            $batch_write++;

            unset( $values );

        }

        mysql_free_result( $result );

        // Create footer/closing comment in SQL-file
        $sql_file .= "\n";
        $sql_file .= "#\n";
        $sql_file .= "# End of data contents of table " . $table . "\n";
        $sql_file .= "# --------------------------------------------------------\n";
        $sql_file .= "\n";

        $this->save_to_database_export_file( $sql_file );

    }

    public function install_wp( $settings=array() ){

        return array('success'=>false);

    }

    public function deploy_wp($settings){

        global $table_prefix;

        $success = false;

        $deploy_db = $settings['deployment_type'] == 'new' ? true : false;

        if( isset($settings['ftp_passive']) ) $settings['connection_type'].= '-passive';

        $deploymentHost = $settings['host'];
        $deploymentURL = rtrim($settings['site_url'], '/');
        $deploymentPath = trim($settings['remote_folder'], '/');

        $zip_ignored = $zip_included = array();

        //--- update statuses ---//
        $this->update_cmd_progress(wpDeployCMD::$wp_deploy, 10);
        $this->update_cmd_status(wpDeployCMD::$wp_deploy, 'Started deployment to ' . $deploymentHost, $this->cmd_status_note);
        //--- update statuses ---//

        if( $deploy_db ) {

            //--- update statuses ---//
            $this->update_cmd_progress(wpDeployCMD::$wp_deploy, 10);
            $this->update_cmd_status(wpDeployCMD::$wp_deploy, 'Creating database backup... ', $this->cmd_status_note);
            //--- update statuses ---//

            $remoteURL = $deploymentURL;
            $localURL     = $this->get_wp_baseurl();

            $db_backup = $this->backup_db();

            if( $db_backup ){

                //--- update statuses ---//
                $this->update_cmd_progress(wpDeployCMD::$wp_deploy, 25);
                $this->update_cmd_status(wpDeployCMD::$wp_deploy, 'Database backup finished successfully... ', $this->cmd_status_note);
                //--- update statuses ---//

                //--- replace urls in the db export file ---//
                $this->replace_serialized_urls($this->db_export_file, $localURL, $remoteURL);
                $this->file_search_and_replace($this->db_export_file, $localURL, $remoteURL);

                $remote_db_path = $this->get_tmp_file('wpd-remote-db');

                @copy($this->db_export_file, $remote_db_path);
                //--- replace urls in the db export file ---//

                $zip_included = array_merge($zip_included, array('database.sql'=>$remote_db_path));

            }
            else $this->update_cmd_status(wpDeployCMD::$wp_deploy, 'Could not generate the database backup file', $this->cmd_status_error);

        }
        else $zip_ignored = array_merge($zip_ignored, array('wp-config.php'));

        //--- update statuses ---//
        $this->update_cmd_progress(wpDeployCMD::$wp_deploy, 30);
        $this->update_cmd_status(wpDeployCMD::$wp_deploy, 'Creating files backup archive... ', $this->cmd_status_note);
        //--- update statuses ---//

        $zip_ignored= strlen($settings['ignore_paths']) ? array_merge( $zip_ignored, @explode("\n", $settings['ignore_paths']) ) : $zip_ignored;

        $output      = $this->get_tmp_file('wpd-remote-zip');
        $archived  = $this->arc_zip('/', $output, $this->get_ignore_files($zip_ignored), $zip_included); //make a zip file of the current install

        if( $archived and file_exists($output) ){

            //--- update statuses ---//
            $this->update_cmd_progress(wpDeployCMD::$wp_deploy, 30);
            $this->update_cmd_status(wpDeployCMD::$wp_deploy, 'Files backup completed... ', $this->cmd_status_note);
            //--- update statuses ---//

            //--- upload wp-deploy on the remote server ---//

            $this->debug('Zip archive created, connecting to remote server....');

            //--- if local save is active copy the zipped file on in the cache folder ---//
            if( WPD_LOCAL_BACKUP ){
                $local_filename = ( $this->get_cache_dir() . DIRECTORY_SEPARATOR . 'backup-' . $this->random_alphanum_string(7) .'.zip' ); @copy($output, $local_filename);
            }
            //--- if local save is active copy the zipped file on in the cache folder ---//

            //--- connect to the remote server ---//

            $connected = $this->remote_connect($settings['host'], $settings['user'], $settings['password'], $settings['port'], $settings['connection_type']);

            if( $connected ){

                $this->debug('Connected to remote server..');

                //--- update statuses ---//
                $this->update_cmd_progress(wpDeployCMD::$wp_deploy, 30);
                $this->update_cmd_status(wpDeployCMD::$wp_deploy, 'Successfully connected to ' . $deploymentHost, $this->cmd_status_note);
                $this->update_cmd_status(wpDeployCMD::$wp_deploy, 'Uploading files on the remote server... ', $this->cmd_status_note);
                //--- update statuses ---//

                    $rindexFile  = $this->remote_deploy_index_file();

                    $rindexPath = ( $deploymentPath . '/index.php' );
                    $rscriptPath = ( $deploymentPath . '/' . basename(__FILE__)  );
                    $rscriptURL  = ( $deploymentURL . '/' . basename(__FILE__) );

                    $rpackagePath = ( $deploymentPath . '/package' . $this->random_alphanum_string(12) . '.zip' );
                    $rpackageURL  = ( $deploymentURL . '/' . basename($rpackagePath)  );

                    $indexFUploaded = $this->remote_upload($rindexFile, $rindexPath);
                    $scriptUploaded = $this->remote_upload( $this->get_rel_path(__FILE__),  $rscriptPath);
                    $archUploaded   = $this->remote_upload($output, $rpackagePath);

                    if($indexFUploaded and $scriptUploaded and $archUploaded ){

                        $this->update_cmd_progress(wpDeployCMD::$wp_deploy, 50);
                        $this->update_cmd_status(wpDeployCMD::$wp_deploy, 'Files uploaded, running remote install ' . $deploymentHost, $this->cmd_status_note);

                        $settings['package']   = basename($rpackagePath);
                        $settings['db_prefix'] = strlen($table_prefix) ? $table_prefix : 'wp_';
                        $settings['db_charset']= DB_CHARSET;

                        $rcmdResult = $this->execute_remote_command($rscriptURL, wpDeployCMD::$finish_wp_deploy, $settings);

                        //-- parse remote command result ---//
                        $rcmdResult = $this->parse_response( $rcmdResult );

                        if( $rcmdResult['success'] == false ){

                            if ( count( $rcmdResult['messages'] ) and is_array( $rcmdResult['messages'] ) )
                                foreach($rcmdResult['messages'] as $msg)
                                    $this->update_cmd_status(wpDeployCMD::$wp_deploy, ( '<em>' . $deploymentHost .  '</em>: ' . $msg ), $this->cmd_status_error);
                            else
                                $this->update_cmd_status(wpDeployCMD::$wp_deploy, ( 'Unknown script error on ' . $rscriptURL ), $this->cmd_status_error);

                        }
                        else {

                            //--- make a request to recreate htaccess file ---//
                            $this->execute_remote_command($rscriptURL, wpDeployCMD::$wp_flush_htaccess);
                            //--- make a request to recreate htaccess file ---//

                            //--- remove remote script ---//
                            $this->execute_remote_command($rscriptURL, wpDeployCMD::$clean_script_files);
                            //--- remove remote script ---//

                            $this->update_cmd_status(wpDeployCMD::$wp_deploy, ( 'Successfully copied on ' . $deploymentURL . '.' ), $this->cmd_status_note);
                            $this->update_cmd_status(wpDeployCMD::$wp_deploy, ( "Don't forget to login at " . $deploymentURL . "/wp-login.php and re-generate the htaccess file." ), $this->cmd_status_note);

                            $success = true;

                        }

                        //-- parse remote command result ---//

                    }
                    else{

                        $errmsg = ( 'Could not upload file ' . basename(__FILE__) . '.');
                        $errmsg.= strpos($settings['connection_type'], 'ftp') === false ? 'Please check the host connection.' : 'Activate the passive mode and try again.';

                        $this->update_cmd_status(wpDeployCMD::$wp_deploy, $errmsg, $this->cmd_status_error);
                    }

                }
                else{
                    $message = (  'Could not connect to ' . $deploymentHost . ' via ' . strtoupper( $settings['connection_type'] ) . ' on port ' . $settings['port'] .'. Please check the username and password provided and try again.' );
                    $this->update_cmd_status(wpDeployCMD::$wp_deploy, $message, $this->cmd_status_error);
                }

                //--- upload wp-deploy on the remote server ---//

        }
        else {

            //--- error could not zip the files ---//
            $message = (  'Could not create zip archive. Please check your folder permissions and try again' );
            $this->update_cmd_status(wpDeployCMD::$wp_deploy, $message, $this->cmd_status_error);
            //--- error could not zip the files ---//
        }

        $this->after_cmd_clean();

        return array('success'=>$success);

    }

    public function backup_db(){

        $this->dbconn = @mysql_pconnect( DB_HOST, DB_USER, DB_PASSWORD );

        if ( ! $this->dbconn )
            $this->dbconn = @mysql_connect( DB_HOST, DB_USER, DB_PASSWORD );

        if ( ! $this->dbconn ) return false;

        @mysql_select_db( DB_NAME, $this->dbconn );

        if ( function_exists( 'mysql_set_charset') ) @mysql_set_charset( DB_CHARSET, $this->dbconn );

        // Begin new backup of MySql
        $tables = @mysql_query( 'SHOW TABLES' );

        $sql_file  = "# WordPress : " . get_bloginfo( 'url' ) . " MySQL database backup\n";
        $sql_file .= "#\n";
        $sql_file .= "# Generated: " . date( 'l j. F Y H:i T' ) . "\n";
        $sql_file .= "# Hostname: " . DB_HOST . "\n";
        $sql_file .= "# Database: " . $this->sql_backquote( DB_NAME ) . "\n";
        $sql_file .= "# --------------------------------------------------------\n";

        $this->save_to_database_export_file($sql_file);

        for ( $i = 0; $i < mysql_num_rows( $tables ); $i++ ) {

            $curr_table = mysql_tablename( $tables, $i );

            // Create the SQL statements
            $sql_file  = "# --------------------------------------------------------\n";
            $sql_file .= "# Table: " . $this->sql_backquote( $curr_table ) . "\n";
            $sql_file .= "# --------------------------------------------------------\n";

            $this->db_make_sql( $sql_file, $curr_table );

        }

        mysql_close($this->dbconn);

        return true; //finished generating sql

    }

    public function get_ignore_files($list=false){

        $relcachedir= ( $this->get_rel_path( $this->cachedir ) );
        $ignored      = array('wp-deploy.php', './wp-deploy.php', ( $relcachedir . '/*'), ( './' . $relcachedir . '/*'));

        if( is_array($list) and count($list) ) foreach($list as $path) {

            $path = trim( trim($path), '/' );

            if( is_file($path) )
                $ignored[] =  $this->get_rel_path( $path );
            elseif( is_dir($path) ){
                $ignored[] =  (  './' . $this->get_rel_path( $path ) );
                //$ignored[] =  ( $this->get_rel_path( $path ) . '/*' );
                $ignored[] =  ( './' . $this->get_rel_path( $path ) . '/*' );
            }

        }

        return $ignored;

    }

    /**
     * Creates a zip archive of the path
     * @param string $path
     * @param bool $include_dbexport
     * @return mixed
     */
    public function backup_files($path='/', $include_dbexport=true){

        $destination = $this->get_path($this->cachedir . DIRECTORY_SEPARATOR .'backup-' . date('ymdHis') . '.zip');
        $ignored      = $this->get_ignore_files();

        if( $include_dbexport ) $supplementary   = array( 'database.sql' => $this->db_export_file );

        $zipped = $this->arc_zip($path, $destination, $ignored, $supplementary);

        return $zipped ? $destination : false;
    }

    public function backup_wp(){

        return array('success'=>false);

    }

    private function add_backup($filepath, $cachefile=false){
        return false;
    }

    public function remove_backup($filepath, $cachefile=false){

        return false;

    }

    public function get_backups($cachefile=false){
        return array();
    }

    public function is_wp_environement(){
        return  ( defined('ABSPATH') and function_exists('wp_insert_comment') ) ;
    }

    public function is_wp_installed(){
        if( $this->is_wp_environement or $this->is_wp_environement() ){
            $blogname = get_option('siteurl'); return strlen($blogname) > 0;
        }

        return false;
    }

    public function get_wp_version($default='unknown'){
        global $wp_version; if( $this->is_wp_environement ) return $wp_version !== null ? $wp_version : $default;
    }

    public function get_wp_baseurl(){
        return @get_bloginfo('siteurl');
    }

    public function is_user_logged_in(){
        if( $this->is_wp_installed ) return is_user_loggedin(); return true;
    }

    public function is_user_admin(){
        if( $this->is_wp_installed ) return current_user_can( 'manage_options' ); return true;
    }


    /**
     * Recursively removes a directory
     * @param $path
     * @return bool
     */
    public function remove_dir($path){

        if( !is_dir($path) ) return FALSE;

        $it     = new RecursiveDirectoryIterator($path);
        $files = new RecursiveIteratorIterator($it,  RecursiveIteratorIterator::CHILD_FIRST);

        foreach($files as $file) {

            if ( ( $file->getFilename() === '.' ) or ( $file->getFilename() === '..' ) )
                continue;

            if ($file->isDir())
                @rmdir($file->getRealPath());
            else
                @unlink($file->getRealPath());

        }

        return @rmdir($path);
    }


    /**
     * Recursively copies directory or file
     * @param $spath
     * @param $dstpath
     * @param bool $clean_dst
     * @param int $rlevel internal variable used to determine directory level
     */
    public function fcopy($spath, $dstpath, $clean_dst=FALSE, $rlevel=0) {

        $spath    = rtrim($spath, DIRECTORY_SEPARATOR);
        $dstpath = rtrim($dstpath, DIRECTORY_SEPARATOR);

        if( $clean_dst and file_exists ( $dstpath ) ){
            if( is_file($dstpath) ) @unlink($dstpath); else $this->remove_dir($dstpath);
        }

        if ( is_dir ( $spath ) ) {

            if( $clean_dst or $rlevel) @mkdir($dstpath);

            $files = scandir ( $spath );

            if( count($files) ) foreach ( $files as $file )
                if ($file != "." && $file != "..")
                    self::fcopy( $spath . DIRECTORY_SEPARATOR . $file, $dstpath . DIRECTORY_SEPARATOR . $file, FALSE, ++$rlevel );

        } else if ( file_exists ( $spath ) )
            @copy ( $spath, $dstpath );

    }

    /**
     * Extracts a zip archive
     * @param $path
     * @param bool $dir
     * @param array $entries
     * @return bool|string
     */
    public function arc_unzip($path, $dir=FALSE, $entries=array()){

        if( empty($dir) ) $dir = dirname($path);

        if( !class_exists('ZipArchive') ) return false;

        $zip = new ZipArchive;

        if ( $zip->open($path) === true ) {

            if( count($entries) )
                $zip->extractTo($dir, $entries);
            else
                $zip->extractTo($dir);

            $zip->close();

            return $dir;
        }

        return false;

    }

    public function arc_zip($path, $output='archive.zip', $ignore_paths=array(), $outside_files=array()){

        if ( !extension_loaded('zip') || !file_exists($path) ) return false;

        $zip = new ZipArchive();

        if (!$zip->open($output, ZIPARCHIVE::CREATE)) return false;

        $source = $this->get_rel_path($path);

        if( is_array($outside_files) and count($outside_files) ) foreach($outside_files as $name=>$pfile) $zip->addFile( $pfile, $name );

        if ( is_dir($source) === true ){

            $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

            foreach ($files as $file){

                $file = str_replace('\\', '/', $file); $file = $this->get_rel_path($file); $nozip = false;

                if( in_array(substr($file, strrpos($file, '/')+1), array('.', '..')) ) continue; // Ignore "." and ".." folders

                if( in_array($file, $ignore_paths) ) $nozip = true;

                foreach($ignore_paths as $ign_path) if( @fnmatch($ign_path, $file) ){  $nozip = true; break; }

                //--- ignore .git and .svn paths ---//
                if( @fnmatch('*.svn*', $file) ) $nozip = true;
                if( @fnmatch('*.git*', $file) ) $nozip = true;
                //--- ignore .git and .svn paths ---//

                if( $nozip ) continue;

                if (is_dir($file) === true){
                    $zip->addEmptyDir(str_replace($source . '/', '', $file . '/'));
                }
                else if (is_file($file) === true) {
                    $zip->addFile($file, str_replace($source . '/', '', $file));
                }
            }
        }
        else if (is_file($source) === true){
            $zip->addFile($source, basename($path));
        }

        return $zip->close();
    }

    public function arc_zip_byshell($path, $output='archive.zip', $ignore_paths=array(), $outside_files=array()){

        $zipcmd = $this->get_zip_command_path();
        $output  = $this->get_path($output);

        $stderr = shell_exec( 'cd ' . escapeshellarg( $this->get_path($path) ) . ' && ' . escapeshellcmd( $zipcmd ) . ' -rq ' . escapeshellarg( $output ) . ' ./' . ' -x ' . $this->exclude_string( 'zip' ) . ' 2>&1' );

    }

    public function execute_command($cmd, $data=array(), $ajax=false){

        $cmd_result = null;

        //--- clean previous command stacks ---//
        $this->clean_cmd_meta($cmd);
        //--- clean previous command stacks ---//

        $this->update_cmd_progress($cmd, 15);
        $this->update_cmd_status($cmd, "Executing command {$cmd}", $this->cmd_status_note);

        switch($cmd){
            case wpDeployCMD::$wp_deploy: $cmd_result = $this->deploy_wp($data); break;
        }

        return $cmd_result;
    }

    private function filter_remote_command_data($data){

        //--- remove sensitive data ---//
        unset($data['host']);
        unset($data['user']);
        unset($data['password']);
        unset($data['port']);
        unset($data['connection_type']);
        //--- remove sensitive data ---//

        return $data;

    }

    private function execute_remote_command($script_url, $cmd, $data=array()){

        $this->debug('Executing remote command ' . $cmd . ' on ' . $script_url);

        $script_url.= ( '?cmd=run&execute=yes&ak=' . md5(WPD_AUTH_KEY) ); $this->debug('remote command at ' . $script_url);

        $data = $this->filter_remote_command_data($data);

        $cfile = $this->get_path( $this->cachedir . DIRECTORY_SEPARATOR . '.cookies' );

        $postfields = array_merge( array('xcmd'=>$cmd), $data );
        $postfields = http_build_query($postfields);

        @curl_setopt($this->curlconn, CURLOPT_URL, $script_url);

        @curl_setopt($this->curlconn, CURLOPT_RETURNTRANSFER, true);
        @curl_setopt ($this->curlconn, CURLOPT_SSL_VERIFYPEER, false);

        curl_setopt($this->curlconn,CURLOPT_POST, true);
        curl_setopt($this->curlconn,CURLOPT_POSTFIELDS, $postfields);

        curl_setopt($this->curlconn, CURLOPT_COOKIESESSION, true);
        curl_setopt($this->curlconn, CURLOPT_COOKIEFILE, $cfile);
        curl_setopt($this->curlconn, CURLOPT_COOKIEJAR, $cfile);

        $result = @curl_exec($this->curlconn); $this->debug('remote command result=' . $result);

        $curl_errno = curl_errno($this->curlconn);
        $curl_error = curl_error($this->curlconn);

        if( $curl_errno or $curl_error ) {
            $this->debug($curl_errno. ': ' . $curl_error, 'cURL Error:'); return false;
        }

        return $result;

    }

    private function after_cmd_clean(){

        //--- close any opened connections ---//
        $this->remote_disconnect();
        //--- close any opened connections ---//

        //--- close any curl sessions ---//
        if( $this->curlconn ) @curl_close( $this->curlconn );
        //--- close any curl sessions ---//

        //--- delete temporary files ---//
        $this->clean_temporary_files();
        //--- delete temporary files ---//

    }

    private function clean_cmd_meta($cmd){

        $this->debug('Cleaning cmd ' . $cmd . ' meta');

        $this->cmd_progress[$cmd]  = array(); $_SESSION['wpd_cmd_progress'] = $this->cmd_progress;
        $this->cmd_msgstack[$cmd] = array(); $_SESSION['wpd_cmd_msgstack'] = $this->cmd_progress;

        unset( $this->cmd_state[$cmd] ); $_SESSION['wpd_cmd_states'] = $this->cmd_state;

        $this->cmd_last_read_status[$cmd] = 0; $_SESSION['wpd_cmd_readsindex'] = $this->cmd_last_read_status;
    }

    public function update_cmd_progress($cmd, $counter=1, $append=false){
        if( $counter > 100 )
            $counter = 100;

        else if($append) $counter = intval($this->cmd_progress[$cmd]) + $counter;

        $this->cmd_progress[$cmd] = intval( $counter );

        $_SESSION['wpd_cmd_progress'] = $this->cmd_progress;

    }

    public function update_cmd_status($cmd, $message, $type='note', $finished=false){

        $message = array('type'=>$type, 'text'=>strip_tags($message));

        if( is_array($this->cmd_msgstack[$cmd]) )
            $this->cmd_msgstack[$cmd][] = $message;
        else
            $this->cmd_msgstack[$cmd] = array($message);

        $this->cmd_state[$cmd] = $finished ? $this->cmd_state_free : $this->cmd_state_busy;

        $_SESSION['wpd_cmd_msgstack'] = $this->cmd_msgstack;
        $_SESSION['wpd_cmd_states']     = $this->cmd_state;
    }

    /**
     * Prepares a JSON-encoded string, ready to be dispatched to an AJAX listener
     * @param $messages
     * @param array $data
     * @param bool $success
     * @return string
     */
    public function ajax_prepare_response($messages, $data=array(), $success=false){

        $messages = is_array($messages) ? $messages : array($messages);

        $response = array('messages'=>$messages, 'data'=>$data, 'success'=>$success);

        return @json_encode($response);

    }

    public function parse_response($response){
        if( $response and strlen($response) and strpos($response, '}') > 0 ){
            $parsed = @json_decode($response, true); if( $parsed and isset($parsed['messages']) ) return $parsed;
        }

        return array('success'=>false);
    }

    public function ajax_get_cmd_status($cmd){

        $last_read_index = isset($this->cmd_last_read_status[$cmd]) ? intval($this->cmd_last_read_status[$cmd]) : -1;

        //$messages = $this->cmd_msgstack[$cmd][$last_read_index + 1]; //this will get only one message
        $messages = array_slice($this->cmd_msgstack[$cmd], $last_read_index ); //this will get a slice of messages
        //$messages = $this->cmd_msgstack[$cmd]; //this will get all of the messages
        $progress  = $this->cmd_progress[$cmd];
        $finished  = $this->cmd_state[$cmd];

        $last_read_index+= count($messages); $this->cmd_last_read_status[$cmd] = $last_read_index;

       $this->debug('Sending ' . count( $messages ) . ' messages to output at t=' . date('Y-m-d H:i:s'));

        $_SESSION['wpd_cmd_readsindex'] = $this->cmd_last_read_status;

        return $this->ajax_prepare_response($messages, array('progress'=>$progress, 'state'=>$finished));

    }

    public function add_inline_script($script, $id=false){
        $id = empty($id) ? ('script-' . count($this->inline_scripts)) : trim($id); $this->inline_scripts["{$id}"] = trim($script);
    }

    public function scripts(){
        if( count($this->inline_scripts) ) foreach($this->inline_scripts as $id=>$script): ?>
            <script id="<?php echo $id; ?>" type="text/javascript"><?php echo $script; ?></script>
        <?php endforeach;
    }

}

$AUTH_KEY = isset($_POST['AUTH_KEY']) ? trim($_POST['AUTH_KEY']) : trim($_GET['ak']);

//--- before running any command, check if the script is installed ---//
if( isset( $_GET['cmd'] ) and ( ( WPD_AUTH_KEY == 'auth_key_here' ) or ( intval( WPD_INSTALL_TIME ) == 0 ) ) ){
    ui_fatal_error('Ops! Something broke... .');
}
//--- before running any command, check if the script is installed ---//

//--- Special commands stack ---//
if( ( $_GET['cmd'] == 'run' ) and ( $_GET['execute'] == 'yes' ) ){

    //--- check auth key ---//
    if( $AUTH_KEY != md5(WPD_AUTH_KEY) ) ui_fatal_error('Invalid Authorization Key!');
    //--- check auth key ---//

    if( WPD_ALLOW_IGNORE_USER_ABORT ) ignore_user_abort(true); set_time_limit(0); //Maximize time limit for the script

    $cxcmd      = trim($_POST['xcmd']);
    $cmd         = $_GET['cmd'];
    $result       = array('success'=>false);
    $messages  = array();

    $WPDeploy = new wpDeploy();

    switch($cxcmd){

        case wpDeployCMD::$finish_wp_deploy:

            //--- finish deployment on this host ---//

            $package    = $WPDeploy->get_path( $_POST['package'] );
            $deployDB  = $_POST['deployment_type'] == 'new' ? true : false;

            if( file_exists( $package ) ){

                //--- unzip the package locally ---//

                $WPDeploy->update_cmd_status($cmd, 'Package found running remote installation', $WPDeploy->cmd_status_note);

                $unzipped = $WPDeploy->arc_unzip($package);

                if( $unzipped ){

                    $WPDeploy->update_cmd_status($cmd, 'Package unzipped, installing database...', $WPDeploy->cmd_status_note);

                    if( $deployDB ){

                        //--- generate a suitable wp-config.php ---//
                        $WPDeploy->generate_wp_config($_POST, $WPDeploy->get_path('wp-config.php'));
                        //--- generate a suitable wp-config.php ---//

                        //--- connect to the database ---//

                        $db_charset = isset($_POST['db_charset']) ? trim($_POST['db_charset']) : 'utf8';
                        $db_port      =isset($_POST['db_port']) ? intval($_POST['db_port']) : false;

                        $connected = $WPDeploy->connect_db($_POST['db_host'], $_POST['db_user'], $_POST['db_password'], $_POST['db_name'], $db_charset, $db_port);

                        if( $connected ) {

                            //--- clean all wp_ tables first ---//
                            $cleaned = $WPDeploy->db_drop_tables($_POST['db_prefix']);
                            //--- clean all wp_ tables first ---//

                            $db_imported = $WPDeploy->import_db( $WPDeploy->get_path('database.sql') );

                            if( $db_imported ){

                                //--- delete deployment files ---//
                                @unlink( $WPDeploy->get_path('database.sql') );
                                @unlink( $package );
                                //--- delete deployment files ---//

                                $result['success'] = true;

                            }else
                                $messages[] = ( 'Could not import the database on ' . $_POST['db_host'] . '. Last error was: ' . $db_imported);

                        }
                        else $messages[] = ( 'Could not connect to database on ' . $_POST['db_host'] . '. Connection error: ' . @mysqli_connect_error());

                        //--- connect to the database ---//

                    }
                    else{
                        @unlink( $package ); $result['success'] = true;
                    }

                    $WPDeploy->debug($_POST, 'remote variables received');//TODO

                }
                else $messages[] = ( 'Could not unpack the zip archive ' . $_POST['package'] );

                //--- unzip the package locally ---//
            }
            else $messages[] = ( 'Could not find the zip archive at ' . $package );

            //--- finish deployment on this host ---//

        break;


        case wpDeployCMD::$wp_flush_htaccess:

            $result['success'] = true;

        break;

        case wpDeployCMD::$clean_script_files:

            //--- clean all script-related files ---//
            $WPDeploy->remove_dir( $WPDeploy->get_cache_dir() ); @unlink( $WPDeploy->get_path(__FILE__) ); $result['success'] = true;
            //--- clean all script-related files ---//

        break;

        default: $WPDeploy->update_cmd_status($cmd, 'Invalid run command: ' . $cxcmd, $WPDeploy->cmd_status_error, true);
    }

    echo empty($messages) ? $WPDeploy->ajax_prepare_response($cmd . ' command finished.', $result, $result['success']) : $WPDeploy->ajax_prepare_response($messages, $result, $result['success']);

    exit;

}
//--- Special commands stack ---//


//--- load WP environment if WordPress is present ---//
if( file_exists('wp-load.php') )
    include_once 'wp-load.php';
else{
    if( file_exists('wp-copy-js.js') ) //is in plugin folder
        ui_fatal_error('In order to use the script this file should be moved to your WordPress root folder.');
}
//--- load WP environment if WordPress is present ---//

$WPDeploy = new wpDeploy();
$Section     = isset($_GET['tab']) ? $_GET['tab'] : 'home';

//--- ajax calls ---//
if( isset($_GET['ajax']) ) {

    //--- check auth key ---//
    if( $AUTH_KEY != md5(WPD_AUTH_KEY) ) ui_fatal_error('Invalid Authorization Key!');
    //--- check auth key ---//

    if( isset($_GET['status']) ){
        //-- get command status ---//
        echo $WPDeploy->ajax_get_cmd_status($_GET['cmd']); exit;
        //-- get command status ---//
    }

    else if( isset($_GET['cmd']) and isset($_GET['execute']) and ( $_GET['execute'] == 'yes' ) ){
        //--- execute a command ---//
        //@ignore_user_abort(true); //If running in API Mode

        set_time_limit(0); //Maximize time limit for the script

        $result = $WPDeploy->execute_command($_GET['cmd'], $_POST, true);

        echo $WPDeploy->ajax_prepare_response($_GET['cmd'] . ' command finished.', $result, $result['success']);

        exit;
        //--- execute a command ---//
    }
}
//--- ajax calls ---//

if( $WPDeploy->is_wp_environement() ) $WPDeploy->check_authorization();

function ui_has_cmd(){
    return isset($_GET['cmd']);
}

function ui_is_section($section){
    global $Section; return $Section == $section;
}

function ui_url($tab=null, $vars=array(), $echo=true){
    echo ui_get_url($tab, $vars);
}

function ui_full_url($path){
    global $WPDeploy; return $WPDeploy->get_path_url($path);
}

function ui_cmd_url($cmd){
    echo ui_get_url('', array('cmd'=>urlencode($cmd)));
}

function ui_get_url($tab=null, $vars=array()){

    global $WPDeploy; $url = $WPDeploy->baseURL;

    if( $tab ) $url.= (  '?tab=' . urlencode($tab) );

    if( $vars ){
        $url.= $tab ? '&' : '?'; $url.= http_build_query($vars);
    }

    return $url;
}

function ui_asset_url($relative_path){
    if( function_exists('plugins_url') and function_exists('wpcopy_init') )
        return plugins_url( '/wp-copy-free' . $relative_path);
    else
        return ( WPD_CDN_BASEURL . $relative_path );
}

function ui_fatal_error($msg, $msgtype="error", $prefix="Error: ", $title="Error", $html=TRUE, $die=TRUE){

    if ( $html ): ?>
        <html>
        <head>
            <meta charset="utf-8" />
            <title>WP-Copy | <?php echo $title; ?> </title>
            <link href="//netdna.bootstrapcdn.com/twitter-bootstrap/2.3.2/css/bootstrap-combined.min.css" rel="stylesheet"/>
            <link href="//netdna.bootstrapcdn.com/font-awesome/3.2.1/css/font-awesome.min.css" rel="stylesheet"/>
            <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:400,700,700italic" rel="stylesheet" type="text/css"/>
            <link href="<?php echo ui_asset_url('/wp-copy-styles.css'); ?>" rel="stylesheet"/>
        </head>
        <body class="error">
            <div class="container main-container">

                <div class="heading">
                    <h2 class="branding align-center"> wp-copy<sup style="font-weight: normal;">free</sup> </h2>
                </div>

                <div class="row">
                    <div class="span12">

                        <p class="msg <?php echo $msgtype; ?> bigger"><strong><?php echo $prefix; ?> </strong><?php echo $msg; ?></p>

                        <p>&nbsp;<br/></p>

                        <p class="help-links">
                            <a href="http://wpdev.me/forums/">Support forums</a> | <a href="http://wordpress.org/plugins/wp-copy-free/">Documentation</a> | <a href="http://wpdev.me/">WPDev.me</a>
                        </p>

                    </div>
                </div>

            </div>
        </body>
        </html>
    <?php
    else:
        echo "{$prefix} {$msg} \n";
    endif;

    if ( $die ) die();

}

function ui_nav_menu(){

    global $WPDeploy, $Section; $activeTab = array($Section=>'class="active"'); ?>

    <li <?php echo $activeTab['home']; ?>><a href="<?php ui_url(); ?>"><span class="icon-compass"></span> Start</a> </li>

    <?php if( $WPDeploy->is_wp_environement ): ?>
        <li <?php echo $activeTab['copy']; ?>><a href="<?php ui_url('copy'); ?>"><span class="icon-cog"></span> Copy</a> </li>
    <?php endif; ?>

    <li <?php echo $activeTab['faq']; ?>><a href="<?php ui_url('faq'); ?>"><span class="icon-question"></span> F.A.Q</a> </li>

    <li <?php echo $activeTab['docs']; ?>><a href="<?php ui_url('docs'); ?>"><span class="icon-book"></span> Documentation</a> </li>

    <li><a href="http://wpdev.me/" target="_blank"><span class="icon-group"></span> Forum &amp; more</a> </li>


    <?php
}

function ui_get_cmd_heading($command){

    global $WPDeploy;

    switch($command){
        case wpDeployCMD::$wp_deploy:  return 'Copying to '.$_POST['site_url'].'...';


        default: return 'Unknown command...';
    }
}

function ui_get_cmd_continue_link($command){
    global $WPDeploy;

    switch($command){
        case wpDeployCMD::$wp_deploy:  return $_POST['site_url'];


        default: return $WPDeploy->get_base_url();
    }
}

function ui_cmd($command){

    global $WPDeploy; $encryptedAuthKey = md5(WPD_AUTH_KEY);

    $script = ('$(function(){ var pData = ' .  @json_encode($_POST) . '; wpSetAuthKey("' . $encryptedAuthKey . '"); wpdTriggerCommand("' . $command . '", pData); });' );
    $WPDeploy->add_inline_script($script); ?>

    <p class="cmd-heading" id="cmdProgressBar">  <?php echo ui_get_cmd_heading($command); ?> </p>

    <div class="cmd-console-label"><em>Console</em></div>
    <div class="cmd-console-wrap">
        <div class="console"></div>
    </div>

    <div class="cmd-actions">

        <p>
            <button id="btnActionBack" class="btn" disabled onclick="wpdActionGoBack();"><span class="icon-arrow-left"></span> Back </button>
            <button id="btnActionReload" class="btn" disabled onclick="wpdActionReload();">Retry <span class="icon-repeat"></span> </button>
            <button id="btnActionAbort" class="btn btn-danger" onclick="wpdAbortCommand();">Abort <span class="icon-remove-sign"></span> </button>
            <button id="btnActionContinue" class="btn btn-success" disabled onclick="wpdActionContinue('<?php echo ui_get_cmd_continue_link($command); ?>')">
                Continue <span class="icon-arrow-right"></span>
            </button>
        </p>

        <form name="cmd-retry-form" id="cmdRetryForm" method="post" target="_self" action="<?php ui_cmd_url($_GET['cmd']); ?>" class="hidden">
            <?php if( count($_POST) ) foreach ($_POST as $name=>$value): ?>
                <input type="hidden" name="<?php echo strip_tags($name); ?>" value="<?php echo htmlentities($value); ?>">
            <?php endforeach; ?>
        </form>

    </div>

<?php
}


function ui_connection_settings_fields(){ ?>

    <fieldset>
        <legend>
            Connection Information <a class="icon-info-sign" href="#nowhere" title="click for more info" onclick="wpdShowInfo('.legend-info-box-ci');"></a>
            <p class="legend-info-box legend-info-box-ci hidden">
                <span class="icon-info"></span> Enter connection information for the remote server. Select the type of connection from the select box then enter
                the remote host's address (either an IP address like 93.184.216.119 or a hostname - ftp.example.com), the username and password provided by your hosting company.<br/>
                If your hosting provider offers FTP over SSL (FTPES) it is recommended to select FTP-ES as your connection type. <br/>
                If you need to use a different port then the standard one (ftp uses 21), check the &quot;Custom port&quot; checkbox and enter the port required to connect.<br/>
                Also you can activate passive FTP mode by clicking the &quot;Passive&quot; checkbox. <br/>
                In the &quot;Remote folder&quot; field enter the folder where you want to deploy to. This is usually public_html or www folder.<br/>
                In the &quot;Site URL&quot; enter the web address of the website you are deploying (e.g. http://www.example.com/).
            </p>
        </legend>

        <div class="control-group">
            <label class="control-label" for="connection_type">Connection type:</label>
            <div class="controls">
                <select name="connection_type" id="connection_type">
                    <option value="ftp">FTP</option>
                    <option value="ftpes">FTP-ES (FTP over SSL)</option>
                    <!--- <option value="sftp">SFTP</option> --->
                </select>

                <label class="checkbox inline" id="label-ftp-passive">
                    <input type="checkbox" name="ftp_passive" id="ftp_passive" value="yes"/> Passive
                </label>

            </div>
        </div>

        <div class="control-group">
            <label class="control-label" for="host">Host:</label>
            <div class="controls">
                <input type="text" name="host" id="host" required placeholder="ftp.example.com" value=""/>

                <label class="checkbox inline" id="label-cport">
                    <input type="checkbox" name="custom_port" id="custom_port" value="yes"/> Custom port
                </label>
            </div>
        </div>

        <div class="control-group hidden control-group-port">
            <label class="control-label" for="port">Port:</label>
            <div class="controls">
                <input type="number" class="input-small" name="port" id="port" required placeholder="port" value="21"/>
            </div>
        </div>

        <div class="control-group">
            <label class="control-label" for="user">Username:</label>
            <div class="controls">
                <input type="text" name="user" id="user" placeholder="somebody" value=""/>
            </div>
        </div>

        <div class="control-group">
            <label class="control-label" for="password">Password:</label>
            <div class="controls">
                <input type="password" name="password" id="password" placeholder="the password" value=""/>
            </div>
        </div>

        <div class="control-group">
            <label class="control-label" for="remote_folder">Remote folder:</label>
            <div class="controls">
                <input type="text" name="remote_folder" id="remote_folder" required placeholder="/" value="/public_html"/>
            </div>
        </div>

        <div class="control-group">
            <label class="control-label" for="site_url">Site URL:</label>
            <div class="controls">
                <input type="text" name="site_url" id="site_url" required value=""/>
            </div>
        </div>

    </fieldset>

    <?php
}

function ui_db_settings_fields($legend='Database Connection'){ ?>
    <fieldset id="db-connection-settings">
        <legend>
            <?php echo $legend; ?> <a class="icon-info-sign" title="click for more info" href="#nowhere" onclick="wpdShowInfo('.legend-info-box-dc');"></a>
            <p class="legend-info-box legend-info-box-dc hidden">
                <span class="icon-info"></span> Enter the desired database connection settings on the remote server.
                The database must exist on the server.
            </p>
        </legend>
        <div class="control-group">
            <label class="control-label" for="db_host">Database host:</label>
            <div class="controls">
                <input type="text" name="db_host" id="db_host" required placeholder="database host" value="localhost"/>
            </div>
        </div>
        <div class="control-group">
            <label class="control-label" for="db_user">Database user:</label>
            <div class="controls">
                <input type="text" name="db_user" id="db_user" required placeholder="database user" value=""/>
            </div>
        </div>
        <div class="control-group">
            <label class="control-label" for="db_password">Database password:</label>
            <div class="controls">
                <input type="password" name="db_password" id="db_password" placeholder="database password" value=""/>
            </div>
        </div>
        <div class="control-group">
            <label class="control-label" for="db_name">Database name:</label>
            <div class="controls">
                <input type="text" name="db_name" id="db_name" required placeholder="database name" value=""/>
            </div>
        </div>
    </fieldset>
<?php
}

function ui_site_settings_fields(){ ?>
    <fieldset>
        <legend>
            Site Settings <a class="icon-info-sign" href="#nowhere" onclick="wpdShowInfo('.legend-info-box-ss');"></a>
            <p class="legend-info-box legend-info-box-ss hidden">
                <span class="icon-info"></span> Enter the your new WordPress site settings.
            </p>
        </legend>
        <div class="control-group">
            <label class="control-label" for="blog_title">Site title:</label>
            <div class="controls">
                <input type="text" name="blog_title" id="blog_title" required placeholder="site title..." value="WP Demo"/>
            </div>
        </div>
        <div class="control-group">
            <label class="control-label" for="user_name">Admin user:</label>
            <div class="controls">
                <input type="text" name="user_name" id="user_name" required placeholder="admin username..." value="admin_demo"/>
            </div>
        </div>
        <div class="control-group">
            <label class="control-label" for="user_email">Admin email:</label>
            <div class="controls">
                <input type="email" name="user_email" id="user_email" required placeholder="admin email..." value=""/>
            </div>
        </div>
        <div class="control-group">
            <label class="control-label" for="user_password">Admin password:</label>
            <div class="controls">
                <input type="password" name="user_password" id="user_password" required placeholder="desired password..." value=""/>
            </div>
        </div>
    </fieldset>
    <?php
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8"/>
    <title>wp-copy &middot; copies your wordpress website </title>
    <link href="//netdna.bootstrapcdn.com/twitter-bootstrap/2.3.2/css/bootstrap-combined.min.css" rel="stylesheet"/>
    <link href="//netdna.bootstrapcdn.com/font-awesome/3.2.1/css/font-awesome.min.css" rel="stylesheet"/>
    <link href="http://fonts.googleapis.com/css?family=Source+Sans+Pro:400,700,700italic" rel="stylesheet" type="text/css"/>
    <link href="<?php echo ui_asset_url('/wp-copy-styles.css'); ?>" rel="stylesheet"/>
</head>
<body>

<div class="container main-container">

    <div class="heading">
        <h2 class="branding align-center"> wp-copy<sup style="font-weight: normal;">free</sup> </h2>
    </div>

    <div class="row">

        <div class="span2">&nbsp;</div>

        <div class="span8 main-content-wrapper">

            <div class="main-content">

                <?php if( !ui_has_cmd() ): ?>
                    <div class="menu"><ul class="nav nav-tabs"> <?php ui_nav_menu(); ?></ul></div>
                <?php endif; ?>

                <div class="content-container">

                    <?php if( ui_has_cmd() ): ui_cmd($_GET['cmd']); else: ?>

                        <?php if( ui_is_section('home') ): ?>

                            <?php if( $WPDeploy->is_wp_installed ): ?>
                                <p class="msg home-heading-msg">
                                    <em> You're currently running on WordPress <?php echo $WPDeploy->wp_version; ?> </em>
                                    <a class="btn btn-primary pull-right" style="margin-top: -5px;" href="<?php ui_url('copy'); ?>"> Copy <span class="icon-arrow-right"></span> </a>
                                    <a class="btn pull-right" style="margin-top: -5px; margin-right: 15px;" href="<?php echo $WPDeploy->get_base_url(); ?>" target="_blank">View site</a>
                                </p>

                                <div class="pro-features">
                                    <table class="table" style="width: 100%;">
                                        <tr>
                                            <td style="border: none;">Fix permalinks - the rewrite rules will be re-generated to match the configured ones  </td>
                                            <td style="border: none;"><span class="icon-ok"></span></td>
                                        </tr>
                                        <tr>
                                            <td>Fix Custom Menus - the site menus will be fully restored in their appropriate locations </td>
                                            <td><span class="icon-ok"></span></td>
                                        </tr>
                                        <tr>
                                            <td colspan="2" style="font-size: small;">
                                                <span class="icon-info"></span> The pro version also takes care of fixing the permalinks on the copied site.
                                                That means you don't have to log in onto the site you copied to and re-generate the htaccess file.
                                                Also your navigation menus will stay in place as on the original site.
                                                <a href="http://wpdev.me/downloads/wp-copy/">Find out more &rarr;</a>
                                            </td>
                                        <tr>
                                        <tr>
                                            <td  colspan="2" style="text-align: center;">
                                                <a class="btn btn-upgrade" href="http://wpdev.me/downloads/wp-copy/?utm_source=wpcopy-free-admin-page&utm_medium=banner&utm_campaign=wpcopy-free"> Upgrade to PRO <span class="icon-arrow-right"></span> </a>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>Create Snapshots - instantly saves a backup of your site</td>
                                            <td><span class="icon-ok"></span></td>
                                        </tr>
                                        <tr>
                                            <td>Advanced deployments - select specific folders, files or database tables to be copied</td>
                                            <td><span class="icon-ok"></span></td>
                                        </tr>
                                        <tr>
                                            <td>Create tasks - save your ftp/database credentials and re-use them later</td>
                                            <td><span class="icon-ok"></span></td>
                                        </tr>
                                        <tr>
                                            <td>Run cron jobs - deploy, copy or backup your site with a simple cron job</td>
                                            <td><span class="icon-ok"></span></td>
                                        </tr>

                                        <tr>
                                            <td colspan="2" style="font-size: small;">
                                                <span class="icon-info"></span> <strong>WP-Deploy</strong> gives you peace of mind when upgrading WordPress or changing theme files,
                                                as you can instantly create a snapshot the entire website. Also you'll be able to create advanced deployments by selecting the folders and
                                                database tables to be copied. And because we like automatic backups and deployments, with WP-Deploy you can save any backup/deployment
                                                as a task and run it periodically using a cron job.
                                                <a href="http://wpdev.me/downloads/wp-deploy/">Find out more &rarr;</a>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td colspan="2" style="text-align: center;">
                                                <a class="btn btn-upgrade" href="http://wpdev.me/downloads/wp-deploy/?utm_source=wpcopy-free-admin-page&utm_medium=banner&utm_campaign=wpcopy-free">Get WP-Deploy <span class="icon-arrow-right"></span> </a>
                                            </td>
                                        </tr>
                                    </table>
                                </div>

                                <hr/>

                                <div class="video-tutorial">
                                    <iframe width="100%" height="407" src="//www.youtube-nocookie.com/embed/q3zq3sDHx0w" frameborder="0" allowfullscreen></iframe>
                                </div>

                                <br class="clear"/>

                            <?php endif; ?>

                        <?php endif; ?>

                        <?php if( ui_is_section('copy') ): ?>

                            <form class="form-deploy form-deploy-ftp form-horizontal" action="<?php ui_cmd_url( wpDeployCMD::$wp_deploy ); ?>" method="post" target="_self">

                                <?php ui_connection_settings_fields(); ?>

                                <?php ui_db_settings_fields(); ?>

                                <p>&nbsp;</p>

                                <p class="align-center">
                                    <input type="hidden" name="deployment_type" value="new"/>
                                    <button class="btn btn-primary" type="submit"> <span class="icon-cloud-upload"></span> Copy now </button>
                                </p>

                            </form>

                        <?php endif; ?>

                        <?php if( ui_is_section('faq') ): ?>

                            <ul class="faq-list">
                                <li>
                                    <em class="question">Q: Can I use this to copy other sites?</em><br/>
                                    A: Yes you can use it for any WordPress website. It won't work with other CMSes like Joomla or Drupal, as it's developed around WordPress APIs.
                                </li>
                                <li>
                                    <em class="question">Q: I receive an error saying it can't finish the copy due to a &quot;slow upload speed&quot;. What does that means?</em><br/>
                                    A: It means that you are uploading a considerably big file compared to your upload limits.
                                    Usually the PHP SAPI interacting with your web server (e.g. <a href="https://en.wikipedia.org/wiki/Apache_HTTP_Server" target="_blank">Apache</a>) sets some limits
                                    for PHP scripts. If the file the script is trying to upload is considerably large it might take more then the time limit allowed, so the script will be stopped.<br/>
                                    Known workarounds for this, is either trying to get a faster connection or increasing time limit allowed for PHP scripts (highly discouraged on public servers).
                                </li>
                                <li>
                                    <em class="question">Q: Are my passwords safe?</em><br/>
                                    A: WP-Copy does not shares any of your passwords with thirdparties, and does not stores any passwords.
                                    Only the database username/password is sent via POST in order to create the appropriate wp-config.php file
                                    on the remote host. If you wish to achieve better security we recommend using HTTPS and FTP-ES protocols.
                                </li>
                                <li>
                                    <em class="question">Q: I have tried to copy my site but all I see on the other site is a page saying that &quot;a deployment is currently taking place...&quot;. What went wrong?</em><br/>
                                    A: This usually happens due to a slow connection, as described above or it might be some settings on the remote site that does not allows WP-Copy
                                    to run properly ( such as an incompatible PHP version or issues with folder permissions). <br/>
                                    Also on some servers, if you are moving the site from a domain to another domain on the same server the script will fail if the
                                    hosting provider is not allowing cURL requests originating from the same IP address.<br/>
                                    We have intensively tested our script, however we cannot
                                    guarantee it will work 100% of the time. If you encounter such problems please post a request on <a href="http://wpdev.me/forums/forum/plugin-support/wp-copy/" target="_blank">our forums</a> and we will jump on it asap.
                                </li>
                                <li>
                                    <em class="question">Q: I have tried to use the script but I received an error saying &quot;you have to be authenticated to access this page&quot;</em><br/>
                                    A: This happens because you are using another browser to enter the script page, or you cleared your cookies in your browser.
                                    WP-Copy enforces cookie-based authentication first (before WordPress admin authentication), in the attempt to make sure there's only one person using it.
                                </li>
                            </ul>

                        <?php endif; ?>

                        <?php if( ui_is_section('docs') ): ?>

                            <p>
                                WP-Copy is a PHP script in one file distributed along with the WP-Copy WordPress plugin. It's aim is to make WordPress developers lives easier
                                by speeding up the process of copying WordPress websites from a server to another one.
                            </p>

                            <h4>How to use?</h4>

                            <p>Please refer to <a href="https://www.youtube.com/watch?v=q3zq3sDHx0w">this video</a> .</p>

                            <h4>How it works?</h4>

                            <ol>
                                <li>First, it makes a backup of the database and saves it as a temporary file;</li>
                                <li>In that file, replaces the originating site URL with the destination site URL;</li>
                                <li>Creates a zip archive of the WordPress site with the database in it (along with non-wp files as well);</li>
                                <li>Connects to the remote server and uploads an index file to prevent &quot;uninvited&quot; access;</li>
                                <li>Uploads the zip archive created at step 3;</li>
                                <li>Uploads a copy of WP-Copy;</li>
                                <li>Runs commands on the destination site in order to finish the installation.</li>
                            </ol>

                            <h4>Requirements</h4>

                            <p>
                                WP-Copy requires PHP 5.3 along with the following extensions: Session, cURL, mbstring, json, mysql, mysqli, ftp and ssh2 for sftp support.
                            </p>

                        <?php endif; ?>

                    <?php endif; ?>

                </div>

            </div>

        </div>

        <div class="span2">&nbsp;</div>

    </div>

    <div class="row">
        <div class="span2">&nbsp;</div>
        <div class="span8 credits-bottom">
            brought to you by the <em><a href="http://wpdev.me/" target="_blank">WPDev Team</a> </em>
        </div>
        <div class="span2">&nbsp;</div>
    </div>

</div>
<script src="//ajax.googleapis.com/ajax/libs/jquery/1.10.2/jquery.min.js"></script>
<script src="//netdna.bootstrapcdn.com/twitter-bootstrap/2.3.2/js/bootstrap.min.js"></script>
<script type="text/javascript" src="<?php echo ui_asset_url('/wp-copy-js.js'); ?>"></script>
<script type="text/javascript">wpdInit('<?php echo $WPDeploy->baseURL; ?>');</script>
<?php $WPDeploy->scripts(); ?>
</body>
</html>
