<?php // -->
/**
 * GoIP Client/Server Package based on
 * GoIP SMS Gateway Interface.
 *
 * (c) 2014-2016 Openovate Labs
 *
 * Copyright and license information can be found at LICENSE.txt
 * distributed with this package.
 */

namespace GoIP;

// set time limit
set_time_limit(0);
// force buffer flushing
ob_implicit_flush();

/**
 * Server Class
 *
 * @package  GoIP
 * @author   Charles Zamora <czamora@openovate.com>
 * @standard PSR-2
 */
class Server extends Event
{
    /**
     * Default socket.
     *
     * @var object
     */
    protected $socket = null;

    /**
     * Server host.
     *
     * @var string
     */
    protected $host = null;

    /**
     * Server port.
     *
     * @var int
     */
    protected $port = null;

    /**
     * Read timeout.
     *
     * @var int
     */
    protected $timeout = 1;

    /**
     * Looop flag.
     *
     * @var bool
     */
    protected $end = false;

    /**
     * Last received id.
     *
     * @var int
     */
    protected $recent = array();

    /**
     * Initialize socket connection
     * and start accepting connection
     * through loop.
     *
     * @param   string
     * @param   int
     * @return  $this
     */
    public function __construct($host, $port) 
    {
        // set the host
        $this->host = $host;
        // set the port
        $this->port = $port;

        // create the socket
        // - We need to use UDP connection
        // - Socket Datagram for UDP connections
        // - protocol will be over UDP
        $this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        // if socket failed to be created
        if($this->socket < 0) {
            // set event message
            $message = 'Failed to create socket: ' . socket_strerror($this->socket) . PHP_EOL;

            // throw an event
            $this->trigger('server-error', $message);

            // exit
            exit();
        }

        // bind socket address and port
        $bind = socket_bind($this->socket, $this->host, $this->port);

        // if socket failed to bind address
        if($bind < 0) {
            // get error string
            $error   = socket_strerror($bind);

            // set event message
            $message = 'Failed to bind socket to: ' . $this->host . ':' . $this->port . ' ' . $error . PHP_EOL;

            // throw an event
            $this->trigger('server-error', $message);

            // exit
            exit();
        }

        // set non-blocking socket
        socket_set_nonblock($this->socket);
    }

    /**
     * Set read timeout.
     *
     * @param   int
     * @return  $this
     */
    public function setReadTimeout($timeout = 1)
    {
        // set timeout
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Start listening and receiving
     * data from the clients.
     *
     * @return  $this
     */
    public function loop()
    {
        // trigger socket bind
        // THIS PART IS A JOKE :p
        $this->trigger('bind', $this, $this->host, $this->port);

        // start connection loop
        // parse the request data
        while(!$this->end) {
            // read from the current socket
            $request = socket_recvfrom($this->socket, $buffer, 2048, 0, $from, $port);

            // if we receive nothing
            if(is_null($request) || !$request) {
                // trigger wait event, just let them
                // know that we are waiting for proper
                // message to be sent.
                $this->trigger('wait', $this);

                // timeout for a while
                sleep($this->timeout);

                continue;
            }

            // trigger request event
            $this->trigger('data', $this, $buffer, $from, $port);

            // parse buffer as array
            $data = Util::parseArray($buffer);

            // if keep alive message
            if(isset($data['req'])) {
                // initialize response
                $message = new Message(); 
                // generate ack message
                $message  = $message->getAck($data['req']);

                // send ACK response
                $acked = socket_sendto($this->socket, $message, strlen($message), 0, $from, $port);

                // successfully acked?
                if($acked === FALSE) {
                    // trigger ack-failed event
                    $this->trigger('ack-fail', $this);
                } else {
                    // trigger ack event
                    $this->trigger('ack', $this, $from, $port);
                }

                // timeout for a while
                sleep($this->timeout);

                continue;
            }

            // try to check if buffer has message
            $message = Util::getMessage($buffer);

            // ignore the message if it has
            // the same receive id as the last
            if(isset($message['RECEIVE'])
            && isset($this->recent[$message['RECEIVE']])) {
                continue;
            }

            // if we have a message
            if(!empty($message)) {
                // set last receive id
                $this->recent[$message['RECEIVE']] = $buffer;

                // trigger message event
                $this->trigger('message', $this, $buffer, $from, $port);

                // timeout for a while
                sleep($this->timeout);
            }
        }

        // let's end up?
        if($this->end) {
            exit();
        }

        return $this;
    }

    /**
     * Exit the current loop.
     *
     * @return  $this
     */
    public function end()
    {
        // set the flag to end everything
        $this->end = true;

        // trigger that everything ends
        $this->trigger('end');

        return $this;
    }
}