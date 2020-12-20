<?php

namespace App;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use PhpAmqpLib\Connection;
use React\EventLoop\StreamSelectLoop;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use App\HTPasswd;

include dirname(__FILE__)."/autoload.php";

class MessageBroker implements MessageComponentInterface 
{
    protected $clients;
	protected $connectionMap = array();
	protected $numberOfReceiver = 1;
	protected $keepData = false;
	protected $database = null;
    protected $dbDriver = null;
    protected $dbHost = null;
    protected $dbPort = null;
    protected $dbName = null;
    protected $dbUser = null;
    protected $dbPass = null;
    protected $dbPrefix = null;
    protected $recordLimit = 1;
	protected $nextRecord = 0;
	protected $configuration = null;
	protected $delaySend = 0;

	/**
	 * Constructor
	 * @param Array $configuration Configuration
	 */
    public function __construct($configuration)
	{
		$this->clients = new \SplObjectStorage();
		$this->configuration = $configuration;
		$this->numberOfReceiver = @$configuration['server']['number_of_receiver'] * 1;
		$this->recordLimit = @$configuration['database']['limit'] * 1;
		$this->keepData = @$configuration['server']['keep_data'] && true;
		if($this->keepData)
		{
			$this->dbDriver = @$configuration['database']['driver'];
			$this->dbHost = @$configuration['database']['host'];
            $this->dbPort = @$configuration['database']['port'];
            $this->dbName = @$configuration['database']['name'];
            $this->dbUser = @$configuration['database']['username'];
            $this->dbPass = @$configuration['database']['password'];
            $this->dbPrefix = @$configuration['database']['prefix'];
            $this->delaySend = @$configuration['server']['delay_send'];
            $this->initDatabase();
 		}
	}

	/**
	 * onOpen
	 * This method is called when a client are connected to the server
	 * @param ConnectionInterface $conn New client
	 */
    public function onOpen(ConnectionInterface $conn) 
    {
		$this->clients->attach($conn);
		$headers = $conn->WebSocket->request->getHeaders();
		if($this->validUser($headers['authorization']))
		{
			$conn->send($this->createResponse('00'));
			$user_data = $this->extractUserData($conn);
			$this->connectionMap[$conn->resourceId] = $user_data;
			$this->updateUserData($conn->resourceId, array(
				'valid'=>true
			));
		}
		else
		{
			$conn->send($this->createResponse('01'));
			$this->updateUserData($conn->resourceId, array(
				'valid'=>false
			));
		}
	}
	
	/**
	 * onMessage
	 * This method is called when a client send a message
	 * @param ConnectionInterface $from Sender
	 * @param String $message Message sent by the client
	 */
    public function onMessage(ConnectionInterface $from, $message) 
    {
		$sender_data = $this->getUserData($from->resourceId);
		$json = json_decode($message, true);
		$channel = @$json['channel'];
		$command = @$json['command'];
		if($command == 'ping')
		{
			$this->updateUserData($from->resourceId, array(
				'type'=>'client',
				'channel'=>$channel
			));
			
			if($this->keepData)
			{
				if($this->testDatabase())
				{
					$response = $this->createResponse("00");
				}
				else
				{
					$response = $this->createResponse("01");
				}
			}
			else
			{
				$response = $this->createResponse("00");
			}
			$from->send($response);
		}
		else if($command == 'receive-message')
		{
			$this->updateUserData($from->resourceId, array(
				'type'=>'receiver',
				'channel'=>$channel
			));
			if($this->keepData)
			{
				do
				{
					$responseMessage = $this->loadFromDatabase($channel);
					if($responseMessage !== null)
					{
						$from->send(($responseMessage));
						if($this->nextRecord > 0 && $this->delaySend > 0)
						{
							usleep($this->delaySend * 1000);
						}
					}
				}
				while($this->nextRecord > 0);
			}
		}
		else if($command == 'send-message')
		{
			$this->updateUserData($from->resourceId, array(
				'type'=>'sender',
				'channel'=>$channel
			));
		}
		else
		{
			$this->updateUserData($from->resourceId, array(
				'type'=>'client',
				'channel'=>$channel
			));
		}
		if($command == 'send-message')
		{
			$nreceiver = 0;
			foreach($this->clients as $client)
			{
				$receiver_data = $this->getUserData($client->resourceId);
				if(@$sender_data['valid'] 
					&& @$receiver_data['valid'] 
					&& @$receiver_data['type'] == 'receiver' 
					&& @$receiver_data['channel'] == $channel
					)
				{
					$client->send($message);
					$nreceiver++;
					if($this->numberOfReceiver > 0 && $nreceiver >= $this->numberOfReceiver)
					{
						break;
					}
				}
			}
			if($nreceiver == 0 && $this->keepData)
			{
				$this->saveToDatabase($json);
			}
		}
    }

