<?php
// vim: set ai ts=4 sw=4 ft=php:
/**
 * This is the User Control Panel Object.
 *
 * Copyright (C) 2013 Schmooze Com, INC
 * Copyright (C) 2013 Andrew Nagy <andrew.nagy@schmoozecom.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package   FreePBX UCP BMO
 * @author   Andrew Nagy <andrew.nagy@schmoozecom.com>
 * @license   AGPL v3
 */

/**
 * AJAX Handler
 *
 * Proof of concept
 */
namespace UCP;
class Ajax extends UCP {

	public $storage = 'null';
	private $headers = array();
	public $settings = array( "authenticate" => true, "allowremote" => false );

	public function __construct($UCP) {
		$this->UCP = $UCP;
		$this->init();
	}

	public function init() {
		$this->getHeaders();
	}

	public function doRequest($module = null, $command = null) {
        if($command == 'poll') {
            $ret = $this->poll();
            if($ret === false) {
                $this->triggerFatal();
            }
            $this->addHeader('HTTP/1.0','200');
        } else {
    		if (!$module || !$command) {
    			$this->triggerFatal("Module or Command were null. Check your code.");
    		}

    		$ucMod = ucfirst($module);
    		if ($module != 'UCP' && $module != 'User' && class_exists(__NAMESPACE__."\\".$ucMod)) {
    			$this->triggerFatal("The class $module already existed. Ajax MUST load it, for security reasons");
    		}

    		if($module == 'User' || $module == 'UCP') {
    			// Is someone trying to be tricky with filenames?
    			$file = dirname(__FILE__).'/'.$ucMod.'.class.php';
    			if((strpos($module, ".") !== false) || !file_exists($file)) {
    				$this->triggerFatal("Module requested invalid");
    			}

    			// Note, that Self_Helper will throw an exception if the file doesn't exist, or if it does
    			// exist but doesn't define the class.
    			$this->injectClass($ucMod, $file);

    			$thisModule = $this->$ucMod;
    		} else {
    			$this->UCP->Modules->injectClass($ucMod);

    			$thisModule = $this->UCP->Modules->$ucMod;
    		}

    		if (!method_exists($thisModule, "ajaxRequest")) {
    			$this->ajaxError(501, 'ajaxRequest not found');
    		}

    		if (!$thisModule->ajaxRequest($command, $this->settings)) {
    			$this->ajaxError(403, 'ajaxRequest declined');
    		}

    		if (method_exists($thisModule, "ajaxCustomHandler")) {
    			$ret = $thisModule->ajaxCustomHandler();
    			if($ret === true) {
    				exit;
    			}
    		}

    		if (!method_exists($thisModule, "ajaxHandler")) {
    			$this->ajaxError(501, 'ajaxHandler not found');
    		}

    		// Right. Now we can actually do it!
    		$ret = $thisModule->ajaxHandler();
    		if($ret === false) {
    			$this->triggerFatal();
    		}
    		$this->addHeader('HTTP/1.0','200');
        }
		//some helpers
		if(!is_array($ret) && is_bool($ret)) {
			$ret = array(
				"status" => $ret,
				"message" => "unknown"
			);
		} elseif(!is_array($ret) && is_string($ret)) {
			$ret = array(
				"status" => true,
				"message" => $ret
			);
		}
		$output = $this->generateResponse($ret);
		$this->sendHeaders();
		echo $output;
		exit;
	}

	public function poll() {
		$modules = $this->UCP->Modules->getModulesByMethod('poll');
		$modData = array();
		$data = !empty($_REQUEST['data']) ? $_REQUEST['data'] : array();//
		foreach($modules as $module) {
			$modData[$module] = $this->UCP->Modules->$module->poll($data);
		}
		return array(
			"status" => true,
			"modData" => $modData
		);
	}

	public function ajaxError($errnum, $message = 'Unknown Error') {
		$this->addHeader('HTTP/1.0',$errnum);
		$output = $this->generateResponse(array("error" => $message));
		$this->sendHeaders();
		echo $output;
		exit;
	}

	private function triggerFatal($message = 'Unknown Error') {
		$this->ajaxError(500, $message);
	}

	private function getUrl() {
		return isset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'])
			? $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']
			: '';
	}

	private function getBody() {
		return empty($this->body) ? file_get_contents('php://input') : $this->body;
	}

	/**
	 * Get Known Headers from the Remote
	 *
	 * Get headers and then store them in an object hash
	 *
	 * @access private
	 */
	private function getHeaders() {
		$h = array(
			'accept'        => '',
			'address'		=> '',
			'content_type'	=> '',
			'host' 			=> '',
			'ip'			=> '',
			'nonce'			=> '',
			'port'			=> '',
			'signature'		=> '',
			'timestamp'		=> '',
			'token'			=> '',
			'uri'			=> '',
			'request'		=> '',
			'user_agent'	=> '',
			'verb'			=> '',
		);

		foreach ($_SERVER as $k => $v) {
			switch ($k) {
				case 'HTTP_ACCEPT':
					$h['accept'] = $v;
				break;
				case 'HTTP_HOST':
					$h['host'] = $v;
				break;
				case 'CONTENT_TYPE':
					$h['content_type'] = $v;
				break;
				case 'SERVER_NAME':
					$h['address'] = $v;
				break;
				case 'SERVER_PORT':
					$h['port'] = $v;
				break;
				case 'REMOTE_ADDR':
					$h['ip'] = $v;
				break;
				case 'REQUEST_URI':
					$h['request'] = $v;
				break;
				case 'HTTP_TOKEN':
					$h['token'] = $v;
				break;
				case 'HTTP_NONCE':
					$h['nonce'] = $v;
				break;
				case 'HTTP_SIGNATURE':
					$h['signature'] = $v;
				break;
				case 'HTTP_USER_AGENT':
					$h['user_agent'] = $v;
				break;
				case 'REQUEST_METHOD':
					$h['verb'] = strtolower($v);
				break;
				case 'PATH_INFO':
					$h['uri'] = $v;
				break;
				default:
				break;
			}
		}

		if(empty($h['uri'])) {
			$h['uri'] = $h['request'];
		}

		$this->req = new \StdClass();
		$this->req->headers = $this->arrayToObject($h);
	}

