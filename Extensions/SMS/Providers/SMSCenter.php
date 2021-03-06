<?php
namespace Quark\Extensions\SMS\Providers;

use Quark\QuarkArchException;
use Quark\QuarkDTO;
use Quark\QuarkHTTPClient;
use Quark\QuarkJSONIOProcessor;
use Quark\QuarkPlainIOProcessor;

use Quark\Extensions\SMS\IQuarkSMSProvider;

/**
 * Class SMSCenter
 *
 * @package Quark\Extensions\SMS\Providers
 */
class SMSCenter implements IQuarkSMSProvider {
	const URL_API = 'http://smsc.ru/sys/send.php';

	/**
	 * @var string $_appID = ''
	 */
	private $_appID = '';

	/**
	 * @var string $_appSecret = ''
	 */
	private $_appSecret = '';

	/**
	 * @var string $_appName = null
	 */
	private $_appName = null;

	/**
	 * @param array|object $params = []
	 *
	 * @return QuarkDTO
	 *
	 * @throws QuarkArchException
	 */
	public function API ($params = []) {
		$request = QuarkDTO::ForGET(new QuarkPlainIOProcessor());
		$request->URIParams(array_merge(array(
			'login' => $this->_appID,
			'psw' => $this->_appSecret,
			'fmt' => 3,
			'charset' => 'utf-8'
		), $params));

		$response = QuarkHTTPClient::To(self::URL_API, $request, new QuarkDTO(new QuarkJSONIOProcessor()));

		if (isset($response->error))
			throw new QuarkArchException('SMSCenter API error: ' . print_r($response->error, true));

		return $response;
	}

	/**
	 * @param string $appID
	 * @param string $appSecret
	 * @param string $appName
	 *
	 * @return mixed
	 */
	public function SMSProviderApplication ($appID, $appSecret, $appName) {
		$this->_appID = $appID;
		$this->_appSecret = $appSecret;
		$this->_appName = $appName;
	}

	/**
	 * @param array|object $ini
	 *
	 * @return mixed
	 */
	public function SMSProviderOptions ($ini) {
		// TODO: Implement SMSProviderOptions() method.
	}

	/**
	 * @param string $message
	 * @param string[] $phones
	 *
	 * @return bool
	 */
	public function SMSSend ($message, $phones) {
		$query = array(
			'mes' => $message,
			'phones' => implode(',', $phones)
		);

		if ($this->_appName !== null)
			$query['sender'] = $this->_appName;

		return $this->API() != null;
	}

	/**
	 * @param string $message
	 * @param string[] $phones
	 *
	 * @return float
	 */
	public function SMSCost ($message, $phones) {
		$query = array(
			'mes' => $message,
			'phones' => implode(',', $phones),
			'cost' => 1
		);

		if ($this->_appName)
			$query['sender'] = $this->_appName;

		$response = $this->API();

		return isset($response->cost) ? $response->cost : null;
	}
}