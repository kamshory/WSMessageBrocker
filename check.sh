#!/bin/bash
message=$(curl -i -N -H "Connection: Upgrade" -H "Upgrade: websocket" -H "Host: www.domain.tld" -H "Origin: htt" https://www.domain.tld/message-broker.wss/ | grep "Ratchet")
if [ -z "$message" ];
then
  nohup /bin/php -q /var/www/html/bin/server.php &
else
  echo "The WebSocket server already running!"
fi