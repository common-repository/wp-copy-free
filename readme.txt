=== WP-Copy (Free) ===
Contributors: adrian7
Tags: hosting, copy, backup, database, files, domain
Requires at least: 2.8
Tested up to: 3.8.1
Stable tag: 1.2.5
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Moves your WordPress site from a domain to another one without pains.

== Description ==

**WP-Copy** is a script that helps you move your WordPress site to a new domain by taking care of the hard work.

[youtube https://www.youtube.com/watch?v=q3zq3sDHx0w]

###Features###

* Copies the files and the database: all of your files and database tables (both WP and non-WP ones) are copied across;
* Fixes permalinks: replaces the old URLs in the database with the new URLs;
* Copies the files as zip archive: one single compressed file upload is uploaded on the remote host;
* Imports database in chunks, so if you got a big database file - no worries wp-copy will sort that for you;
* Protects the files during upload: automatically places an index file in the root of the remote site, so no uninvited visitors can see your files during the transfer;
* FTPES (FTP over SSL) support: supports both FTP active/passive modes as well as FTP over SSL;

####*The PRO version also does*:####
* Fixes serialized urls in database: in case you got custom menus they'll still work;
* Re-generates the htaccess file on the remote site, so you don't have to log in again and re-generate it yourself;
* Works with PHP 5.2;

####*Or get WP-Deploy for even more satisfaction*:####
* Instantly create snapshots of your site;
* More control over copying, select which folders and database tables you want to ignore;
* Save the remote server credentials and re-use them anytime;
* Run cron jobs to automate backups/copying/deployments;

***[WP-Copy PRO  &rarr;](http://wpdev.me/downloads/wp-copy/ "Get more peace in mind when transferring WordPress sites")***

***[WP-Deploy  &rarr;](http://wpdev.me/downloads/wp-deploy/ "Take control over your development process")***



Who's Who?

We are a team of WordPress lovers, visit us at: [WPDev.me](http://wpdev.me/ "WPDev.me - WordPress goodies for masses").

We also got a YouTube Channel: [WPDev.me on Youtube](https://www.youtube.com/channel/UC_nP4RzAE836kdihxNd4djA "WPDev.me - youtube channel").

For support and advice, check out [WPDev.me Forums](http://wpdev.me/forums/ "WPDev.me Forums").


== Installation ==

1. Download the plugin zip archive and save it on your computer;
2. In the admin area, go to Plugins->Add new page, then choose *Upload* from top menu;
3. Upload the wp-copy.zip file and click *Install now*;
4. Go back to Plugins, look for the plugin called WP-Copy (Free) and click *Activate*;
5. From the left-side menu, click on WP-Copy;

== Frequently Asked Questions ==

= Can I use this to copy other sites? =

Yes you can use it for any WordPress website. It won't work with other CMSes like Joomla or Drupal, as it's developed around WordPress APIs.

= I receive an error saying it can't finish the copy due to a &quot;slow upload speed&quot;. What does that means? =

It means that you are uploading a considerably big file compared to your upload limits.
Usually the PHP SAPI interacting with your web server (e.g. <a href="https://en.wikipedia.org/wiki/Apache_HTTP_Server" target="_blank">Apache</a>) sets some limits
for PHP scripts. If the file the script is trying to upload is considerably large it might take more then the time limit allowed, so the script will be stopped.
Known workarounds for this, is either trying to get a faster connection or increasing time limit allowed for PHP scripts (highly discouraged on public servers).
Additionally, if you're a PHP savvy you can try by setting up *WPD_ALLOW_IGNORE_USER_ABORT* to true on line 21 in wp-copy.php. This is an experimental feature,
which allows the PHP script to run for as much as it needs to. It might not work on all servers, as well as could lead to high resource usage on others.

= Are my passwords safe? =

WP-Copy does not shares any of your passwords with thirdparties, and does not stores any passwords.
Only the database username/password is sent via POST in order to create the appropriate wp-config.php file
on the remote host. If you wish to achieve better security we recommend using HTTPS and FTP-ES protocols.

= I have tried to copy my site but all I see on the other site is a page saying that &quot;a deployment is currently taking place...&quot;. What went wrong? =

This usually happens due to a slow connection, as described above or it might be some settings on the remote site that does not allows WP-Copy
to run properly ( such as an incompatible PHP version or issues with folder permissions).
Also on some servers, if you are moving the site from a domain to another domain on the same server the script will fail if the
hosting provider is not allowing cURL requests originating from the same IP address.
We have intensively tested our script, however we cannot guarantee it will work 100% of the time.
If you encounter such problems please post a request on <a href="http://wpdev.me/forums/forum/plugin-support/wp-copy/" target="_blank">our forums</a> and we will jump on it asap.

= I have tried to use the script but I received an error saying &quot;you have to be authenticated to access this page&quot;? =

This happens because you are using another browser to enter the script page, or you cleared the cookies in your browser.
WP-Copy enforces cookie-based authentication first (before WordPress admin authentication), in the attempt to make sure there's only one person using it.

== Screenshots ==

1. Script main page, with video tutorial;
2. Script copy page;

== Changelog ==

= 1.2.5 =
* Added alert for incompatible PHP versions (<5.3)
* Changes in the UI

= 1.2.0 =
* Fixed bug causing wp-config generation to randomly fail;
* Fixed css to accommodate with the new MP6 admin styles;
* Added script update on plugin init;

= 1.1.3 =
* Added message to instruct users to reset the permalinks once the site has been moved;
* Added WPD_LOCAL_BACKUP feature to allow saving locally, already-prepared backup file, before uploading;
* Fixed access to the script in plugin directory, after plugin uninstall;

= 1.1.2 =
* Changed script filename from wp-copy-free to wp-copy;

= 1.1 =
* Fixed admin area script installation failed message;
* Added tutorial links to script page;

= 1.0 =
* Added option to select ftp passive;
* Fixed bug with wp-config generation;
* Added progress bar;

= 0.8 =
* Added WPD_DEBUG_MODE switch;
* Added FTPES support.
* Randomized uploaded archive filename;
* Added feature to remove all temporary files once a command has finished;
* Added script cleanup feature after copy process ends;

= 0.7 =
* Fixed remote session handling (added cookie storage);
* Added index.php upload on the remote host, to prevent uninvited access;
* Added support for remote commands;
* Added htaccess regeneration support (PRO);
