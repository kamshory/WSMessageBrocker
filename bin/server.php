<?php
require dirname(__DIR__) . '/lib/bootstrap.php';
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use App\MessageBroker;

$ini_file = dirname(dirname(__FILE__))."/config.ini";
if(file_exists($ini_file))
{
	$configuration = parse_ini_file($ini_file, true, true);
}
else
{
}
if(isset($configuration) && isset($configuration['secret']) && $configuration['secret']['use_secret'])
{
	if($configuration['secret']['secret_mode'] == 'write')
	{
		$secret_data = $configuration['secret'];
		$secret_name = $secret_data['secret_name'];
		unset($secret_data['use_secret']);
		unset($secret_data['secret_mode']);
		unset($secret_data['secret_name']);
		saveSecret($secret_name, $secret_data);
	}
	if($configuration['secret']['secret_mode'] == 'read')
	{
		$secret_data = $configuration['secret'];
		$secret_name = $secret_data['secret_name'];		
		$new_configuration = getSecretConfiguration($secret_name);
		$configuration = updateConfiguration($configuration, $new_configuration);		
	}
}

function updateConfiguration($configuration, $new_configuration)
{
	foreach($new_configuration as $key=>$val)
	{
		if(is_array($val))
		{
			if(!isset($configuration[$key]))
			{
				$configuration[$key] = array();
			}
			foreach($val as $key2=>$val2)
			{
				$configuration[$key][$key2] = $val2;
			}
		}
		else
		{
			$configuration[$key] = $val;
		}
	}
	return $configuration;
}

function getSecretConfiguration($secret_name)
{
	$string = getenv($secret_name);
	$raw_configuration = json_decode(base64_decode($string));
	$configuration = array();
	foreach($raw_configuration as $key=>$val)
	{
		$arr = explode("->", $key, 2);
		if(count($arr) > 1)
		{
			$section = $arr[0];
			$name = $arr[1];
			if(!isset($configuration[$section]))
			{
				$configuration[$section] = array();
			}
			$configuration[$section][$name] = $val;
		}
		else
		{
			$name = $arr[0];
			$configuration[$name] = $val;
		}
	}
	return $configuration;
}

function saveSecret($secret_name, $secret_data)
{
	$string = base64_encode(json_encode($secret_data));
	putenv($secret_name."=".$string);
	$os = PHP_OS;
	if(stripos($os, "win") !== false)
	{
		exec("setx $secret_name $string");
	}
	else
	{
		exec("export $secret_name=$string");
	}
}

$server = IoServer::factory(
    new WsServer(
        new MessageBroker($configuration)
    ), $configuration['server']['port']
);

$server->run();
