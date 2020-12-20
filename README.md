# WSMessageBrocker

# Introduction

Sometime, you need a very lightweight message broker that would run on a system with very minimum specifications. On the other hand, your server is ready with the PHP runtime. Installing a message brokerage service with large system requirements is not your choice because you are coming from very minimal resources.

Using a very light library is your choice because you don't want to sacrifice enormous resources for a very simple task. WSMessageBrocker is one of your choices. With a very easy installation and only using two server-side files, you can create a message broker that can forward messages from one client to another.

WSMessageBrocker is lightweight message broker that 100% PHP. You can use MariaDB or MySQL database to ensure that message received to the receiver. However, you can use another DBMS by modifying a little of the sorce code. 

You can still use WSMessageBrocker without using a database. In this case, the message will only be received if the recipient has connected to the server before sender send the message. Once the message is received by the server, it will disappear immediately regardless of whether it arrives or not.

# Requirement

No external libraries required. On Windows operating system, make sure tha the `extension=php_sockets.dll` is uncommented.

# Topolgy

![Topology](https://raw.githubusercontent.com/kamshory/WSMessageBrocker/main/topology.png)

From image above, we can see that message sender (client 1) can send message to receiver (client 2). Both sender and receiver do not require public IP address.

![Topology](https://raw.githubusercontent.com/kamshory/WSMessageBrocker/main/multi-channel.png)

WSMessageBrocker support multi channel. Receivers only will receive message with same channel. The user can limit the number of receivers for each channel. This is very useful for avoiding duplicate sending if an application is running more than one receiving process.

# User Credentials

WSMessageBrocker use HTPasswd as user credentials. To generate user password, use tool like https://www.htaccesstools.com/htpasswd-generator/

Supported Algorithm:

1. SHA
2. APR1

# Database

If you want to keep data to a database to ensure that message received to the receiver, database structure shown below:

```sql
CREATE TABLE `mq_data` (
 `data_id` bigint(20) NOT NULL AUTO_INCREMENT,
 `channel` varchar(100) DEFAULT NULL,
 `data` longtext,
 `created` datetime DEFAULT NULL,
 PRIMARY KEY (`data_id`),
 KEY `channel` (`channel`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
```

Note: `mq_` is table prefix. You can use another prefix according to your application.

You can define the fields in the table yourself according to your needs, but if you want to save the data in JSON format then the structure above is enough.

If you use the database to store the message, server will load message from the database and send it to the receiver after login successfully.

# Application

Example application is SMS gateway server. If you want to build an OTP server for your small application. 

Case

1. Your application is on shared hosting or small VPS hosting
2. You don't have any static IP address
3. You wan't use SMS gateway provider (for any reason)
4. You have an SMS gateway server and you want to put it on your home or your office
5. You want to integrate the application server and SMS gateway server

Your system topology can be as shown below

![SMS Gateway](https://raw.githubusercontent.com/kamshory/WSMessageBrocker/main/sms-gateway.png)

Other applications are IoT and smart home application using Raspberry Pi and others.