	/**
	 * onClose
	 * This method is called when a client is disconnected
	 * @param ConnectionInterface $conn Disconnected client
	 */
    public function onClose(ConnectionInterface $conn) 
    {
		$this->clients->detach($conn);
        $conn->close();        
    }

	/**
	 * onError
	 * This method is called when a client is error
	 * @param ConnectionInterface $conn Error client
	 * @param Exception $e Exception
	 */
    public function onError(ConnectionInterface $conn, \Exception $e) 
    {
		$this->clients->detach($conn);
        $conn->close();        
	}
	
	/**
	 * createResponse
	 * Create response when a client is connected
	 * @param String $response_code Response code
	 */
	protected function createResponse($response_code)
	{
		return json_encode(array(
			'command'=>'connect',
			'response_code'=>$response_code,
			'response_text'=>$this->getResponseText($response_code),
			'data'=>array(
				array(
					'time_stamp'=>gmdate("Y-m-d\TH:i:s").".000Z"
					)
			)
		));
	}

	/**
	 * updateUserData
	 * Update client data
	 * @param Int $resourceId Resource ID
	 * @param Array $user_data New data
	 */
	protected function updateUserData($resourceId, $user_data)
	{
		if(is_array($user_data))
		{
			foreach($user_data as $key=>$val)
			{
				$this->connectionMap[$resourceId][$key] = $val;
			}
		}
	}

	/**
	 * getResponseText
	 * Get response text from a response code
	 * @param String $response_code Response code
	 * @return String Response text
	 */
	protected function getResponseText($response_code)
	{
		$map = array(
			'00'=>'Accepted',
			'01'=>'Rejected'
		);
		return $map[$response_code];
	}

	/**
	 * extractUserData
	 * Extract client data from a connection
	 * @param ConnectionInterface $conn Client
	 * @return Array Client data
	 */
	protected function extractUserData($conn)
	{
		return array(
			'resource_id'=>$conn->resourceId
		);
	}


	/**
	 * getUserData
	 * Get client data from a resourceId
	 * @param Int $resourceId Resource ID
	 * @return Array Client data
	 */
	protected function getUserData($resourceId)
	{
		return $this->connectionMap[$resourceId];
	}

	/**
	 * validUser
	 * Validate client
	 * @param String $authorization Basic authorization sent by the client
	 * @return Boolean true if valid and false if invalid
	 */
 	protected function validUser($authorization)
	{
		$arr1 = explode(' ', $authorization, 2);
		if(strtolower($arr1[0]) == 'basic')
		{
			$arr2 = explode(':', base64_decode($arr1[1]), 2);
			$username = $arr2[0];
			$password = $arr2[1];
			
			$stored = '';
			if(@$this->configuration['credential']['source'] == 'file')
			{
				foreach($this->configuration['credential']['data'] as $file)
				{
					$passwordFile = dirname(dirname(dirname(__FILE__)))."/".$file;
					$stored .= file_get_contents($passwordFile)."\r\n";
				}
			}
			else
			{
				foreach($this->configuration['credential']['data'] as $str)
				{
					$stored .= $str."\r\n";
				}
			}
			return HTPasswd::auth($username, $password, $stored);
		}
		return false;
	}
	