	/**
	 * Get Server Protocol
	 *
	 * Not used yet
	 *
	 * @return string http
	 * @access private
	 */
	private function getProtocol() {
		return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == "on"
			? 'https'
			: 'http';
	}

	/**
	 * Prepare headers to be returned
	 *
	 * Note: if just type is set, it will be assumed to be a value
	 *
	 * @param mixed $type type of header to be returned
	 * @param mixed $value value header should be set to
	 * @return $object New Object
	 * @access private
	 */
	public function addHeader($type, $value = '') {
		$responses = array(
			200	=> 'OK',
			201	=> 'Created',
			202	=> 'Accepted',
			204	=> 'No Content',
			301	=> 'Moved Permanently',
			303	=> 'See Other',
			304	=> 'Not Modified',
			307	=> 'Temporary Redirect',
			400	=> 'Bad Request',
			401	=> 'Unauthorized',
			402	=> 'Forbidden',
			404	=> 'Not Found',
			405	=> 'Method Not Allowed',
			406	=> 'Not Acceptable',
			409	=> 'Conflict',
			412	=> 'Precondition Failed',
			415	=> 'Unsupported Media Type',
			500	=> 'Internal Server Error',
			503 => 'Service Unavailable'
		);

		if ($type && !$value) {
			$value = $type;
			$type = 'HTTP/1.1';
		}

		//clean up type
		$type = str_replace(array('_', ' '), '-', trim($type));
		//HTTP responses headers
		if ($type == 'HTTP/1.1') {
			$value = ucfirst($value);
			//ok is always fully capitalized, not just its first letter
			if ($value == 'Ok') {
				$value = 'OK';
			}

			if (array_key_exists($value, $responses) || $value = array_search($value, $responses)) {
				$this->headers['HTTP/1.1'] = $value . ' ' . $responses[$value];
				return true;
			} else {
				return false;
			}
		} //end HTTP responses

		//all other headers. Not sure if/how we can validate them more...
		$this->headers[$type] = $value;

		return true;
	}

	/**
	 * Send Headers to PHP
	 *
	 * Gets headers from this Object (if set) and sends them to the PHP compiler
	 *
	 * @access private
	 */
	private function sendHeaders() {
		//send http header
		if (isset($this->headers['HTTP/1.1'])) {
			header('HTTP/1.1 ' . $this->headers['HTTP/1.1']);
			unset($this->headers['HTTP/1.1']);
		} else {
			header('HTTP/1.1 200 OK'); //defualt to 200
		}

		//send all headers, if any
		if ($this->headers) {
			foreach ($this->headers as $k => $v) {
				header($k . ': ' . $v);
				//unlist sent headers, as this mehtod can be called more than once
				unset($this->headers[$k]);
			}
		}

		//CORS: http://en.wikipedia.org/wiki/Cross-origin_resource_sharing
		header('Access-Control-Allow-Headers:Content-Type, Depth, User-Agent, X-File-Size, X-Requested-With, If-Modified-Since, X-File-Name, Cache-Control, X-Auth-Token');
		header('Access-Control-Allow-Methods: '.strtoupper($this->req->headers->verb));
		header('Access-Control-Allow-Origin:*');
		header('Access-Control-Max-Age:86400');
		header('Allow: '.strtoupper($this->req->headers->verb));
	}

	/**
	 * Generate Response
	 *
	 * Generates a response after determining the accepted response from the client
	 *
	 * @param mixed $body Array of what should be in the body
	 * @return string XML or JSON or WHATever
	 * @access private
	 */
    private function generateResponse($body) {
        $ret = false;

		if(!is_array($body)) {
			$body = array("message" => $body);
		}

		$accepts = explode(",",$this->req->headers->accept);
		foreach($accepts as $accept) {
			//strip off content accept priority
			$accept = preg_replace('/;(.*)/i','',$accept);
	        switch($accept) {
				case "text/json":
				case "application/json":
					$this->addHeader('Content-Type', 'text/json');
					return json_encode($body);
					break;
				case "text/xml":
				case "application/xml":
					$this->addHeader('Content-Type', 'text/xml');
					//DOMDocument provides us with pretty print XML. Which is...pretty.
					require_once(dirname(__FILE__).'/Array2XML2.class.php');
					$xml = \Array2XML2::createXML('response', $body);
					return $xml->saveXML();
	        }
		}

		//If nothing is defined then just default to showing json
		$this->addHeader('Content-Type', 'text/json');
		return json_encode($body);
    }

	/**
	 * Turn Array into an Object
	 *
	 * This turns any PHP array hash into a PHP Object. It's a cheat, but it works
	 *
	 * @param $arr The array
	 * @return object The PHP Object
	 * @access private
	 */
	private function arrayToObject($arr) {
		return json_decode(json_encode($arr), false);
	}
}
