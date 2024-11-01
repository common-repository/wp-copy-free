<?php
/**
 * WP-Copy plugin functions
 */

define('WPCOPY_PLUGIN_SLUG'     , 'wpcopy');
define('WPCOPY_PLUGIN_VERSION', '0.8.8');

define('WPCOPY_LOCAL_SCRIPT_FILE', 'wp-copy.php');

function wpcopy_init(){

    if( WPCOPY_PLUGIN_VERSION != get_option('wpcopy_version', 0) ){

        update_option( 'wpcopy_version', WPCOPY_PLUGIN_VERSION );

        wpcopy_plugin_activate(); //make sure we update the script

        $script_path = wpcopy_get_script_path();

        if( file_exists($script_path) )
            add_action('admin_notices', 'wpcopy_installed_message');

    }

}

function wpcopy_plugin_activate() {

    //--- install the script file in the WP root folder ---//
    if( file_exists(WPCOPY_LOCAL_SCRIPT_FILE)  ) wpcopy_script_install();
    //--- install the script file in the WP root folder ---//

}

function wpcopy_plugin_deactivate(){
    //--- move the script back into the plugin folder ---//
    wpcopy_script_uninstall();
    //--- move the script back into the plugin folder ---//
}

function wpcopy_installed_message(){
    $docs_url = 'http://wpdev.me/downloads/wp-copy/'; $script_url = wpcopy_get_script_url();?>
    <div class="updated">
    <p>
        WP-Copy script has been successfully installed.
        Find out <a href="<?php echo $docs_url; ?>">more about it</a> or jump on and
        <a class="button" href="<?php echo $script_url; ?>" target="_blank">copy the current site now</a>.
    </p>
    </div><?php
}

function wpcopy_installed_failed_message(){ ?>
    <div class="updated error">
        <p>
            <strong>Ups!</strong> The script could not be installed properly. Please check your folder permissions and try again.
            Additionally you can wanna take a look on <a href="http://wpdev.me/forums" target="_blank">our forums</a> and ask for further support.
        </p>
    </div><?php
}

function wpcopy_get_script_filename(){
    return basename(WPCOPY_LOCAL_SCRIPT_FILE);
}

function wpcopy_get_script_url(){
    return site_url( wpcopy_get_script_filename() );
}

function wpcopy_get_script_path($local=false){
    return $local ? wpcopy_path( WPCOPY_LOCAL_SCRIPT_FILE ) : (  ABSPATH . DIRECTORY_SEPARATOR . wpcopy_get_script_filename() );
}

function wpcopy_admin_css(){
    if( strpos($_SERVER['REQUEST_URI'], 'page=wpcopy') > 0 ){

        echo '<link rel="stylesheet" type="text/css" href="' . wpcopy_plugin_url('/styles/style.css') . '">';
        echo '<link rel="stylesheet" type="text/css" href="' . wpcopy_plugin_url('/styles/font-awesome.min.css') . '">';

    }
}

/**
 * Generates the plugin's admin menu
 */
function wpcopy_admin_menu(){
    add_menu_page( 'WP-Copy', 'WP-Copy', 'manage_options', WPCOPY_PLUGIN_SLUG, 'wpcopy_admin_page', '', 77 );
}

function wpcopy_script_install($path=false){
    //--- move the script from current location to wp root ---//

    $localfile = wpcopy_path( WPCOPY_LOCAL_SCRIPT_FILE );

    if( empty($path) ) $path = wpcopy_get_script_path();

    if( @copy($localfile, $path) ){
        @unlink($localfile); return true;
    }

    return false;

    //--- move the script from current location to wp root ---//
}

function  wpcopy_script_uninstall( $path=false ){

    //--- move the script from wp root to the plugin dir ---//
    $localfile = wpcopy_path( WPCOPY_LOCAL_SCRIPT_FILE );

    if( empty($path) ) $path = wpcopy_get_script_path();

    if( file_exists($path) and @copy($path, $localfile) ){
        @unlink($path); return true;
    }

    return false;
    //--- move the script from wp root to the plugin dir ---//
}

function wpcopy_admin_subpage($subpage=false){
    $subpage = empty($subpage) ? $_GET['page'] : $subpage;

    echo 'Showing subpage info; ' . $subpage;
}

function wpcopy_admin_page(){

    $script_path = wpcopy_get_script_path();

    if( !file_exists( $script_path ) ) {
        wpcopy_install_page(); return;
    }

    if( defined('WPCOPY_IS_PRO_VERSION') and WPCOPY_IS_PRO_VERSION )
        wpcopy_admin_script_redirect();
    else
        wpcopy_admin_main_page();

}