	/**
	 * initDatabase
	 * Initialize database
	 */
	protected function initDatabase()
    {
        // Init database here
        try
        {
            $url = $this->dbDriver.":host=".$this->dbHost.";port=".$this->dbPort.";dbname=".$this->dbName;
            $this->database = new \PDO($url, $this->dbUser, $this->dbPass);
            $this->database->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }
        catch(PDOException $e)
        {
            $this->keepData = false;
            $this->log("Can not connect to database. Host : $this->dbHost");
        }
	}
	
	/**
	 * testDatabase
	 * Test database connection
	 * @return Boolean true if success and false if failed
	 */
	protected function testDatabase()
	{
		try
        {
            $sql = "select now() as now";
            $db_rs = $this->database->prepare($sql);
            $db_rs->execute();
            $rowCount = $db_rs->rowCount();
            if($rowCount > 0)
            {
                $rows = $db_rs->fetchAll(\PDO::FETCH_ASSOC);
				return (isset($rows['now']) && strlen(@$rows['now']) > 0);
            }
            else
            {
                return false;
            }
        }
        catch(PDOException $e)
        {
			$this->log("Can not connect to database. Host : $this->dbHost");
			$this->log("Reconnecting...");
            $this->initDatabase();
            return false;
        }
	}
	
    /**
	 * Load channel data from database
	 * @return String eesage to be sent to the client or null if data not exists
	 */
    protected function loadFromDatabase($channel)
    {
		$table_name = $this->dbPrefix."data";
        try
        {
            $channel = addslashes($channel);
            $sql = "select * from `$table_name` where channel = '$channel' ";
            $db_rs = $this->database->prepare($sql);
            $db_rs->execute();
            $rowCount = $db_rs->rowCount();
            if($rowCount > 0)
            {
                if($rowCount >= $this->recordLimit)
                {
                    $num = $rowCount - $this->recordLimit;
                    if($num < 0)
                    {
                        $num = 0;
                    }
                    $this->nextRecord = $num;

                    $sql = "select * from `$table_name` where channel = '$channel' order by data_id asc limit 0, ".$this->recordLimit;
                    $db_rs = $this->database->prepare($sql);
                    $db_rs->execute();
                }
                $rows = $db_rs->fetchAll(\PDO::FETCH_ASSOC);
                $data = array();
                $dataIDs = array();
                foreach($rows as $row)
                {
                    $data[] = json_decode($row['data']);
                    $dataIDs[] = $row['data_id'];
                }
                if(!empty($dataIDs))
                {
                    $sql = "delete from `$table_name` where data_id in(".implode(", ", $dataIDs).")";
                    $db_rs = $this->database->prepare($sql);
                    $db_rs->execute();
                }
                return json_encode(array(
					"command"=>"send-message", 
					"channel"=>$channel,
					"data"=>$data
					), true);
            }
            else
            {
                return null;
            }
        }
        catch(PDOException $e)
        {
			$this->log("Can not connect to database. Host : $this->dbHost");
			$this->log("Reconnecting...");
            $this->initDatabase();
            return $this->loadFromDatabase($channel);
        }
 	}

	/**
	 * saveToDatabase
	 * Save data to database
	 * @param Array $clientData Data sent by client to be saved on database
	 */
	protected function saveToDatabase($clientData)
	{
		$table_name = $this->dbPrefix."data";
        try
        {
            $channel = addslashes($clientData['channel']);
			$data = $clientData['data'];
			if(is_array($data))
			{
				$vallues = array();
				foreach($data as $idx=>$dt)
				{
					$string = addslashes(json_encode($dt));					
					$values[] = "('$channel', '$string', now())";
				}
				if(count($values) > 0)
				{					
					$sql = "insert into `$table_name`(channel, data, created) values\r\n".implode(",\r\n", $values);
					$db_rs = $this->database->prepare($sql);
					$db_rs->execute(); 
				}
			}			
        }
        catch(PDOException $e)
        {
			$this->log("Can not connect to database. Host : $this->dbHost");
			$this->log("Reconnecting...");
            $this->initDatabase();
            $this->saveToDatabase($clientData);
        }
	}
	
	/**
	 * Create log
	 * @param String $text Text to be logged
	 */
	protected function log($text)
	{
		if($this->showLog)
		{
			echo $text;
		}
	}
    
}
