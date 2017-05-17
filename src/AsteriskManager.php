<?php

/**
 * AsteriskManager is the wrapper for the api to access the Asterisk through Sockets
 */
class AsteriskManager
{

    private $socket;

    private $config_file = '/etc/asterisk/phpagi.conf';

    private $config;

    private $server, $port, $username, $secret;

    private $reconnects = 2;

    public function __construct($config = false)
    {

        if($config && is_array($config)){
            $this->config = $config;             
        }
        else{
            if(file_exists($config)){
                $this->config_file = $config;
            }

            $this->config = parse_ini_file($this->config_file, true);
        }

        if (!isset($this->config['asmanager']['server'])) {
            $this->config['asmanager']['server'] = 'localhost';
        }

        if (!isset($this->config['asmanager']['port'])) {
            $this->config['asmanager']['port'] = 5038;
        }

        if (!isset($this->config['asmanager']['username'])) {
            $this->config['asmanager']['username'] = 'phpagi';
        }

        if (!isset($this->config['asmanager']['secret'])) {
            $this->config['asmanager']['secret'] = 'phpagi';
        }

        /* set own properties */
        $this->server   = $this->config['asmanager']['server'];
        $this->port     = $this->config['asmanager']['port'];
        $this->username = $this->config['asmanager']['username'];
        $this->secret   = $this->config['asmanager']['secret'];

    }

    public function connect()
    {

        $this->events = 'on';

        $errno        = $errstr        = null;
        $this->socket = @fsockopen($this->server, $this->port, $errno, $errstr, 10);

        if ($this->socket == false) {
            $this->log("Unable to connect to manager {$this->server}:{$this->port} ($errno): $errstr");
            return false;
        } else {

            /* send login command */
            $res = $this->sendRequest('Login', array('Username' => $this->username, 'Secret' => $this->secret, 'Events' => $this->events), false);
            if ($res['Response'] != 'Success') {
                $this->log("Failed to login.");
                $this->disconnect(true);
                return false;
            }
            return true;
        }
    }
    public function sendRequest($action, $parameters = array(), $retry = true)
    {
        $reconnects = $this->reconnects;

        $req = "Action: $action\r\n";
        foreach ($parameters as $var => $val) {
            $req .= "$var: $val\r\n";
        }

        $req .= "\r\n";
        $this->log("Sending Request down socket:", 10);
        $this->log($req, 10);
        fwrite($this->socket, $req);
        $response = $this->waitResponse();

        // If we got a false back then something went wrong, we will try to reconnect the manager connection to try again
        //
        while ($response === false && $retry && $reconnects > 0) {
            $this->log("Unexpected failure executing command: $action, reconnecting to manager and retrying: $reconnects");
            $this->disconnect();
            if ($this->connect($this->server . ':' . $this->port, $this->username, $this->secret, $this->events) !== false) {
                fwrite($this->socket, $req);
                $response = $this->waitResponse();
            } else {
                if ($reconnects > 1) {
                    $this->log("reconnect command failed, sleeping before next attempt");
                    sleep(1);
                } else {
                    $this->log("FATAL: no reconnect attempts left, command permanently failed, returning to calling program with 'false' failure code");
                }
            }
            $reconnects--;
        }
        return $response;
    }
    public function disconnect($dontlogoff = null)
    {
        if (!$dontlogoff) {
            $this->logoff();
        }
        fclose($this->socket);
    }
    public function waitResponse()
    {
        $timeout = false;
        do {
            $type       = null;
            $parameters = array();

            if (feof($this->socket)) {
                return false;
            }
            $buffer = trim(fgets($this->socket, 4096));
            while ($buffer != '') {
                $a = strpos($buffer, ':');
                if ($a) {
                    if (!count($parameters)) // first line in a response?
                    {
                        $type = strtolower(substr($buffer, 0, $a));
                        if (substr($buffer, $a + 2) == 'Follows') {
                            // A follows response means there is a miltiline field that follows.
                            $parameters['data'] = '';
                            $buff               = fgets($this->socket, 4096);
                            while (substr($buff, 0, 6) != '--END ') {
                                $parameters['data'] .= $buff;
                                $buff = fgets($this->socket, 4096);
                            }
                        }
                    }

                    // store parameter in $parameters
                    $parameters[substr($buffer, 0, $a)] = substr($buffer, $a + 2);
                }
                $buffer = trim(fgets($this->socket, 4096));
            }

            // process response
            switch ($type) {
                case '': // timeout occured
                    $timeout = $allow_timeout;
                    break;
                case 'event':
                    /*$this->process_event($parameters);*/
                    break;
                case 'response':
                    break;
                default:

                    break;
            }
        } while ($type != 'response' && !$timeout);

        return $parameters;
    }
    public function log($message)
    {
        //echo $message;
    }
    public function Logoff()
    {
        return $this->sendRequest('Logoff', array(), false);
    }
}