function wpcopy_admin_script_redirect(){
    $script_url  = wpcopy_get_script_url(); ?>
    <div class="wrap goodies-wrap">
    <div id="icon-tools" class="icon32"><br></div>
    <h2> WP-Copy (<?php echo WPCOPY_DIST_VERSION; ?>) </h2>

    <p class="wpcopy-info" style="text-align: center;">
        Redirecting you to the script page...<br/> If nothing happens in 3 seconds please <a href="<?php echo $script_url; ?>">click here</a>.
    </p>

    <script type="text/javascript">window.location.href = '<?php echo $script_url; ?>';</script>

    <?php
}

function wpcopy_install_page(){

    $script_path  = wpcopy_get_script_path(); $script_url  = wpcopy_get_script_url();  ?>

    <div class="wrap goodies-wrap">

        <div id="icon-tools" class="icon32"><br></div>
        <h2>Installing WP-Copy (<?php echo WPCOPY_DIST_VERSION; ?>)... </h2>

        <?php if( wpcopy_script_install( $script_path ) ): ?>

            <p class="msg note">
                The script was installed successfully. <a class="button" href="<?php echo $script_url; ?>">Launch it now <span class="fa fa-arrow-right"></span> </a>
            </p>

            <?php wpcopy_admin_page_content(); ?>

        <?php else: ?>

            <p class="msg warn">
                <strong>Ups!</strong> It seems the script file is missing from the plugin folder. It might be
                <a href="<?php echo $script_url; ?>" target="_blank">already installed</a>, or
                somebody accidentally deleted it.
                However you can get it back from <a href="http://wpdev.me/downloads/wp-copy" target="_blank">here</a>.
            </p>

        <?php endif; ?>

    </div>
<?php
}

function wpcopy_admin_main_page(){
    $script_url  = wpcopy_get_script_url(); ?>
    <div class="wrap goodies-wrap">
        <div id="icon-tools" class="icon32"><br></div>
        <h2> WP-Copy (<?php echo WPCOPY_DIST_VERSION; ?>) </h2>

        <?php wpcopy_admin_page_content(); ?>

    </div>
<?php
}

function wpcopy_admin_page_content(){
    $script_url  = wpcopy_get_script_url();  ?>
    <p class="wpcopy-info">
        Ever tried to move your WordPress site to a new server?
        Then you know, you have to download a copy of the database, replace all of the old URLs with the new ones, to keep permalinks,
        then upload the files one by one through ftp and finally log in into the server control panel and import the database.
        Also if the database export file it's too big the import will fail... . Being involved in WordPress development,
        that was happening to us to, almost every day - so we decided to create this awesome plugin.
    </p>
    <p class="wpcopy-info">
        WP-Copy takes care of all of this stuff for you, no more headaches regarding replacing URLs and too big database export files - just click &quot;copy&quot;,
        and the content and files will be transferred to the new website just like magic.<br/>
    </p>
    <p class="wpcopy-info">
        <strong>Oh wait!</strong> there's more, the PRO version also re-generates the htaccess file for the new website, meaning you don't have to log in and generate it
        by yourself in order to restore the permalinks. Also it will fix serialized data containing URLs, so your menus and theme customizations will be also restored.
    </p>

    <p>&nbsp;</p>

    <div class="align-left social-icons" style="width: 40%; float: left;">
        <a href="http://wpdev.me/?utm_source=wpcopy-free-admin-page&utm_medium=banner&utm_campaign=wpcopy-free" class="wpdev-logo" style="background-image: url('<?php echo wpcopy_plugin_url('/images/wpdev-logo.png'); ?>');" class="website-link" title="our website" target="_blank">
            &nbsp;
        </a>
        &nbsp;&nbsp;/&nbsp;
        <a href="https://www.facebook.com/pages/WPDev/264268130358039" target="_blank" title="on facebook" class="fb-link">
            <span class="fa fa-facebook-square"></span>
        </a>
        &nbsp;&nbsp;/&nbsp;
        <a href="https://twitter.com/wpdevdotme" target="_blank" title="on twitter" class="twitter-link">
            <span class="fa fa-twitter"></span>
        </a>
    </div>
    <div class="align-right launch-buttons" style="width: 44%; float: right;">
        <a class="button" href="<?php echo $script_url; ?>" target="_blank">
            Launch Free Version <span class="fa fa-arrow-right"></span>
        </a>
        <a class="button button-primary" href="http://wpdev.me/?utm_source=wpcopy-free-admin-page&utm_medium=banner&utm_campaign=wpcopy-free" style="margin-left: 30px;" target="_blank">
            Get the PRO version <span class="fa fa-thumbs-up"></span>
        </a>
    </div>

    <br class="clear"/>
    <?php
}

function wpcopy_plugin_url($file='/'){
    return (plugins_url( basename( dirname(__FILE__) ) ) . '/' . ltrim($file, '/'));
}

function wpcopy_path( $file='/' ){
    return ( dirname(__FILE__) . DIRECTORY_SEPARATOR . ltrim($file, '/') );
}

add_action('init', 'wpcopy_init');

add_action('admin_menu', 'wpcopy_admin_menu');
add_action('admin_head', 'wpcopy_admin_css');
