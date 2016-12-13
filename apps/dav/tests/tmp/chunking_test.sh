#!/bin/bash

script_path="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

user='admin'
pass='admin'
server='localhost:8080'

chunk="$script_path/chunk"
total_size=20971520 #total file size 20MB
chunk_size=10485760 #chunk size 10MB
truncate -s $chunk_size $chunk

rand=$RANDOM

#benchmark MKCOL in remote.php/dav/uploads
blackfire --samples 1 curl -X MKCOL -u $user:$pass -H "OC-Total-Length: $total_size"-H "Content-Length: 0" "http://$server/remote.php/dav/uploads/admin/$rand"

#PUT CHUNKS
blackfire --samples 1 curl -X PUT -u $user:$pass -H "Content-Length: $chunk_size" -H "OC-Chunk-Offset: 0" --data-binary @"$chunk" "http://$server/remote.php/dav/uploads/admin/$rand/0"
blackfire --samples 1 curl -X PUT -u $user:$pass -H "Content-Length: $chunk_size" -H "OC-Chunk-Offset: $chunk_size" --data-binary @"$chunk" "http://$server/remote.php/dav/uploads/admin/$rand/1"

#MOVE
blackfire --samples 1 curl -X MOVE -u $user:$pass -H "Content-Type: application/octet-stream" -H "Destination: /remote.php/dav/files/admin/zombie.jpg" -H "X-OC-Mtime: 1472576578" -H "OC-Async: 1" --data-binary @"$chunk" "http://$server/remote.php/dav/uploads/admin/$rand/.file"