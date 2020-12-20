<?php

$server = '127.0.0.1';
$port = 8888;
$username = 'qa';
$password = '4lt0@1234';
$channel = 'sms';

putenv('MQ_SERVER_HOST='.$server);
putenv('MQ_SERVER_PORT='.$port);
putenv('MQ_CLIENT_USERNAME='.$username);
putenv('MQ_CLIENT_PASSWORD='.$password);
putenv('MQ_CANNEL_NAME='.$channel);

$server = getenv('MQ_SERVER_HOST');
$port = getenv('MQ_SERVER_PORT');
$username = getenv('MQ_CLIENT_USERNAME');
$password = getenv('MQ_CLIENT_PASSWORD');
$channel = getenv('MQ_CANNEL_NAME');

$headers = array(
	'Authorization: Basic '.base64_encode($username.':'.$password)
);

require "websocket-client.php";

function process_response($response)
{
	$json = json_decode($response, true);
	if($json != null && !empty($json))
	{
		$command = $json['command'];
		$data = $json['data'];
		print_r($json);
		if($command == 'send-message')
		{
		}
		else if($command == 'connect')
		{
			echo "Connected\r\n";
		}
	}
}

echo "Server: $server:$port\n";

$request = array(
	'command'=>'receive-message',
	'channel'=>$channel,
	'data'=>array(
	)
);
$message = json_encode($request, JSON_PRETTY_PRINT);

while(true)
{
	echo "Connecting...\r\n";
	if( $sp = websocket_open($server, $port, $headers, $errstr, 10, false) ) 
	{
		websocket_write($sp, $message);
		while(!@feof($sp))
		{
			$response = @websocket_read($sp, $errorcode, $errstr);
			if(strlen($response) > 7)
			{
				process_response($response);
			}
		}

	}
	else 
	{
		echo "Failed to connect to server\n";
		echo "Server responed with: $errstr\n";
	}
	sleep(5);
}

?>