<?php

use pmmp\thread\Thread;

/**
 * This is small example that shows the implementation of a thread based HTTP server
 * supporting Connection: keep-alive and how session handling can be implemented.
 * 
 * To start the server, open the command line and enter:
 * 
 *     $ php -f keep-alive-session.php 8822
 *     
 * where 8822 is an example port to let the server listen to. To run this example you
 * need a thread-safe compiled PHP > 5.3 with pthreads enabled.
 * 
 * @author Tim Wagner <tw@appserver.io>
 * @version 0.1.0
 * @link https://github.com/krakjoe/pthreads
 */
class Test extends Thread
{

    /**
     * Socket resource to read/write from/to.
     * 
     * @var resource
     */
    protected $socket;

    /**
     * Initializes the thread with the socket resource
     * necessary for reading/writing.
     * 
     * @param resource $socket The socket resource
     * @return void
     */
    public function __construct($socket)
    {
        $this->socket = $socket;
    }
    
    /**
     * Found on php.net {@link http://pa1.php.net/function.http-parse-headers#111226}, thanks
     * to anonymous!
     * 
     * @param string $header The header to parse
     * @return array The headers parsed from the passed string
     * @see http://pa1.php.net/function.http-parse-headers#111226
     */
    public function http_parse_headers($header)
    {
        $retVal = array();
        $fields = explode("\r\n", preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $header));
        foreach( $fields as $field ) {
            if (preg_match('/([^:]+): (.+)/m', $field, $match)) {
                $match[1] = preg_replace('/(?<=^|[\x09\x20\x2D])./e', 'strtoupper("\0")', strtolower(trim($match[1])));
                if( isset($retVal[$match[1]]) ) {
                    if (!is_array($retVal[$match[1]])) {
                        $retVal[$match[1]] = array($retVal[$match[1]]);
                    }
                    $retVal[$match[1]][] = $match[2];
                } else {
                    $retVal[$match[1]] = trim($match[2]);
                }
            }
        }
        return $retVal;
    }

    /**
     * The thread's run() method that runs in parallel.
     */
    public function run() : void
    {
                
        // initialize the local variables and the socket
        $threadId = $this->getThreadId();

        $counter = 1;
        $connectionOpen = true;
        $startTime = time();
        
        $timeout = 5;
        $maxRequests = 5;
        
        $client = socket_accept($this->socket);
        socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO, array("sec" => $timeout, "usec" => 0));
        
        do {
            
            // we only read headers here, because it's an example
            $buffer = '';
            while ($buffer .= socket_read($client, 1024)) {
                if (false !== strpos($buffer, "\r\n\r\n")) {
                    break;
                }
            }
            
            // check if the clients stopped sending data
            if ($buffer === '') {
                
                socket_close($client);
                $connectionOpen = false;
                
            } else {
            
                // parse the request headers
                $requestHeaders = $this->http_parse_headers($buffer);
                
                // simulate $_COOKIE array
                $_COOKIE = array();
                if (array_key_exists('Cookie', $requestHeaders)) {
                    $cookies = explode('; ', $requestHeaders['Cookie']);
                    foreach ($cookies as $cookie) {
                        list ($key, $value) = explode('=', $cookie);
                        $_COOKIE[$key] = $value;
                    }
                }
                
                // calculate the number of available requests (after this one)
                $availableRequests = $maxRequests - $counter++;
    
                // prepare response headers
                $headers = array();
                $headers[] = "HTTP/1.1 200 OK";
                $headers[] = "Content-Type: text/html";
                
                // start the session if not already done 
                if (session_status() == PHP_SESSION_NONE) {
                    session_start();
                }
                
                // write data to a REAL PHP session, started with session_start()!
                $_SESSION["thread_$threadId"]['availableRequest'] = $availableRequests;
                
                // add a header to create session cookie
                $headers[] = "Set-Cookie: " . session_name() . "=" . session_id() . "; Path=/";
                
                // prepare HTML body
                $body = '<html><head><title>A Title</title></head><body><p>Generated by thread: ' . $threadId . '</p><p>' . var_export($_SESSION, true) . '</p></body></html>';
                
                // prepare header with content-length
                $contentLength = strlen($body);
                $headers[] = "Content-Length: $contentLength";
    
                // check if this will be the last requests handled by this thread
                if ($availableRequests > 0) {
                    $headers[] = "Connection: keep-alive";
                    $headers[] = "Keep-Alive: max=$availableRequests, timeout=$timeout, thread={$this->getThreadId()}";
                } else {
                    $headers[] = "Connection: close";
                }
                
                // prepare the response head/body
                $response = array(
                    "head" => implode("\r\n", $headers) . "\r\n",
                    "body" => $body
                );
                
                // write the result back to the socket
                socket_write($client, implode("\r\n", $response));
                
                // check if this is the last request
                if ($availableRequests <= 0) {
                    // if yes, close the socket and end the do/while
                    socket_close($client);
                    $connectionOpen = false;
                }
            }
            
        } while ($connectionOpen);
    }        
}

// intialize the threads and the socket
$workers = array();
$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_bind($socket, '0.0.0.0', $argv[1]);
socket_listen($socket);

// we start 5 worker threads here
if ($socket) {
    
    $worker = 0;
    
    while (++ $worker < 5) {
        $workers[$worker] = new Test($socket);
        $workers[$worker]->start(Thread::INHERIT_ALL|Thread::ALLOW_HEADERS);
    }
    
    foreach ($workers as $worker) {
        $worker->join();
    }
}
