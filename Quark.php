<?php
namespace Quark;

/**
 * Class Quark
 *
 * This package contains main functionality for Quark PHP framework
 *
 * @package Quark
 *
 * @version 1.0.1
 * @author Alex Furnica
 *
 * @grandfather Furnica Alexandru Dumitru, agronomist, Deputy Chairman of the executive committee Vulcăneşti (Фурника Александр Дмитриевич, агроном, заместитель председателя райисполкома Вулканешты)
 * @grandmother Furnica Nina Feodorovna, biology teacher, teaching experience 49 years (Фурника Нина Фёдоровна, учитель биологии, преподавательский стаж 49 лет)
 * @mom Furnica Tatiana Alexandru, music teacher, teaching experience 28 years (Фурника Татьяна Александровна, учитель музыки, преподавательский стаж 28 лет)
 * @me Furnica Alexandru Dumitru, web programmer since 2009 (Фурника Александр Дмитриевич, веб-программист с 2009 года)
 */
class Quark {
	const MODE_DEV = 'dev';
	const MODE_PRODUCTION = 'production';

	const LOG_OK = ' ok ';
	const LOG_INFO = 'info';
	const LOG_WARN = 'warn';
	const LOG_FATAL = 'fatal';

	const UNIT_BYTE = 1;
	const UNIT_KILOBYTE = 1024;
	const UNIT_MEGABYTE = 1048576;
	const UNIT_GIGABYTE = 1073741824;

	const ALPHABET_ALL = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	const ALPHABET_LETTERS = 'abcdefghijklmnopqrstuvwxyz';
	const ALPHABET_PASSWORD = 'abcdefgpqstxyzABCDEFGHKMNPQRSTXYZ123456789';
	const ALPHABET_PASSWORD_LOW = 'abcdefgpqstxyz123456789';
	const ALPHABET_PASSWORD_LETTERS = 'abcdefgpqstxyz';
	
	/**
	 * @var bool $_init = false
	 */
	private static $_init = false;
	
	/**
	 * @var QuarkConfig $_config
	 */
	private static $_config;

	/**
	 * @var IQuarkEnvironment[] $_environment
	 */
	private static $_environment = array();

	/**
	 * @var IQuarkEnvironment $_currentEnvironment
	 */
	private static $_currentEnvironment;

	/**
	 * @var IQuarkStackable[] $_stack
	 */
	private static $_stack = array();

	/**
	 * @var IQuarkContainer[] $_containers
	 */
	private static $_containers = array();

	/**
	 * @var string $_currentLanguage = ''
	 */
	private static $_currentLanguage = '';

	/**
	 * @var float $_execTime = 0.0
	 */
	private static $_execTime = 0.0;

	/**
	 * @var null $_null = null
	 */
	private static $_null = null;

	/**
	 * @return bool
	 */
	public static function CLI () {
		return PHP_SAPI == 'cli';
	}

	/**
	 * @return QuarkConfig
	 */
	public static function Config () {
		if (self::$_config == null)
			self::$_config = new QuarkConfig();

		return self::$_config;
	}
	
	/**
	 * @return bool
	 */
	public static function _init () {
		if (!self::$_init) {
			if (!ini_get('date.timezone')) {
				ini_set('date.timezone', 'UTC');
				self::Log('Missed "date.timezone" in PHP configuration. UTC used.', self::LOG_WARN);
			}
			
			spl_autoload_extensions('.php');
			
			self::Import(__DIR__, function ($class) { return substr($class, 6); });
			self::Import(self::Host());
			
			self::$_init = true;
		}
		
		return self::$_init;
	}

	/**
	 * @param QuarkConfig $config = null
	 *
	 * @throws QuarkArchException
	 */
	public static function Run (QuarkConfig $config = null) {
		self::$_execTime = microtime(true);
		
		self::$_config = $config ? $config : new QuarkConfig();
		self::$_config->ConfigReady();

		$argc = isset($_SERVER['argc']) ? $_SERVER['argc'] : 0;
		$argv = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();

		$threads = new QuarkThreadSet($argc, $argv);

		self::Environment(self::CLI()
			? new QuarkCLIEnvironment($argc, $argv)
			: new QuarkFPMEnvironment($argc, $argv)
		);

		$threads->Threads(self::$_environment);

		$threads->On(QuarkThreadSet::EVENT_AFTER_INVOKE, function () {
			$timers = QuarkTimer::Timers();

			foreach ($timers as $timer)
				if ($timer) $timer->Invoke();

			self::ContainerFree();
		});

		if (!self::CLI() || ($argc > 1 || $argc == 0)) $threads->Invoke();
		else $threads->Pipeline(self::$_config->Tick());
	}

	/**
	 * @param string $host
	 *
	 * @return string
	 */
	public static function IP ($host) {
		return gethostbyname($host);
	}

	/**
	 * @param string $ip = ''
	 *
	 * @return mixed
	 */
	public static function IPInfo ($ip = '') {
		return QuarkHTTPClient::To('http://ipinfo.io/' . $ip, QuarkDTO::ForGET(), new QuarkDTO(new QuarkJSONIOProcessor()))->Data();
	}

	/**
	 * http://mycrimea.su/partners/web/access/ipsearch.php
	 *
	 * @param int $mask = 24
	 *
	 * @return string
	 */
	public static function CIDR ($mask = 24) {
		return long2ip(pow(2, 32) - pow(2, (32 - $mask)));
	}

	/**
	 * @param $string
	 * @param bool $values = true
	 *
	 * @return int[]|array
	 */
	public static function StringToBytes ($string, $values = true) {
		$bytes = unpack('C*', $string);

		return $values ? array_values($bytes) : $bytes;
	}

	/**
	 * @param int[] $bytes
	 *
	 * @return string
	 */
	public static function BytesToString ($bytes = []) {
		$out = '';

		foreach ($bytes as $byte)
			$out .= chr($byte);

		return $out;
	}

	/**
	 * @return string
	 */
	public static function HostIP () {
		return self::IP(php_uname('n'));
	}

	/**
	 * @return string
	 */
	public static function EntryPoint () {
		return $_SERVER['PHP_SELF'];
	}

	/**
	 * @param bool $endSlash = true
	 *
	 * @return string
	 */
	public static function Host ($endSlash = true) {
		return self::NormalizePath(getcwd(), $endSlash);
	}

	/**
	 * @return string
	 */
	public static function WebHost () {
		return self::$_config->WebHost()->URI(false);
	}

	/**
	 * @param string $path
	 *
	 * @return string
	 */
	public static function WebLocation ($path) {
		$uri = Quark::WebHost() . str_replace(Quark::Host(), '/', Quark::NormalizePath($path, false));
		return str_replace(':::', '://', str_replace('//', '/', str_replace('://', ':::', $uri)));
	}

	/**
	 * @param string $url
	 *
	 * @return string
	 */
	public static function FSLocation ($url) {
		return Quark::Host() . str_replace(Quark::WebHost(), '', $url);
	}

	/**
	 * @param string $path
	 * @param bool $endSlash = true
	 *
	 * @return string
	 */
	public static function NormalizePath ($path, $endSlash = true) {
		return is_scalar($path)
			? trim(preg_replace('#/+#', '/', self::RealPath(str_replace('\\', '/', $path))))
				. ($endSlash && (strlen($path) != 0 && $path[strlen($path) - 1] != '/') ? '/' : '')
			: ($path instanceof QuarkFile ? $path->location : '');
	}

	/**
	 * @param string $path
	 *
	 * https://stackoverflow.com/a/4050444/2097055
	 *
	 * @return string
	 */
	public static function RealPath ($path) {
		$absolutes = array();
		$route = explode('/', str_replace('\\', '/', $path));

		foreach ($route as $part) {
			if ('.'  == $part) continue;
			if ('..' == $part) array_pop($absolutes);
			else $absolutes[] = $part;
		}

		return implode('/', $absolutes);
	}

	/**
	 * Date unique ID
	 *
	 * @return string
	 */
	public static function DuID () {
		$micro = explode(' ', microtime());
		return gmdate('YmdHis', $micro[1]) . substr($micro[0], strpos($micro[0], '.'));
	}

	/**
	 * Global unique ID
	 *
	 * @return string
	 */
	public static function GuID () {
		return sha1(self::DuID());
	}

	/**
	 * @param int $id
	 * @param string $alphabet = self::ALPHABET_ALL
	 * @param int $base = 2
	 * @param int $mod = PHP_INT_MAX
	 *
	 * @return string
	 */
	public static function TextID ($id, $alphabet = self::ALPHABET_ALL, $base = 2, $mod = PHP_INT_MAX) {
		$number = (string)$id;

		$i = 0;
		$lenNum = strlen($number);
		$out = '';

		while ($i < $lenNum) {
			$result = (string)(((pow($base, (int)$number[$i] < 3 ? 3 : $number[$i]) % $mod) * $base) % $mod);

			$j = 0;
			$lenRes = strlen($result);
			$alphabet = str_shuffle($alphabet);

			while ($j < $lenRes) {
				$out .= $alphabet[$result[$j] % (pow($result[$j], $j) + 1)];
				$j++;
			}

			$i++;
		}

		return $out;
	}

	/**
	 * @param int $length = 10
	 * @param bool $readable = true
	 * @param bool $firstLetter = true
	 *
	 * @return string
	 */
	public static function GeneratePassword ($length = 10, $readable = true, $firstLetter = true) {
		$alphabet = self::ALPHABET_PASSWORD_LETTERS;

		return ($firstLetter ? $alphabet[rand(0, strlen($alphabet) - 1)]: '')
			. substr(
				self::TextID(
					pow($length, $length),
					$readable ? self::ALPHABET_PASSWORD_LOW : self::ALPHABET_ALL
				),
				0,
				$length - (int)$firstLetter
		);
	}

	/**
	 * @param string $pattern = ''
	 * @param string $alphabet = self::ALPHABET_LETTERS
	 *
	 * @return string
	 */
	public static function GenerateByPattern ($pattern = '', $alphabet = self::ALPHABET_LETTERS) {
		if (!preg_match_all('#(\\\?.)(\{([\d]+)\})*#', $pattern, $found, PREG_SET_ORDER)) return '';

		$out = '';
		$last = strlen($alphabet) - 1;

		foreach ($found as $item) {
			if (!isset($item[3])) {
				$out .= $item[1];
				continue;
			}

			$i = 0;
			$count = $item[3] == '' ? 1 : (int)$item[3];

			while ($i < $count) {
				switch ($item[1]) {
					case '\d': $out .= mt_rand(0, 9); break;
					case '\c': $out .= $alphabet[mt_rand(0, $last)]; break;
					case '\C': $out .= strtoupper($alphabet[mt_rand(0, $last)]); break;
					case '\s': $out .= ' '; break;
					default: $out .= $item[1]; break;
				}

				$i++;
			}
		}

		return $out;
	}

	/**
	 * @param string $regEx = ''
	 *
	 * @return mixed
	 */
	public static function EscapeRegEx ($regEx = '') {
		return preg_replace('#([\-\[\]\/\{\}\(\)\*\+\?\.\\\^\$\|])#Uis', '\\\$1', $regEx);
	}
	
	/**
	 * @param int $code
	 *
	 * http://stackoverflow.com/a/9878531/2097055
	 * http://il.php.net/manual/en/function.chr.php#88611
	 *
	 * @return string
	 */
	public static function UnicodeChar ($code) {
		return mb_convert_encoding('&#' . intval($code) . ';', 'UTF-8', 'HTML-ENTITIES');
	}

	/**
	 * @param IQuarkEnvironment $provider = null
	 *
	 * @return IQuarkEnvironment[]
	 */
	public static function &Environment (IQuarkEnvironment $provider = null) {
		if ($provider) {
			if (!$provider->EnvironmentMultiple())
				foreach (self::$_environment as $environment)
					if ($environment instanceof $provider) return self::$_environment;

			self::$_environment[] = $provider;
		}

		return self::$_environment;
	}

	/**
	 * @param IQuarkEnvironment $provider = null
	 *
	 * @return IQuarkEnvironment
	 */
	public static function &CurrentEnvironment (IQuarkEnvironment $provider = null) {
		if (func_num_args() != 0)
			self::$_currentEnvironment = $provider;

		return self::$_currentEnvironment;
	}

	/**
	 * @param string $name
	 * @param IQuarkStackable $component = null
	 *
	 * @return IQuarkStackable
	 *
	 * @throws QuarkArchException
	 */
	public static function &Component ($name, IQuarkStackable $component = null) {
		if (!$component)
			return self::Stack($name);

		return self::Stack($name, $component);
	}

	/**
	 * @return IQuarkStackable[]
	 */
	public static function &Components () {
		return self::$_stack;
	}

	/**
	 * @param string $name
	 * @param IQuarkStackable $object = null
	 *
	 * @return IQuarkStackable
	 *
	 * @throws QuarkArchException
	 */
	public static function &Stack ($name, IQuarkStackable $object = null) {
		if (func_num_args() == 2 && $object != null) {
			$object->Stacked($name);
			self::$_stack[$name] = $object;
		}

		if (!isset(self::$_stack[$name]))
			throw new QuarkArchException('Stackable object for ' . $name . ' does not stacked');

		return self::$_stack[$name];
	}

	/**
	 * @param IQuarkStackable $type
	 *
	 * @return IQuarkStackable[]
	 */
	public static function StackOf (IQuarkStackable $type) {
		$out = array();

		foreach (self::$_stack as $object)
			if ($object instanceof $type)
				$out[] = $object;

		return $out;
	}

	/**
	 * @param IQuarkContainer $container
	 */
	public static function Container (IQuarkContainer &$container) {
		self::$_containers[spl_object_hash($container->Primitive())] = $container;
	}

	/**
	 * @param string $id
	 *
	 * @return IQuarkContainer|null
	 */
	public static function &ContainerOf ($id) {
		if (!isset(self::$_containers[$id]))
			return self::$_null;

		return self::$_containers[$id];
	}

	/**
	 * @param IQuarkPrimitive $primitive
	 *
	 * @return IQuarkContainer|null
	 */
	public static function ContainerOfInstance (IQuarkPrimitive $primitive) {
		return self::ContainerOf(spl_object_hash($primitive));
	}

	/**
	 * Free associated containers
	 */
	public static function ContainerFree () {
		self::$_containers = array();
	}

	/**
	 * @return IQuarkContainer[]
	 */
	public static function &Containers () {
		return self::$_containers;
	}

	/**
	 * @param string $language = QuarkLanguage::ANY
	 *
	 * @return string
	 */
	public static function CurrentLanguage ($language = QuarkLanguage::ANY) {
		if (func_num_args() != 0)
			self::$_currentLanguage = $language;

		return self::$_currentLanguage;
	}

	/**
	 * @return string
	 */
	public static function CurrentLanguageFamily () {
		return self::$_currentLanguage != QuarkLanguage::ANY && !preg_match('#[a-z]{2}\-[A-Z]{2}#Uis', self::$_currentLanguage)
			? strtolower(self::$_currentLanguage) . '-' . strtoupper(self::$_currentLanguage)
			: '';
	}

	/**
	 * @param string $path
	 * @param callable $process = null
	 *
	 * @return bool
	 */
	public static function Import ($path, callable $process = null) {
		if (!is_string($path)) return false;

		spl_autoload_register(function ($class) use ($path, $process) {
			if ($process != null)
				$class = $process($class);

			$file = Quark::NormalizePath($path . '/' . $class . '.php', false);

			if (file_exists($file))
				/** @noinspection PhpIncludeInspection */
				include_once $file;
		});

		return true;
	}

	/**
	 * @param string $message
	 * @param string $lvl = self::LOG_INFO
	 * @param string $domain = 'application'
	 *
	 * @return int|bool
	 */
	public static function Log ($message, $lvl = self::LOG_INFO, $domain = 'application') {
		$logs = self::NormalizePath(self::Host() . '/' . self::Config()->Location(QuarkConfig::RUNTIME) . '/');

		if (!is_dir($logs)) mkdir($logs);

		return file_put_contents(
			$logs . $domain . '.log',
			'[' . $lvl . '] ' . QuarkDate::Now() . ' ' . $message . "\r\n",
			FILE_APPEND | LOCK_EX
		);
	}

	/**
	 * @param mixed $needle
	 * @param string $domain = 'application'
	 *
	 * @return int|bool
	 */
	public static function Trace ($needle, $domain = 'application') {
		return self::Log('[' . gettype($needle) . '] ' . print_r($needle, true), self::LOG_INFO, $domain);
	}

	/**
	 * @param QuarkException $e
	 *
	 * @return int|bool
	 */
	public static function LogException (QuarkException $e) {
		return self::Log($e->message, $e->lvl);
	}

	/**
	 * @param bool $args = false
	 * @param bool $trace = true
	 *
	 * @return array|int|bool
	 */
	public static function CallStack ($args = false, $trace = true) {
		$stack = debug_backtrace($args ? DEBUG_BACKTRACE_PROVIDE_OBJECT : DEBUG_BACKTRACE_IGNORE_ARGS);

		return $trace ? self::Trace($stack) : $stack;
	}

	/**
	 * @param bool $trace = false
	 *
	 * @return array|int|bool
	 */
	public static function ShortCallStack ($trace = false) {
		$stack = self::CallStack(false, false);
		$out = array();

		foreach ($stack as $item)
			$out[]  = (isset($item['class']) ? $item['class'] : '')
					. (isset($item['type']) ? $item['type'] : '')
					. $item['function']
					. ' ('
					. (isset($item['file']) ? $item['file'] : '[file]')
					. ':'
					. (isset($item['line']) ? $item['line'] : '[line]')
					. ')';

		return $trace ? self::Trace($out) : $out;
	}

	/**
	 * @return bool
	 */
	public static function MemoryAvailable () {
		$alloc = self::$_config->Alloc();

		return $alloc == 0 || memory_get_usage() <= $alloc * 1024 * 1024;
	}

	/**
	 * @param int $unit = self::UNIT_KILOBYTE
	 * @param int $precision = 2
	 *
	 * @return string
	 */
	public static function MemoryUsage ($unit = self::UNIT_KILOBYTE, $precision = 2) {
		$str = self::MemoryUnit($unit);

		return "[Quark] Memory usage:\r\n" .
				' - current:      ' . round(\memory_get_usage() / $unit, $precision) . $str . "\r\n" .
				' - current.real: ' . round(\memory_get_usage(true) / $unit, $precision) . $str . "\r\n" .
				' - peak:         ' . round(\memory_get_peak_usage() / $unit, $precision) . $str . "\r\n" .
				' - peak.real:    ' . round(\memory_get_peak_usage(true) / $unit, $precision) . $str . "\r\n";
	}

	/**
	 * @param $value
	 *
	 * @return string
	 */
	public static function MemoryUnit ($value) {
		switch ($value) {
			case self::UNIT_BYTE: return 'B'; break;
			case self::UNIT_KILOBYTE: return 'KB'; break;
			case self::UNIT_MEGABYTE: return 'MB'; break;
			case self::UNIT_GIGABYTE: return 'GB'; break;
		}

		return '-';
	}

	/**
	 * @return float
	 */
	public static function ExecutionTime () {
		return microtime(true) - self::$_execTime;
	}
	
	/**
	 * @param string[] $names = []
	 * @param string $fallback = ''
	 *
	 * @return string
	 */
	public static function EnvVar ($names = [], $fallback = '') {
		foreach ($names as $name) {
			$out = getenv($name);
			
			if ($out !== false) return $out;
		}
		
		return $fallback;
	}
}

/**
 * Class QuarkConfig
 *
 * @package Quark
 */
class QuarkConfig {
	const INI_QUARK = 'Quark';
	const INI_LOCALIZATION_DETAILS = 'LocalizationDetails';
	const INI_LOCAL_SETTINGS = 'LocalSettings';
	const INI_DATA_PROVIDERS = 'DataProviders';
	const INI_AUTHORIZATION_PROVIDER = 'AuthorizationProvider:';
	const INI_ASYNC_QUEUES = 'AsyncQueues';
	const INI_ENVIRONMENT = 'Environment:';
	const INI_EXTENSION = 'Extension:';
	const INI_CONFIGURATION = 'Configuration:';

	const SERVICES = 'services';
	const VIEWS = 'views';
	const RUNTIME = 'runtime';
	
	const LANGUAGE_DELIMITER = ',';

	/**
	 * @var IQuarkCulture $_culture = null
	 */
	private $_culture = null;

	/**
	 * @var int $_alloc = 10 (megabytes)
	 */
	private $_alloc = 10;

	/**
	 * @var int $_tick = 10000 (microseconds)
	 */
	private $_tick = QuarkThreadSet::TICK;

	/**
	 * @var string $_mode = Quark::MODE_DEV
	 */
	private $_mode = Quark::MODE_DEV;
	
	/**
	 * @var bool $_allowINIFallback = false
	 */
	private $_allowINIFallback = false;

	/**
	 * @var QuarkModel|IQuarkApplicationSettingsModel $_settingsApp = null
	 */
	private $_settingsApp = null;

	/**
	 * @var object $_settingsLocal = null
	 */
	private $_settingsLocal = null;

	/**
	 * @var string $_ini = ''
	 */
	private $_ini = '';

	/**
	 * @var QuarkFile $_localization = null
	 */
	private $_localization = null;
	
	/**
	 * @var object $_localizationDictionary = null
	 */
	private $_localizationDictionary = null;

	/**
	 * @var bool $_localizationByFamily = true
	 */
	private $_localizationByFamily = true;

	/**
	 * @var string $_localizationExtract = QuarkLocalizedString::EXTRACT_CURRENT
	 */
	private $_localizationExtract = QuarkLocalizedString::EXTRACT_CURRENT;

	/**
	 * @var bool $_localizationParseFailedToAny = false
	 */
	private $_localizationParseFailedToAny = false;

	/**
	 * @var object $_localizationDetails = null
	 */
	private $_localizationDetails = null;

	/**
	 * @var string[] $_localizationDetailsLoaded = []
	 */
	private $_localizationDetailsLoaded = array();
	
	/**
	 * @var string $_localizationDetailsDelimiter = ':'
	 */
	private $_localizationDetailsDelimiter = ':';

	/**
	 * @var string[] $_languages = [QuarkLanguage::ANY]
	 */
	private $_languages = array(QuarkLanguage::ANY);

	/**
	 * @var string $_modelValidation = QuarkModel::CONFIG_VALIDATION_ALL
	 */
	private $_modelValidation = QuarkModel::CONFIG_VALIDATION_ALL;

	/**
	 * @var array $_queues = []
	 */
	private $_queues = array();

	/**
	 * @var object $_configuration = null
	 */
	private $_configuration = null;
	
	/**
	 * @var string $_openSSLConfig = ''
	 */
	private $_openSSLConfig = '';

	/**
	 * @var callable $_ready = null
	 */
	private $_ready = null;

	/**
	 * @var array $_location
	 */
	private $_location = array(
		self::SERVICES => 'Services',
		self::VIEWS => 'Views',
		self::RUNTIME => 'runtime',
	);

	/**
	 * @var QuarkURI $_webHost
	 */
	private $_webHost;

	/**
	 * @var string $_streamHost = ''
	 */
	private $_streamHost = '';

	/**
	 * @var QuarkURI $_clusterControllerListen
	 */
	private $_clusterControllerListen;

	/**
	 * @var QuarkURI $_clusterControllerConnect
	 */
	private $_clusterControllerConnect;

	/**
	 * @var QuarkURI $_clusterMonitor
	 */
	private $_clusterMonitor;

	/**
	 * @var string $_clusterKey
	 */
	private $_clusterKey;

	/**
	 * @var QuarkURI $_selfHosted
	 */
	private $_selfHosted;

	/**
	 * @var bool $_allowIndexFallback = false
	 */
	private $_allowIndexFallback = false;

	/**
	 * @param string $ini = ''
	 */
	public function __construct ($ini = '') {
		$this->_culture = new QuarkCultureISO();
		$this->_webHost = new QuarkURI();

		$this->ClusterControllerListen(QuarkStreamEnvironment::URI_CONTROLLER_INTERNAL);
		$this->ClusterControllerConnect($this->_clusterControllerListen->ConnectionURI()->URI());
		$this->ClusterMonitor(QuarkStreamEnvironment::URI_CONTROLLER_EXTERNAL);
		$this->_selfHosted = QuarkURI::FromURI(QuarkFPMEnvironment::SELF_HOSTED);

		if (isset($_SERVER['SERVER_PROTOCOL']))
			$this->_webHost->scheme = $_SERVER['SERVER_PROTOCOL'];

		if (isset($_SERVER['SERVER_NAME']))
			$this->_webHost->host = $_SERVER['SERVER_NAME'];

		if (isset($_SERVER['SERVER_PORT']))
			$this->_webHost->port = $_SERVER['SERVER_PORT'];

		if (isset($_SERVER['DOCUMENT_ROOT']))
			$this->_webHost->path = Quark::NormalizePath(str_replace($_SERVER['DOCUMENT_ROOT'], '', Quark::Host()));

		$this->Ini($ini);
	}

	/**
	 * @param IQuarkCulture $culture = null
	 *
	 * @return IQuarkCulture|QuarkCultureISO
	 */
	public function &Culture (IQuarkCulture $culture = null) {
		if (func_num_args() != 0 && $culture != null)
			$this->_culture = $culture;

		return $this->_culture;
	}

	/**
	 * @param int $mb = 10 (megabytes)
	 *
	 * @return int
	 */
	public function &Alloc ($mb = 10) {
		if (func_num_args() != 0)
			$this->_alloc = $mb;

		return $this->_alloc;
	}

	/**
	 * @param int $ms = 10000 (microseconds)
	 *
	 * @return int
	 */
	public function &Tick ($ms = QuarkThreadSet::TICK) {
		if (func_num_args() != 0)
			$this->_tick = $ms;

		return $this->_tick;
	}

	/**
	 * @param string $mode = Quark::MODE_DEV
	 *
	 * @return string
	 */
	public function &Mode ($mode = Quark::MODE_DEV) {
		if (func_num_args() != 0)
			$this->_mode = $mode;

		return $this->_mode;
	}
	
	/**
	 * @param bool $fallback = false
	 *
	 * @return bool
	 */
	public function AllowINIFallback ($fallback = false) {
		if (func_num_args() != 0)
			$this->_allowINIFallback = $fallback;
		
		return $this->_allowINIFallback;
	}

	/**
	 * @param string $name
	 * @param IQuarkStackable $object = null
	 * @param string $message = ''
	 *
	 * @return IQuarkStackable|QuarkSessionSource|QuarkModelSource|IQuarkExtensionConfig
	 *
	 * @throws QuarkArchException
	 */
	private function _component ($name, IQuarkStackable $object = null, $message = '') {
		try {
			return Quark::Component($name, $object);
		}
		catch (\Exception $e) {
			throw new QuarkArchException($message . '. Additional : ' . $e->getMessage());
		}
	}

	/**
	 * @param string $name
	 * @param IQuarkAuthorizationProvider $provider = null
	 * @param IQuarkAuthorizableModel $user = null
	 *
	 * @return QuarkSessionSource
	 */
	public function AuthorizationProvider ($name, IQuarkAuthorizationProvider $provider = null, IQuarkAuthorizableModel $user = null) {
		return $this->_component(
			$name,
			func_num_args() == 3 ? new QuarkSessionSource($name, $provider, $user) : null,
			'AuthorizationProvider for key ' . $name . ' does not configured'
		);
	}

	/**
	 * @param string $name
	 * @param IQuarkDataProvider $provider = null
	 * @param QuarkURI $uri = null
	 *
	 * @return QuarkModelSource
	 */
	public function DataProvider ($name, IQuarkDataProvider $provider = null, QuarkURI $uri = null) {
		return $this->_component(
			$name,
			(func_num_args() == 3 && $provider != null && $uri != null) || (func_num_args() == 2 && $provider != null) ? new QuarkModelSource($name, $provider, $uri) : null,
			'DataProvider for key ' . $name . ' does not configured'
		);
	}

	/**
	 * @param string $name
	 * @param IQuarkExtensionConfig $config = null
	 *
	 * @return IQuarkExtensionConfig
	 */
	public function Extension ($name, IQuarkExtensionConfig $config = null) {
		return $this->_component(
			$name,
			$config,
			'Extension for key ' . $name . ' does not configured'
		);
	}

	/**
	 * @param string $name
	 * @param QuarkURI $uri = null
	 * @param IQuarkNetworkProtocol $protocol = null
	 *
	 * @return QuarkKeyValuePair
	 */
	public function AsyncQueue ($name, QuarkURI $uri = null, IQuarkNetworkProtocol $protocol = null) {
		if (!isset($this->_queues[$name]) || func_num_args() == 2)
			$this->_queues[$name] = new QuarkKeyValuePair($uri, $protocol);

		return $this->_queues[$name];
	}

	/**
	 * @param IQuarkEnvironment $provider = null
	 *
	 * @return IQuarkEnvironment[]
	 */
	public function Environment (IQuarkEnvironment $provider = null) {
		return Quark::Environment($provider);
	}

	/**
	 * @param IQuarkApplicationSettingsModel $model = null
	 *
	 * @return QuarkModel|IQuarkApplicationSettingsModel
	 */
	public function &ApplicationSettings (IQuarkApplicationSettingsModel $model = null) {
		if (func_num_args() != 0 && $model != null)
			$this->_settingsApp = new QuarkModel($model);
		else $this->_loadSettings();

		return $this->_settingsApp;
	}

	/**
	 * @param string $name
	 * @param IQuarkConfiguration $config = null
	 *
	 * @return IQuarkConfiguration
	 */
	public function Configuration ($name, IQuarkConfiguration $config = null) {
		if ($this->_configuration == null)
			$this->_configuration = new \stdClass();

		if ($config != null)
			$this->_configuration->$name = $config;

		return isset($this->_configuration->$name) ? $this->_configuration->$name : null;
	}
	
	/**
	 * @param string $location = ''
	 *
	 * @return string
	 */
	public function OpenSSLConfig ($location = '') {
		if (func_num_args() != 0)
			$this->_openSSLConfig = $location;
		
		return $this->_openSSLConfig;
	}

	/**
	 * @return bool
	 */
	private function _loadSettings () {
		if ($this->_settingsApp == null) return false;
		
		$criteria = $this->_settingsApp->LoadCriteria();
		$settings = null;

		if ($criteria !== null) $settings = QuarkModel::FindOne($this->_settingsApp->Model(), $criteria);
		else {
			$settings = $this->_settingsApp;

			Quark::Log('[QuarkConfig::_loadSettings] Load criteria for ApplicationSettings is null, so default ' . get_class($this->_settingsApp->Model()) . ' model returned');
		}

		if ($settings == null || !($settings->Model() instanceof IQuarkApplicationSettingsModel)) return false;

		$this->_settingsApp = $settings;
		return true;
	}

	/**
	 * @param $data
	 *
	 * @return bool
	 */
	public function UpdateApplicationSettings ($data = null) {
		if ($this->_settingsApp == null) return false;

		$ok = $this->_loadSettings();

		if (func_num_args() != 0)
			$this->_settingsApp->PopulateWith($data);

		return $ok ? $this->_settingsApp->Save() : $this->_settingsApp->Create();
	}

	/**
	 * @param string $key = ''
	 * @param string $value = ''
	 *
	 * @return mixed
	 */
	public function LocalSettings ($key = '', $value = '') {
		if ($this->_settingsLocal == null)
			$this->_settingsLocal = new \stdClass();

		if (func_num_args() == 2)
			$this->_settingsLocal->$key = $value;

		return isset($this->_settingsLocal->$key) ? $this->_settingsLocal->$key : null;
	}

	/**
	 * @param string $path
	 *
	 * @return bool
	 */
	public function SharedResource ($path = '') {
		return Quark::Import($path);
	}

	/**
	 * @param string $component
	 * @param string $location = ''
	 *
	 * @return string
	 */
	public function Location ($component, $location = '') {
		if (func_num_args() == 2)
			$this->_location[$component] = $location;

		return isset($this->_location[$component]) ? $this->_location[$component] : '';
	}

	/**
	 * @param QuarkURI|string $uri = ''
	 *
	 * @return QuarkURI
	 */
	public function &WebHost ($uri = '') {
		if (func_num_args() != 0)
			$this->_webHost = QuarkURI::FromURI($uri);

		return $this->_webHost;
	}

	/**
	 * @param string $host = ''
	 *
	 * @return string
	 */
	public function &StreamHost ($host = '') {
		if (func_num_args() != 0)
			$this->_streamHost = $host;
		
		return $this->_streamHost;
	}

	/**
	 * @param QuarkURI|string $listen = ''
	 * @param QuarkURI|string $connect = ''
	 *
	 * @return QuarkConfig
	 */
	public function ClusterController ($listen = '', $connect = '') {
		$this->ClusterControllerListen($listen);
		$this->ClusterControllerConnect($connect);
		
		if (func_num_args() == 1)
			$this->ClusterControllerConnect($this->_clusterControllerListen->ConnectionURI()->URI());
		
		return $this;
	}

	/**
	 * @param QuarkURI|string $uri = ''
	 *
	 * @return QuarkURI
	 */
	public function &ClusterControllerListen ($uri = '') {
		if (func_num_args() != 0)
			$this->_clusterControllerListen = QuarkURI::FromURI($uri);

		return $this->_clusterControllerListen;
	}

	/**
	 * @param QuarkURI|string $uri = ''
	 *
	 * @return QuarkURI
	 */
	public function &ClusterControllerConnect ($uri = '') {
		if (func_num_args() != 0)
			$this->_clusterControllerConnect = QuarkURI::FromURI($uri);

		return $this->_clusterControllerConnect;
	}

	/**
	 * @param QuarkURI|string $uri = ''
	 *
	 * @return QuarkURI
	 */
	public function &ClusterMonitor ($uri = '') {
		if (func_num_args() != 0)
			$this->_clusterMonitor = QuarkURI::FromURI($uri);

		return $this->_clusterMonitor;
	}

	/**
	 * @param string $key = ''
	 *
	 * @return string
	 */
	public function &ClusterKey ($key = '') {
		if (func_num_args() != 0)
			$this->_clusterKey = $key;

		return $this->_clusterKey;
	}

	/**
	 * @param QuarkURI|string $uri = ''
	 *
	 * @return QuarkURI
	 */
	public function &SelfHostedFPM ($uri = '') {
		if (func_num_args() != 0)
			$this->_selfHosted = QuarkURI::FromURI($uri);

		return $this->_selfHosted;
	}

	/**
	 * @param bool $allow = false
	 *
	 * @return bool
	 */
	public function AllowIndexFallback ($allow = false) {
		if (func_num_args() != 0)
			$this->_allowIndexFallback = $allow;

		return $this->_allowIndexFallback;
	}

	/**
	 * @param string $file
	 *
	 * @return string
	 */
	public function Ini ($file = '') {
		if (func_num_args() != 0)
			$this->_ini = $file;
		
		return $this->_ini;
	}

	/**
	 * @param string $file = ''
	 *
	 * @return QuarkFile
	 */
	public function Localization ($file = '') {
		if (func_num_args() != 0)
			$this->_localization = new QuarkFile($file);

		return $this->_localization;
	}
	
	/**
	 * @param string $key
	 *
	 * @return object
	 */
	private function _localization ($key) {
		if ($this->_localizationDictionary == null)
			$this->_localizationDictionary = $this->_localization == null
				? null
				: $this->_localization->Decode(new QuarkINIIOProcessor(), true);

		if (preg_match('#^(.*)' . Quark::EscapeRegEx($this->_localizationDetailsDelimiter) . '.*#i', $key, $found) && !in_array($found[1], $this->_localizationDetailsLoaded)) {
			$domain = $found[1];

			if (isset($this->_localizationDetails->$domain)) {
				$details = QuarkFile::FromLocation($this->_localizationDetails->$domain)->Decode(new QuarkINIIOProcessor(), true);
				$this->_localizationDetailsLoaded[] = $domain;

				if ($this->_localizationDictionary == null)
					$this->_localizationDictionary = new \stdClass();

				if (QuarkObject::isTraversable($details))
					foreach ($details as $key => $block) {
						$outKey = $domain . $this->_localizationDetailsDelimiter . $key;
						$this->_localizationDictionary->$outKey = $block;
					}
			}
		}

		return $this->_localizationDictionary;
	}

	/**
	 * @param string $key = ''
	 * @param string $language = QuarkLanguage::ANY
	 *
	 * @return bool
	 */
	public function LocalizationExists ($key = '', $language = QuarkLanguage::ANY) {
		$locale = $this->_localization($key);

		return isset($locale->$key->$language);
	}

	/**
	 * @param string $key = ''
	 * @param string $language = QuarkLanguage::ANY
	 * @param string $value = ''
	 *
	 * @return string
	 */
	public function LocalizationOf ($key = '', $language = QuarkLanguage::ANY, $value = '') {
		$locale = $this->_localization($key);
		
		if (func_num_args() == 3) {
			if ($this->_localizationDictionary == null)
				$this->_localizationDictionary = new \stdClass();
			
			if (!isset($this->_localizationDictionary->$key))
				$this->_localizationDictionary->$key = new \stdClass();
			
			$this->_localizationDictionary->$key->$language = $value;
			$locale = $this->_localizationDictionary;
		}

		return isset($locale->$key->$language) ? $locale->$key->$language : '';
	}

	/**
	 * @param string $key = ''
	 * @param array|object $dictionary = []
	 *
	 * @return object
	 */
	public function LocalizationDictionaryOf ($key = '', $dictionary = []) {
		$locale = $this->_localization($key);
		
		if (func_num_args() == 2) {
			if ($this->_localizationDictionary == null)
				$this->_localizationDictionary = new \stdClass();
			
			$this->_localizationDictionary->$key = (object)$dictionary;
			$locale = $this->_localizationDictionary;
		}

		return isset($locale->$key) ? $locale->$key : new \stdClass();
	}

	/**
	 * @param bool $localize = true
	 *
	 * @return bool
	 */
	public function LocalizationByFamily ($localize = true) {
		if (func_num_args() != 0)
			$this->_localizationByFamily = $localize;
		
		return $this->_localizationByFamily;
	}

	/**
	 * @param string $localization = QuarkLocalizedString::EXTRACT_CURRENT
	 *
	 * @return string
	 */
	public function LocalizationExtract ($localization = QuarkLocalizedString::EXTRACT_CURRENT) {
		if (func_num_args() != 0)
			$this->_localizationExtract = QuarkObject::ConstValue($localization);

		return $this->_localizationExtract;
	}

	/**
	 * @param bool $force = false
	 *
	 * @return bool
	 */
	public function LocalizationParseFailedToAny ($force = false) {
		if (func_num_args() != 0)
			$this->_localizationParseFailedToAny = $force;
		
		return $this->_localizationParseFailedToAny;
	}

	/**
	 * @param string $key = ''
	 * @param bool $strict = false
	 *
	 * @return string
	 */
	public function CurrentLocalizationOf ($key = '', $strict = false) {
		$locale = $this->_localization($key);

		$lang_current = Quark::CurrentLanguage();
		$lang_any = QuarkLanguage::ANY;
		$lang_family = Quark::CurrentLanguageFamily();

		return isset($locale->$key->$lang_current)
			? $locale->$key->$lang_current
			: (!$strict && $this->_localizationByFamily && isset($locale->$lang_family)
				? $locale->$lang_family
				: (!$strict && isset($locale->$key->$lang_any)
					? $locale->$key->$lang_any
					: ''
				)
			);
	}
	
	/**
	 * @param string $domain = ''
	 * @param string $location = ''
	 *
	 * @return string|null
	 */
	public function LocalizationDetails ($domain = '', $location = '') {
		if ($this->_localizationDetails == null)
			$this->_localizationDetails = new \stdClass();
		
		if (func_num_args() == 2)
			$this->_localizationDetails->$domain = $location;
		
		return isset($this->_localizationDetails->$domain)
			? $this->_localizationDetails->$domain
			: null;
	}
	
	/**
	 * @param string $delimiter = ':'
	 *
	 * @return string
	 */
	public function LocalizationDetailsDelimiter ($delimiter = ':') {
		if (func_num_args() != 0)
			$this->_localizationDetailsDelimiter = $delimiter;
		
		return $this->_localizationDetailsDelimiter;
	}
	
	/**
	 * @param string|string[] $languages = ''
	 * @param string $delimiter = self::LANGUAGE_DELIMITER
	 *
	 * @return string[]
	 */
	public function Languages ($languages = '', $delimiter = self::LANGUAGE_DELIMITER) {
		if (func_num_args() != 0)
			$this->_languages = is_array($languages) ? $languages : explode($delimiter, (string)$languages);
		
		return $this->_languages;
	}

	/**
	 * @param string $mode = QuarkModel::CONFIG_VALIDATION_ALL
	 *
	 * @return string
	 */
	public function ModelValidation ($mode = QuarkModel::CONFIG_VALIDATION_ALL) {
		if (func_num_args() != 0)
			$this->_modelValidation = QuarkObject::ConstValue($mode);
		
		return $this->_modelValidation;
	}

	/**
	 * @param callable $callback = null
	 */
	public function ConfigReady (callable $callback = null) {
		if (func_num_args() != 0) {
			$this->_ready = $callback;
			return;
		}
		
		if (!$this->_ini) return;

		$file = QuarkFile::FromLocation($this->_ini);
		
		if (!$file->Exists() && $this->_allowINIFallback) return;
		
		$ini = $file->Load()->Decode(new QuarkINIIOProcessor());
		$callback = $this->_ready;

		if ($callback != null)
			$callback($this, $ini);

		if (!$ini) return;
		$ini = (array)$ini;

		if (isset($ini[self::INI_QUARK]))
			foreach ($ini[self::INI_QUARK] as $key => $value)
				$this->$key($value);

		if (isset($ini[self::INI_DATA_PROVIDERS]))
			foreach ($ini[self::INI_DATA_PROVIDERS] as $key => &$connection) {
				$component = Quark::Component(QuarkObject::ConstValue($key));
				
				if ($component instanceof QuarkModelSource)
					$component->URI(QuarkURI::FromURI($connection));
			}

		if (isset($ini[self::INI_ASYNC_QUEUES]))
			foreach ($ini[self::INI_ASYNC_QUEUES] as $key => &$queue) {
				$name = QuarkObject::ConstValue($key);
				
				if (!isset($this->_queues[$name]))
					$this->_queues[$name] = new QuarkKeyValuePair(null, null);
			
				$this->_queues[$name]->Key(QuarkURI::FromURI($queue));
			}

		if (isset($ini[self::INI_LOCAL_SETTINGS])) {
			if ($this->_settingsLocal == null)
				$this->_settingsLocal = new \stdClass();
			
			foreach ($ini[self::INI_LOCAL_SETTINGS] as $key => $value)
				$this->_settingsLocal->$key = $value;
		}

		if (isset($ini[self::INI_LOCALIZATION_DETAILS])) {
			if ($this->_localizationDetails == null)
				$this->_localizationDetails = new \stdClass();
			
			foreach ($ini[self::INI_LOCALIZATION_DETAILS] as $key => $value)
				$this->_localizationDetails->$key = $value;
		}

		if (QuarkObject::isTraversable($this->_configuration))
			foreach ($this->_configuration as $key => &$item) {
				/**
				 * @var IQuarkConfiguration $item
				 */

				$options = self::_ini($ini, self::INI_CONFIGURATION, QuarkObject::ConstValue($key));

				if (QuarkObject::isTraversable($options)) {
					$ready = $item->ConfigurationReady($key, $options);

					if ($ready == true || $ready === null)
						foreach ($options as $name => $value)
							$item->$name($value);
				}
			}

		$environments = Quark::Environment();

		foreach ($environments as $i => &$environment) {
			$options = self::_ini($ini, self::INI_ENVIRONMENT, $environment->EnvironmentName());

			if ($options !== null)
				$environment->EnvironmentOptions($options);
		}

		$components = Quark::Components();

		foreach ($components as $key => &$component) {
			if ($component instanceof QuarkSessionSource) {
				$options = self::_ini($ini, self::INI_AUTHORIZATION_PROVIDER, $component->Name());

				if ($options !== null)
					$component->Options($options);
			}

			if ($component instanceof IQuarkExtensionConfig) {
				$options = self::_ini($ini, self::INI_EXTENSION, $component->ExtensionName());

				if ($options !== null)
					$component->ExtensionOptions($options);
			}
		}

		unset($environment, $environments);
		unset($extension, $extensions);
		unset($options, $callback, $ini);
	}

	/**
	 * @param object|array $ini
	 * @param string $prefix
	 * @param string $name
	 *
	 * @return object
	 */
	private static function _ini ($ini, $prefix, $name) {
		$key = $prefix . $name;
		
		if (isset($ini[$key]))
			return (object)$ini[$key];

		$name = QuarkObject::ConstByValue($name);
		if (!$name) return null;

		$key = $prefix . $name;

		return isset($ini[$key]) ? (object)$ini[$key] : null;
	}
}

/**
 * Interface IQuarkConfiguration
 *
 * @package Quark
 */
interface IQuarkConfiguration {
	/**
	 * @param string $key
	 * @param object $ini
	 *
	 * @return bool
	 */
	public function ConfigurationReady($key, $ini);
}

/**
 * Interface IQuarkStackable
 *
 * @package Quark
 */
interface IQuarkStackable {
	/**
	 * @param string $name
	 */
	public function Stacked($name);
}

/**
 * Interface IQuarkEnvironment
 *
 * @package Quark
 */
interface IQuarkEnvironment extends IQuarkThread {
	/**
	 * @return bool
	 */
	public function EnvironmentMultiple();

	/**
	 * @return string
	 */
	public function EnvironmentName();

	/**
	 * @param object $ini
	 *
	 * @return mixed
	 */
	public function EnvironmentOptions($ini);
}

/**
 * Trait QuarkEvent
 *
 * @package Quark
 */
trait QuarkEvent {
	/**
	 * @var array $_events
	 */
	private $_events = array();

	/**
	 * @param string $event
	 * @param callable $callback
	 */
	public function On ($event, callable $callback) {
		if (!isset($this->_events[$event]))
			$this->_events[$event] = array();

		$this->_events[$event][] = $callback;
	}

	/**
	 * @param string $event
	 *
	 * @return bool
	 */
	public function Trigger ($event) {
		return $this->TriggerArgs($event, array_slice(func_get_args(), 1));
	}

	/**
	 * @param string $name
	 * @param array $args
	 *
	 * @return bool
	 */
	public function TriggerArgs ($name, $args) {
		if (!isset($this->_events[$name])) return true;

		foreach ($this->_events[$name] as $w => &$worker)
			call_user_func_array($worker, $args);

		return true;
	}

	/**
	 * @param string $name
	 *
	 * @return callable[]
	 */
	public function &EventWorkers ($name) {
		if (!isset($this->_events[$name]))
			$this->_events[$name] = array();

		return $this->_events[$name];
	}

	/**
	 * @param $name
	 * @param IQuarkEventable $eventable
	 */
	public function Delegate ($name, IQuarkEventable $eventable) {
		$this->_events[$name] = $eventable->EventWorkers($name);
	}
}

/**
 * Interface IQuarkEventable
 *
 * @package Quark
 */
interface IQuarkEventable {
	/**
	 * @param string $event
	 * @param callable $callback
	 */
	public function On($event, callable $callback);

	/**
	 * All specified arguments after $event will be applied to callback
	 *
	 * @param string $event
	 *
	 * @return bool
	 */
	public function Trigger($event);

	/**
	 * @param string $event
	 *
	 * @return callable[]
	 */
	public function EventWorkers($event);
}

/**
 * Class QuarkFPMEnvironment
 *
 * @package Quark
 */
class QuarkFPMEnvironment implements IQuarkEnvironment {
	const ENV = 'FPM';

	const SELF_HOSTED = 'http://127.0.0.1:25080';

	const DIRECTION_REQUEST = 'Request';
	const DIRECTION_RESPONSE = 'Response';
	const DIRECTION_BOTH = 'Both';

	/**
	 * @var string $_statusNotFound = QuarkDTO::STATUS_404_NOT_FOUND
	 */
	private $_statusNotFound = QuarkDTO::STATUS_404_NOT_FOUND;

	/**
	 * @var string $_statusServerError = QuarkDTO::STATUS_500_SERVER_ERROR
	 */
	private $_statusServerError = QuarkDTO::STATUS_500_SERVER_ERROR;

	/**
	 * @var IQuarkIOProcessor $_processorRequest = null
	 */
	private $_processorRequest = null;

	/**
	 * @var IQuarkIOProcessor $_processorResponse = null
	 */
	private $_processorResponse = null;

	/**
	 * @var IQuarkIOFilter $_filterRequest = null
	 */
	private $_filterRequest = null;

	/**
	 * @var IQuarkIOFilter $_filterResponse = null
	 */
	private $_filterResponse = null;

	/**
	 * @return bool
	 */
	public function EnvironmentMultiple () { return false; }

	/**
	 * @return string
	 */
	public function EnvironmentName () {
		return self::ENV;
	}

	/**
	 * @param object $ini
	 *
	 * @return void
	 */
	public function EnvironmentOptions ($ini) {
		if (isset($ini->DefaultNotFoundStatus))
			$this->DefaultNotFoundStatus($ini->DefaultNotFoundStatus);

		if (isset($ini->DefaultServerErrorStatus))
			$this->DefaultServerErrorStatus($ini->DefaultServerErrorStatus);
	}

	/**
	 * @return bool
	 */
	public function UsageCriteria () {
		return !Quark::CLI();
	}

	/**
	 * @param string $status = ''
	 *
	 * @return string
	 */
	public function DefaultNotFoundStatus ($status = '') {
		if (func_num_args() == 1)
			$this->_statusNotFound = $status;

		return $this->_statusNotFound;
	}

	/**
	 * @param string $status = ''
	 *
	 * @return string
	 */
	public function DefaultServerErrorStatus ($status = '') {
		if (func_num_args() == 1)
			$this->_statusServerError = $status;

		return $this->_statusServerError;
	}

	/**
	 * @param $option
	 * @param $direction
	 * @param $value
	 *
	 * @return IQuarkIOProcessor|IQuarkIOFilter
	 */
	private function _option ($option, $direction, $value = null) {
		if ($value != null) {
			if ($direction != self::DIRECTION_BOTH) {
				$key = '_' . $option . $direction;
				$this->$key = $value;
			}
			else {
				$key = '_' . $option . self::DIRECTION_REQUEST;
				$this->$key = $value;

				$key = '_' . $option . self::DIRECTION_RESPONSE;
				$this->$key = $value;
			}
		}

		$opt = '_' . $option . ($direction == self::DIRECTION_BOTH ? self::DIRECTION_RESPONSE : $direction);

		return is_string($direction) ? $this->$opt : null;
	}

	/**
	 * @param string $direction
	 * @param IQuarkIOProcessor $processor = null
	 *
	 * @return IQuarkIOProcessor
	 */
	public function Processor ($direction, IQuarkIOProcessor $processor = null) {
		return $this->_option('processor', $direction, $processor);
	}

	/**
	 * @param string $direction
	 * @param IQuarkIOFilter $filter = null
	 *
	 * @return IQuarkIOFilter
	 */
	public function Filter ($direction, IQuarkIOFilter $filter = null) {
		return $this->_option('filter', $direction, $filter);
	}

	/**
	 * @return mixed
	 */
	public function Thread () {
		Quark::CurrentEnvironment($this);

		$offset = Quark::Config()->WebHost()->path;

		$service = new QuarkService(
			substr($_SERVER['REQUEST_URI'], ($offset != '' ? (int)strpos($_SERVER['REQUEST_URI'], $offset) : 0) + strlen($offset)),
			$this->_processorRequest,
			$this->_processorResponse
		);
		
		$service->InputFilter($this->_filterRequest);
		$service->OutputFilter($this->_filterResponse);

		$uri = QuarkURI::FromURI(Quark::NormalizePath($_SERVER['REQUEST_URI'], false));
		$service->Input()->URI($uri);
		$service->Output()->URI($uri);

		$remote = QuarkURI::FromEndpoint($_SERVER['REMOTE_ADDR'], $_SERVER['REMOTE_PORT']);
		$service->Input()->Remote($remote);
		$service->Output()->Remote($remote);

		if ($service->Service() instanceof IQuarkServiceWithAccessControl)
			$service->Output()->Header(QuarkDTO::HEADER_ALLOW_ORIGIN, $service->Service()->AllowOrigin());

		$headers = array();

		$authType = '';
		$authBasic = 0;
		$authDigest = 0;

		if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
			$_SERVER['HTTP_AUTHORIZATION'] = QuarkDTO::HTTPBasicAuthorization($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
			$authBasic = 1;
		}

		foreach ($_SERVER as $name => $value) {
			$name = str_replace('CONTENT_', 'HTTP_CONTENT_', $name);
			$name = str_replace('PHP_AUTH_DIGEST', 'HTTP_AUTHORIZATION', $name, $authDigest);

			if ($authBasic != 0)
				$authType = 'Basic ';

			if ($authDigest != 0)
				$authType = 'Digest ';

			if (substr($name, 0, 5) == 'HTTP_')
				$headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = ($name == 'HTTP_AUTHORIZATION' ? $authType : '') . $value;
		}

		$service->Input()->Method(ucfirst(strtolower($_SERVER['REQUEST_METHOD'])));
		$service->Input()->Headers($headers);

		Quark::CurrentLanguage($service->Input()->ExpectedLanguage());

		$service->Input()->Merge((object)$_GET);
		$service->InitProcessors();

		$input = array_replace_recursive(
			$_GET,
			$_POST,
			QuarkFile::FromFiles($_FILES),
			(array)json_decode(json_encode($service->Input()->Processor()->Decode(file_get_contents('php://input'))), true),
			array()
		);

		$service->Input()->Merge((object)$input);

		if (isset($_POST[$service->Input()->Processor()->MimeType()]))
			$service->Input()->Merge($service->Input()->Processor()->Decode($_POST[$service->Input()->Processor()->MimeType()]));

		ob_start();

		echo QuarkHTTPServer::ServicePipeline($service, $input);

		$headers = $service->Output()->SerializeResponseHeadersToArray();

		foreach ($headers as $header)
			header($header);

		ob_end_flush();

		return true;
	}

	/**
	 * @param \Exception $exception
	 *
	 * @return mixed
	 */
	public function ExceptionHandler (\Exception $exception) {
		if ($exception instanceof QuarkArchException)
			return $this->_status($exception, $this->_statusServerError);

		if ($exception instanceof QuarkConnectionException)
			return $this->_status($exception, $this->_statusServerError);

		if ($exception instanceof QuarkHTTPException)
			return $this->_status($exception, $exception->Status(), $exception->log);

		if ($exception instanceof \Exception)
			return Quark::Log('Common exception: ' . $exception->getMessage() . "\r\n at " . $exception->getFile() . ':' . $exception->getLine(), Quark::LOG_FATAL);

		return true;
	}

	/**
	 * @param QuarkException $exception
	 * @param string $status
	 * @param string $log = ''
	 *
	 * @return bool|int
	 */
	private function _status ($exception, $status, $log = '') {
		ob_start();
		header($_SERVER['SERVER_PROTOCOL'] . ' ' . $status);
		ob_end_flush();

		return Quark::Log('[' . $_SERVER['REQUEST_URI'] . '] ' . (func_num_args() == 3 ? $log : $exception->message), $exception->lvl);
	}
}

/**
 * Class QuarkCLIEnvironment
 *
 * @package Quark
 */
class QuarkCLIEnvironment implements IQuarkEnvironment {
	/**
	 * @var QuarkTask[] $_tasks
	 */
	private $_tasks = array();

	/**
	 * @var string $_start = null
	 */
	private $_start = null;

	/**
	 * @var bool $_started = false
	 */
	private $_started = false;

	/**
	 * @var string $_name = 'CLI'
	 */
	private $_name = 'CLI';

	/**
	 * @param int   $argc = 0
	 * @param array $argv = []
	 */
	public function __construct ($argc = 0, $argv = []) {
		if (!Quark::CLI() || $argc > 1) return;

		$dir = new \RecursiveDirectoryIterator(Quark::Host());
		$fs = new \RecursiveIteratorIterator($dir);

		foreach ($fs as $file) {
			/**
			 * @var \FilesystemIterator $file
			 */

			if ($file->isDir() || !strstr($file->getFilename(), 'Service.php')) continue;

			$class = QuarkObject::ClassIn($file->getPathname());

			/**
			 * @var IQuarkService $service
			 */
			$service = new $class();

			if ($service instanceof IQuarkScheduledTask)
				$this->_tasks[] = new QuarkTask($service);

			unset($service);
		}
	}

	/**
	 * @return bool
	 */
	public function EnvironmentMultiple () { return false; }

	/**
	 * @return string
	 */
	public function EnvironmentName () {
		return $this->_name;
	}

	/**
	 * @param object $ini
	 *
	 * @return void
	 */
	public function EnvironmentOptions ($ini) {
		if (isset($ini->ApplicationStart))
			$this->ApplicationStart($ini->ApplicationStart);
	}

	/**
	 * @return bool
	 */
	public function UsageCriteria () {
		return Quark::CLI();
	}

	/**
	 * @param string $uri = ''
	 *
	 * @return string
	 */
	public function ApplicationStart ($uri = '') {
		if (func_num_args() != 0)
			$this->_start = $uri;

		return $this->_start;
	}

	/**
	 * @param int $argc
	 * @param array $argv
	 *
	 * @return void
	 *
	 * @throws QuarkArchException
	 * @throws QuarkHTTPException
	 */
	public function Thread ($argc = 0, $argv = []) {
		Quark::CurrentEnvironment($this);

		if ($argc > 1) {
			if ($argv[1] == QuarkTask::PREDEFINED) {
				if (!isset($argv[2]))
					throw new QuarkArchException('Predefined scenario not selected');

				$class = '\\Quark\\Scenarios\\' . str_replace('/', '\\', $argv[2]);

				if (!class_exists($class))
					throw new QuarkArchException('Unknown predefined scenario ' . $class);

				$service = new $class();
			}
			else $service = (new QuarkService('/' . $argv[1]))->Service();

			if (!($service instanceof IQuarkTask))
				throw new QuarkArchException('Class ' . get_class($service) . ' is not an IQuarkTask');

			/**
			 * @var QuarkService|IQuarkTask|QuarkCLIBehavior $service
			 */
			if (QuarkObject::Uses($service, 'Quark\\QuarkCLIBehavior'))
				$service->ShellInput($argv);

			$service->Task($argc, $argv);
		}
		else {
			if (!$this->_started && $this->_start !== null) {
				$this->_started = true;
				$service = (new QuarkService('/' . $this->_start))->Service();

				if (!($service instanceof IQuarkApplicationStartTask))
					throw new QuarkArchException('Class ' . get_class($service) . ' is not an IQuarkApplicationStartTask');

				$service->ApplicationStartTask($argc, $argv);
			}

			foreach ($this->_tasks as $task)
				$task->Launch($argc, $argv);
		}
	}

	/**
	 * @param \Exception $exception
	 *
	 * @return mixed
	 */
	public function ExceptionHandler (\Exception $exception) {
		return QuarkException::ExceptionHandler($exception);
	}
}

/**
 * Interface IQuarkNetworkTransport
 *
 * @package Quark
 */
interface IQuarkNetworkTransport {
	/**
	 * @param QuarkClient &$client
	 *
	 * @return mixed
	 */
	public function EventConnect(QuarkClient &$client);

	/**
	 * @param QuarkClient &$client
	 * @param string $data
	 *
	 * @return mixed
	 */
	public function EventData(QuarkClient &$client, $data);

	/**
	 * @param QuarkClient &$client
	 *
	 * @return mixed
	 */
	public function EventClose(QuarkClient &$client);

	/**
	 * @param string $data
	 *
	 * @return string
	 */
	public function Send($data);
}

/**
 * Interface IQuarkNetworkProtocol
 *
 * @package Quark
 */
interface IQuarkNetworkProtocol {
	/**
	 * @return IQuarkNetworkTransport
	 */
	public function Transport();

	/**
	 * @param QuarkClient $client
	 *
	 * @return bool
	 */
	public function OnConnect(QuarkClient $client);

	/**
	 * @param QuarkClient $client
	 * @param string $data
	 *
	 * @return mixed
	 */
	public function OnData(QuarkClient $client, $data);

	/**
	 * @param QuarkClient $client
	 *
	 * @return mixed
	 */
	public function OnClose(QuarkClient $client);
}

/**
 * Interface IQuarkExtension
 *
 * @package Quark
 */
interface IQuarkExtension { }

/**
 * Interface IQuarkExtensionConfig
 *
 * @package Quark
 */
interface IQuarkExtensionConfig extends IQuarkStackable {
	/**
	 * @return string
	 */
	public function ExtensionName();

	/**
	 * @param object $ini
	 *
	 * @return mixed
	 */
	public function ExtensionOptions($ini);

	/**
	 * @return IQuarkExtension
	 */
	public function ExtensionInstance();
}

/**
 * Interface IQuarkAuthorizableService
 *
 * @package Quark
 */
interface IQuarkAuthorizableService {
	/**
	 * @param QuarkDTO $request
	 *
	 * @return string
	 */
	public function AuthorizationProvider(QuarkDTO $request);
}

/**
 * Interface IQuarkAuthorizableServiceWithAuthentication
 *
 * @package Quark
 */
interface IQuarkAuthorizableServiceWithAuthentication extends IQuarkAuthorizableService {
	/**
	 * @param QuarkDTO $request
	 * @param QuarkSession $session
	 *
	 * @return bool|mixed
	 */
	public function AuthorizationCriteria(QuarkDTO $request, QuarkSession $session);

	/**
	 * @param QuarkDTO $request
	 * @param $criteria
	 *
	 * @return mixed
	 */
	public function AuthorizationFailed(QuarkDTO $request, $criteria);
}

/**
 * Interface IQuarkAuthorizableModelWithSessionKey
 *
 * @package Quark
 */
interface IQuarkAuthorizableModelWithSessionKey {
	/**
	 * @return string
	 */
	public function SessionKey();
}

/**
 * Interface IQuarkService
 *
 * @package Quark
 */
interface IQuarkService extends IQuarkPrimitive { }

/**
 * Interface IQuarkHTTPService
 *
 * @package Quark
 */
interface IQuarkHTTPService extends IQuarkService { }

/**
 * Interface IQuarkAnyService
 *
 * @package Quark
 */
interface IQuarkAnyService extends IQuarkHTTPService {
	/**
	 * @param QuarkDTO $request
	 * @param QuarkSession $session
	 *
	 * @return mixed
	 */
	public function Any(QuarkDTO $request, QuarkSession $session);
}

/**
 * Interface IQuarkGetService
 *
 * @package Quark
 */
interface IQuarkGetService extends IQuarkHTTPService {
	/**
	 * @param QuarkDTO $request
	 * @param QuarkSession $session
	 *
	 * @return mixed
	 */
	public function Get(QuarkDTO $request, QuarkSession $session);
}

/**
 * Interface IQuarkPostService
 *
 * @package Quark
 */
interface IQuarkPostService extends IQuarkHTTPService {
	/**
	 * @param QuarkDTO $request
	 * @param QuarkSession $session
	 *
	 * @return mixed
	 */
	public function Post(QuarkDTO $request, QuarkSession $session);
}

/**
 * Interface IQuarkServiceWithCustomProcessor
 *
 * @package Quark
 */
interface IQuarkServiceWithCustomProcessor {
	/**
	 * @param QuarkDTO $request
	 *
	 * @return IQuarkIOProcessor
	 */
	public function Processor(QuarkDTO $request);
}

/**
 * Interface IQuarkServiceWithCustomRequestProcessor
 *
 * @package Quark
 */
interface IQuarkServiceWithCustomRequestProcessor {
	/**
	 * @param QuarkDTO $request
	 *
	 * @return IQuarkIOProcessor
	 */
	public function RequestProcessor(QuarkDTO $request);
}

/**
 * Interface IQuarkServiceWithCustomResponseProcessor
 *
 * @package Quark
 */
interface IQuarkServiceWithCustomResponseProcessor {
	/**
	 * @param QuarkDTO $request
	 *
	 * @return IQuarkIOProcessor
	 */
	public function ResponseProcessor(QuarkDTO $request);
}

/**
 * Interface IQuarkPolymorphicService
 *
 * @package Quark
 */
interface IQuarkPolymorphicService {
	/**
	 * @param QuarkDTO $request
	 *
	 * @return IQuarkIOProcessor[]
	 */
	public function Processors(QuarkDTO $request);
}

/**
 * Interface IQuarkServiceWithFilter
 *
 * @package Quark
 */
interface IQuarkServiceWithFilter {
	/**
	 * @param QuarkDTO $dto
	 * @param QuarkSession $session
	 *
	 * @return IQuarkIOFilter
	 */
	public function Filter(QuarkDTO $dto, QuarkSession $session);
}

/**
 * Interface IQuarkServiceWithRequestFilter
 *
 * @package Quark
 */
interface IQuarkServiceWithRequestFilter {
	/**
	 * @param QuarkDTO $output
	 * @param QuarkSession $session
	 *
	 * @return IQuarkIOFilter
	 */
	public function RequestFilter(QuarkDTO $output, QuarkSession $session);
}

/**
 * Interface IQuarkServiceWithResponseFilter
 *
 * @package Quark
 */
interface IQuarkServiceWithResponseFilter {
	/**
	 * @param QuarkDTO $output
	 * @param QuarkSession $session
	 *
	 * @return IQuarkIOFilter
	 */
	public function ResponseFilter(QuarkDTO $output, QuarkSession $session);
}

/**
 * Interface IQuarkServiceWithRequestBackbone
 *
 * @package Quark
 */
interface IQuarkServiceWithRequestBackbone {
	/**
	 * @return array
	 */
	public function RequestBackbone();
}

/**
 * Interface IQuarkStrongService
 *
 * @package Quark
 */
interface IQuarkStrongService { }

/**
 * Interface IQuarkServiceWithAccessControl
 *
 * @package Quark
 */
interface IQuarkServiceWithAccessControl {
	/**
	 * @return string
	 */
	public function AllowOrigin();
}

/**
 * Interface IQuarkSignedService
 *
 * @package Quark
 */
interface IQuarkSignedService { }

/**
 * Interface IQuarkSignedAnyService
 *
 * @package Quark
 */
interface IQuarkSignedAnyService extends IQuarkSignedService {
	/**
	 * @param QuarkDTO $request
	 *
	 * @return mixed
	 */
	public function SignatureCheckFailedOnAny(QuarkDTO $request);
}

/**
 * Interface IQuarkSignedGetService
 *
 * @package Quark
 */
interface IQuarkSignedGetService extends IQuarkSignedService {
	/**
	 * @param QuarkDTO $request
	 *
	 * @return mixed
	 */
	public function SignatureCheckFailedOnGet(QuarkDTO $request);
}

/**
 * Interface IQuarkSignedPostService
 *
 * @package Quark
 */
interface IQuarkSignedPostService extends IQuarkSignedService {
	/**
	 * @param QuarkDTO $request
	 *
	 * @return mixed
	 */
	public function SignatureCheckFailedOnPost(QuarkDTO $request);
}

/**
 * Class QuarkTask
 *
 * @package Quark
 */
class QuarkTask {
	const PREDEFINED = '--quark';
	const QUEUE = 'tcp://127.0.0.1:25500';

	/**
	 * @var IQuarkService|IQuarkTask|IQuarkScheduledTask $_service
	 */
	private $_service = null;

	/**
	 * @var QuarkDate $_launched
	 */
	private $_launched = '';

	/**
	 * @var bool $_client = true
	 */
	private $_client = true;

	/**
	 * @var QuarkDTO $_io
	 */
	private $_io;

	/**
	 * @param IQuarkService $service
	 */
	public function __construct (IQuarkService $service = null) {
		$this->_client = func_num_args() != 0;
		$this->_io = new QuarkDTO(new QuarkJSONIOProcessor());

		if (!$this->_client) return;

		$this->_service = $service;
		$this->_launched = QuarkDate::Now();
	}

	/**
	 * @return string
	 */
	public function Name () {
		return preg_replace('#^Services\\\#Uis', '', get_class($this->_service));
	}

	/**
	 * @param int $argc
	 * @param array $argv
	 *
	 * @return bool
	 */
	public function Launch ($argc, $argv) {
		if ($this->_service instanceof IQuarkScheduledTask && !$this->_service->LaunchCriteria($this->_launched)) return true;

		$out = true;

		try {
			$this->_service->Task($argc, $argv);
		}
		catch (\Exception $e) {
			$out = QuarkException::ExceptionHandler($e);
		}

		$this->_launched = QuarkDate::Now();

		return $out;
	}

	/**
	 * @param array $args = []
	 * @param string $queue = self::QUEUE
	 *
	 * @return mixed
	 *
	 * @throws QuarkArchException
	 */
	public function AsyncLaunch ($args = [], $queue = self::QUEUE) {
		if (!($this->_service instanceof IQuarkTask))
			throw new QuarkArchException('Trying to async launch service ' . ($this->_service ? get_class($this->_service) : 'null') . ' which is not an IQuarkTask');

		array_unshift($args, Quark::EntryPoint(), $this->Name());

		$out = $this->_service instanceof IQuarkAsyncTask
			? $this->_service->OnLaunch(sizeof($args), $args)
			: null;

		$this->_io->Data($args);

		if (func_num_args() < 2 && $queue == self::QUEUE)
			Quark::Config()->AsyncQueue($queue, QuarkURI::FromURI($queue), $this->Transport());
		
		$uri = Quark::Config()->AsyncQueue($queue);

		if (!($uri->Key() instanceof QuarkURI))
			throw new QuarkArchException('Trying to connect to async queue ' . $queue . ' which is not set');
		
		$protocol = $uri->Value();
		$client = new QuarkClient($uri->Key(), ($protocol ? $protocol->Transport() : $this->Transport()), null, 30);

		$client->On(QuarkClient::EVENT_CONNECT, function (QuarkClient $client) {
			$this->_io->Data(array(
				'task' => get_class($this->_service),
				'args' => $this->_io->Data()
			));

			$out = $this->_io->SerializeRequestBody();

			return $client->Send($out) && $client->Close();
		});

		if (!$client->Connect()) return false;

		return $out;
	}

	/**
	 * @param string $queue = self::QUEUE
	 * @param int $tick = QuarkThreadSet::TICK (microseconds)
	 *
	 * @return bool
	 */
	public static function AsyncQueue ($queue = self::QUEUE, $tick = QuarkThreadSet::TICK) {
		$uri = Quark::Config()->AsyncQueue($queue);

		if (!($uri->Key() instanceof QuarkURI)) return false;

		$protocol = $uri->Value();

		$task = new QuarkTask();
		$server = new QuarkServer($uri->Key(), $protocol ? $protocol->Transport() : $task->Transport());

		/** @noinspection PhpUnusedParameterInspection
		 */
		$server->On(QuarkClient::EVENT_DATA, function (QuarkClient $client, $data) use (&$task) {
			$json = $task->_io->Processor()->Decode($data);

			if (!isset($json->task) || !isset($json->args)) return;

			$args = (array)$json->args;
			$class = $json->task;
			$service = new $class();

			if ($service instanceof IQuarkTask)
				$service->Task(sizeof($args), $args);

			unset($service, $class, $args, $json, $task);
		});

		if (!$server->Bind()) return false;

		QuarkThreadSet::Queue(function () use ($server) {
			return $server->Pipe();
		}, $tick);

		return true;
	}

	/**
	 * @return IQuarkNetworkTransport
	 */
	public function Transport () {
		return new QuarkTCPNetworkTransport(array($this->_io->Processor(), 'Batch'));
	}
}

/**
 * Interface IQuarkTask
 *
 * @package Quark
 */
interface IQuarkTask extends IQuarkService {
	/**
	 * @param int $argc
	 * @param array $argv
	 *
	 * @return mixed
	 */
	public function Task($argc, $argv);
}

/**
 * Interface IQuarkScheduledTask
 *
 * @package Quark
 */
interface IQuarkScheduledTask extends IQuarkTask {
	/**
	 * @param QuarkDate $previous
	 *
	 * @return bool
	 */
	public function LaunchCriteria(QuarkDate $previous);
}

/**
 * Interface IQuarkAsyncTask
 *
 * @package Quark
 */
interface IQuarkAsyncTask extends IQuarkTask {
	/**
	 * @param int $argc
	 * @param array $argv
	 *
	 * @return mixed
	 */
	public function OnLaunch($argc, $argv);
}

/**
 * Interface IQuarkApplicationStartTask
 *
 * @package Quark
 */
interface IQuarkApplicationStartTask extends IQuarkService {
	/**
	 * @param int $argc
	 * @param array $argv
	 *
	 * @return mixed
	 */
	public function ApplicationStartTask($argc, $argv);
}

/**
 * Class QuarkThreadSet
 *
 * @package Quark
 */
class QuarkThreadSet {
	const TICK = 10000;

	const EVENT_BEFORE_INVOKE = 'invoke.before';
	const EVENT_AFTER_INVOKE = 'invoke.after';

	use QuarkEvent;

	/**
	 * @var IQuarkThread[] $_threads
	 */
	private $_threads;

	/**
	 * @var array $_args = []
	 */
	private $_args = array();

	/**
	 * ThreadSet constructor
	 */
	public function __construct () {
		$this->_args = func_get_args();
	}

	/**
	 * @param IQuarkThread $thread
	 *
	 * @return QuarkThreadSet
	 */
	public function Thread (IQuarkThread $thread) {
		$this->_threads[] = $thread;

		return $this;
	}

	/**
	 * @param IQuarkThread[] $threads
	 *
	 * @return IQuarkThread[]
	 */
	public function Threads ($threads = []) {
		if (func_num_args() != 0 && is_array($threads))
			$this->_threads = $threads;

		return $this->_threads;
	}

	/**
	 * @return bool|mixed
	 */
	public function Invoke () {
		$run = true;

		$this->Trigger(self::EVENT_BEFORE_INVOKE);

		foreach ($this->_threads as &$thread) {
			if (!($thread instanceof IQuarkThread) || !$thread->UsageCriteria()) continue;

			try {
				$run_tmp = call_user_func_array(array($thread, 'Thread'), $this->_args);
				$run_tmp = $run_tmp === null || $run_tmp;
			}
			catch (\Exception $e) {
				$run_tmp = $thread->ExceptionHandler($e);
			}

			$run &= $run_tmp;
		}

		unset($thread);

		$this->Trigger(self::EVENT_AFTER_INVOKE);

		return (bool)$run;
	}

	/**
	 * @param int $sleep = self::TICK (microseconds)
	 */
	public function Pipeline ($sleep = self::TICK) {
		self::Queue(function () { return $this->Invoke(); }, $sleep);
	}

	/**
	 * @param callable $pipe
	 * @param int $sleep = self::TICK (microseconds)
	 */
	public static function Queue (callable $pipe, $sleep = self::TICK) {
		$run = true;

		while ($run) {
			$result = $pipe();

			$run = $result !== false;

			usleep($sleep);
		}
	}
}

/**
 * Class QuarkTimer
 *
 * @package Quark
 */
class QuarkTimer {
	const ONE_SECOND = 1;
	const ONE_MINUTE = 60;
	const ONE_HOUR = 3600;

	/**
	 * @var QuarkTimer[] $_timers
	 */
	private static $_timers = array();

	/**
	 * @var int $_time
	 */
	private $_time;

	/**
	 * @var callable $_callback
	 */
	private $_callback;

	/**
	 * @var QuarkDate $_last
	 */
	private $_last;

	/**
	 * @var string $_id
	 */
	private $_id;

	/**
	 * @var null $_null = null
	 */
	private static $_null = null;

	/**
	 * @param int $time (seconds)
	 * @param callable(QuarkTimer, ..$) $callback
	 * @param int $offset = 0
	 * @param string $id = ''
	 */
	public function __construct ($time, callable $callback, $offset = 0, $id = '') {
		$this->_time = $time > $offset ? $time - $offset : $time;
		$this->_callback = $callback;
		$this->_last = QuarkDate::Now();
		$this->_id = func_num_args() == 4 ? $id : Quark::GuID();

		self::$_timers[] = $this;
	}

	/**
	 * @param int $time = 0
	 *
	 * @return int
	 */
	public function Time ($time = 0) {
		if (func_num_args() != 0)
			$this->_time = $time;

		return $this->_time;
	}

	/**
	 * @param callable $callback = null
	 *
	 * @return callable
	 */
	public function Callback (callable $callback = null) {
		if (func_num_args() != 0)
			$this->_callback = $callback;

		return $this->_callback;
	}

	/**
	 * @return QuarkDate
	 */
	public function Last () {
		return $this->_last;
	}

	/**
	 * @return string
	 */
	public function ID () {
		return $this->_id;
	}

	/**
	 * Invoke timer callback
	 */
	public function Invoke () {
		$now = QuarkDate::Now();

		if (!$this->_last->Later($now, $this->_time)) return;

		$this->_last = $now;

		call_user_func_array($this->_callback, array(&$this) + func_get_args());
	}

	/**
	 * Destroy timer
	 */
	public function Destroy () {
		foreach (self::$_timers as $i => &$timer)
			if ($timer->_id == $this->_id)
				unset(self::$_timers[$i]);
	}

	/**
	 * @return QuarkTimer[]
	 */
	public static function Timers () {
		return self::$_timers;
	}

	/**
	 * @param string $id
	 *
	 * @return QuarkTimer
	 */
	public static function &Get ($id) {
		foreach (self::$_timers as $i => &$timer)
			if ($timer->_id == $id) return $timer;

		return self::$_null;
	}
}

/**
 * Interface IQuarkThread
 *
 * @package Quark
 */
interface IQuarkThread {
	/**
	 * @return bool
	 */
	public function UsageCriteria();

	/**
	 * @return mixed
	 */
	public function Thread();

	/**
	 * @param \Exception $exception
	 *
	 * @return mixed
	 */
	public function ExceptionHandler(\Exception $exception);
}

/**
 * Interface IQuarkStream
 *
 * @package Quark
 */
interface IQuarkStream extends IQuarkService {
	/**
	 * @param QuarkDTO $request
	 * @param QuarkSession $session
	 *
	 * @return mixed
	 */
	public function Stream(QuarkDTO $request, QuarkSession $session);
}

/**
 * Interface IQuarkStreamNetwork
 *
 * @package Quark
 */
interface IQuarkStreamNetwork extends IQuarkService {
	/**
	 * @param QuarkDTO $request
	 *
	 * @return mixed
	 */
	public function StreamNetwork(QuarkDTO $request);
}

/**
 * Interface IQuarkStreamConnect
 *
 * @package Quark
 */
interface IQuarkStreamConnect extends IQuarkService {
	/**
	 * @param QuarkSession $session
	 *
	 * @return mixed
	 */
	public function StreamConnect(QuarkSession $session);
}

/**
 * Interface IQuarkStreamClose
 *
 * @package Quark
 */
interface IQuarkStreamClose extends IQuarkService {
	/**
	 * @param QuarkSession $session
	 *
	 * @return mixed
	 */
	public function StreamClose(QuarkSession $session);
}

/**
 * Interface IQuarkStreamUnknown
 *
 * @package Quark
 */
interface IQuarkStreamUnknown extends IQuarkService {
	/**
	 * @param QuarkDTO $request
	 * @param QuarkSession $session
	 *
	 * @return mixed
	 */
	public function StreamUnknown(QuarkDTO $request, QuarkSession $session);
}

/**
 * Interface IQuarkControllerStreamConnect
 *
 * @package Quark
 */
interface IQuarkControllerStreamConnect extends IQuarkService {
	public function ControllerStreamConnect();
}

/**
 * Interface IQuarkControllerStream
 *
 * @package Quark
 */
interface IQuarkControllerStream extends IQuarkService {
	/**
	 * @param QuarkDTO $request
	 * @param QuarkCluster $cluster
	 *
	 * @return mixed
	 */
	public function ControllerStream(QuarkDTO $request, QuarkCluster $cluster);
}

/**
 * Interface IQuarkControllerStreamClose
 *
 * @package Quark
 */
interface IQuarkControllerStreamClose extends IQuarkService {
	public function ControllerStreamClose();
}

/**
 * Trait QuarkServiceBehavior
 *
 * @package Quark
 */
trait QuarkServiceBehavior {
	use QuarkContainerBehavior;

	/** @noinspection PhpUnusedPrivateMethodInspection
	 * @return QuarkService
	 */
	private function _envelope () {
		return new QuarkService($this);
	}

	/**
	 * @param IQuarkService $service = null
	 *
	 * @return string
	 */
	public function URL (IQuarkService $service = null) {
		return $this->__call('URL', func_get_args());
	}

	/**
	 * @return QuarkDTO
	 */
	public function Input () {
		return $this->__call('Input', func_get_args());
	}

	/**
	 * @return QuarkSession
	 */
	public function Session () {
		return $this->__call('Session', func_get_args());
	}

	/**
	 * @param string $url = ''
	 * @param string $method = QuarkDTO::METHOD_GET
	 * @param QuarkDTO|object|array $input = []
	 * @param QuarkSession $session = null
	 *
	 * @return mixed
	 */
	public function InvokeURL ($url = '', $method = QuarkDTO::METHOD_GET, $input = [], QuarkSession $session = null) {
		$num = func_num_args();

		$service = new QuarkService($url);
		$service->Input()->Merge($num < 3 ? $this->Input() : $input);
		$service->Session($num < 4 ? $this->Session() : $session);
		$service->Input()->URI(QuarkURI::FromURI($url));
		
		$output = $service->Invoke($method, $input !== null ? array($service->Input()) : array(), true);

		unset($service);

		return $output;
	}
}

/**
 * Trait QuarkStreamBehavior
 *
 * @package Quark
 */
trait QuarkStreamBehavior {
	use QuarkServiceBehavior;

	/**
	 * @param QuarkDTO|object|array $data
	 * @param string $url = ''
	 *
	 * @return bool
	 */
	public function Broadcast ($data, $url = '') {
		$env = Quark::CurrentEnvironment();

		if ($env instanceof QuarkStreamEnvironment) {
			$session = $this->Session();
			$clients = $env->Cluster()->Server()->Clients();

			foreach ($clients as $i => &$client) {
				$connection = $session->Connection();

				if ($connection && $client->ID() == $connection->ID())
					$client->Session($session->ID());
			}

			unset($connection, $clients, $session);
			
			$out = $env->BroadcastNetwork(func_num_args() == 2 ? $url : $this->URL(), $data);
		}
		else $out = QuarkStreamEnvironment::ControllerCommand(
			QuarkStreamEnvironment::COMMAND_BROADCAST,
			QuarkStreamEnvironment::Payload(QuarkStreamEnvironment::PACKAGE_REQUEST, $url, $data)
		);

		unset($env, $data);

		return $out;
	}

	/**
	 * @param QuarkDTO|object|array $data
	 * @param IQuarkStreamNetwork $service = null
	 *
	 * @return bool
	 */
	public function BroadcastService ($data, IQuarkStreamNetwork $service = null) {
		return $this->Broadcast($data, $this->URL($service));
	}

	/**
	 * @param callable(QuarkSession $client) $sender = null
	 * @param bool $auth = true
	 *
	 * @return bool
	 *
	 * @throws QuarkArchException
	 */
	public function Event (callable $sender = null, $auth = true) {
		$env = Quark::CurrentEnvironment();

		if ($env instanceof QuarkStreamEnvironment) return $env->BroadcastLocal($this->URL(), $sender, $auth);
		else throw new QuarkArchException('QuarkStreamBehavior: the `Event` method cannot be called in a non-stream environment');
	}

	/**
	 * @param string $channel = ''
	 * @param callable(QuarkSession $client) $sender = null
	 * @param bool $auth = true
	 *
	 * @return bool
	 *
	 * @throws QuarkArchException
	 */
	public function ChannelEvent ($channel = '', callable $sender = null, $auth = true) {
		$env = Quark::CurrentEnvironment();

		if ($env instanceof QuarkStreamEnvironment) return $env->BroadcastLocal($this->URL(), $sender, $auth, $channel);
		else throw new QuarkArchException('QuarkStreamBehavior: the `Event` method cannot be called in a non-stream environment');
	}

	/**
	 * @param string $url = ''
	 * @param QuarkDTO|object|array $input = []
	 * @param QuarkSession $session = null
	 *
	 * @return mixed
	 */
	public function InvokeStream ($url = '', $input = [], QuarkSession $session = null) {
		$num = func_num_args();
		
		return $this->InvokeURL(
			$url,
			'Stream',
			$num < 3 ? $this->Input() : $input,
			$num < 4 ? $this->Session() : $session
		);
	}

	/**
	 * @return QuarkCluster
	 */
	public function &Cluster () {
		$env = Quark::CurrentEnvironment();

		if ($env instanceof QuarkStreamEnvironment)
			return $env->Cluster();

		return $this->_null;
	}
}

/**
 * Trait QuarkCLIBehavior
 *
 * @package Quark
 */
trait QuarkCLIBehavior {
	use QuarkServiceBehavior;

	/**
	 * @var array $_shellInput = []
	 */
	private $_shellInput = array();

	/**
	 * @var array $_shellOutput = []
	 */
	private $_shellOutput = array();

	/**
	 * @param string $command = ''
	 * @param string[] &$output = []
	 * @param int &$status = 0
	 *
	 * @return bool
	 */
	public function Shell ($command = '', &$output = [], &$status = 0) {
		if (strlen($command) == 0) return false;

		exec($command, $output, $status);
		$this->_shellOutput = $output;

		return $status == 0;
	}

	/**
	 * @param array $argv = []
	 *
	 * @return array
	 */
	public function ShellInput ($argv = []) {
		if (func_num_args() != 0)
			$this->_shellInput = $argv;

		return $this->_shellInput;
	}

	/**
	 * @return array
	 */
	public function ShellOutput () {
		return $this->_shellOutput;
	}

	/**
	 * @param int $id = 0
	 *
	 * @return mixed
	 */
	public function Arg ($id = 0) {
		return isset($this->_shellInput[$id]) ? $this->_shellInput[$id] : null;
	}

	/**
	 * @return array
	 */
	public function ServiceArgs () {
		if (!isset($this->_shellInput[0]))
			return $this->_shellInput;

		$args = array_slice($this->_shellInput, 1);

		if ($args[0] == QuarkTask::PREDEFINED)
			$args = array_slice($args, 1);

		$args = array_slice($args, 1);

		return $args;
	}

	/**
	 * @param int $id = 0
	 *
	 * @return mixed
	 */
	public function ServiceArg ($id = 0) {
		$args = $this->ServiceArgs();

		return isset($args[$id]) ? $args[$id] : null;
	}

	/**
	 * @param string $arg = ''
	 * @param string $flag = ''
	 * @param string $alias = ''
	 * @param string $prefixFlag = '--'
	 * @param string $prefixAlias = '-'
	 *
	 * @return bool
	 */
	private function _isFlag ($arg = '', $flag = '', $alias = '', $prefixFlag = '--', $prefixAlias = '-') {
		$flag = $prefixFlag . $flag;
		$alias = $prefixAlias . $alias;

		return $arg == $flag || ($alias != '' && $arg == $alias);
	}

	/**
	 * @param string $flag = ''
	 * @param string $alias = ''
	 * @param string $prefixFlag = '--'
	 * @param string $prefixAlias = '-'
	 *
	 * @return bool
	 */
	public function HasFlag ($flag = '', $alias = '', $prefixFlag = '--', $prefixAlias = '-') {
		foreach ($this->_shellInput as $arg)
			if ($this->_isFlag($arg, $flag, $alias, $prefixFlag, $prefixAlias)) return true;
		
		return false;
	}

	/**
	 * @param string $flag = ''
	 * @param string $alias = ''
	 * @param string $prefixFlag = '--'
	 * @param string $prefixAlias = '-'
	 *
	 * @return mixed
	 */
	public function Flag ($flag = '', $alias = '', $prefixFlag = '--', $prefixAlias = '-') {
		$i = 0;
		$size = sizeof($this->_shellInput);

		while ($i < $size) {
			$arg = $this->_shellInput[$i];
			$next = isset($this->_shellInput[$i + 1]) ? $this->_shellInput[$i + 1] : null;

			$ok = $this->_isFlag($arg, $flag, $alias, $prefixFlag, $prefixAlias)
				&& $next !== null
				&& !$this->_isFlag($next, $flag, $alias, $prefixFlag, $prefixAlias);

			if ($ok) return $next;

			$i++;
		}

		return null;
	}

	/**
	 * @param IQuarkAsyncTask $task
	 * @param array $args = []
	 * @param string $queue = QuarkTask::QUEUE
	 *
	 * @return mixed
	 *
	 * @throws QuarkArchException
	 */
	public function AsyncTask (IQuarkAsyncTask $task, $args = [], $queue = QuarkTask::QUEUE) {
		$cmd = new QuarkTask($task);

		return $cmd->AsyncLaunch($args, $queue);
	}
}

/**
 * Class QuarkService
 *
 * @package Quark
 */
class QuarkService implements IQuarkContainer {
	/**
	 * @var IQuarkService|IQuarkAuthorizableService|IQuarkServiceWithAccessControl|IQuarkPolymorphicService $_service
	 */
	private $_service;

	/**
	 * @var QuarkDTO $_input
	 */
	private $_input;

	/**
	 * @var QuarkDTO $_output
	 */
	private $_output;

	/**
	 * @var QuarkSession $_session
	 */
	private $_session;

	/**
	 * @var IQuarkIOFilter $_inputFilter
	 */
	private $_inputFilter;

	/**
	 * @var IQuarkIOFilter $_outputFilter
	 */
	private $_outputFilter;

	/**
	 * @param string $service
	 *
	 * @return string
	 */
	private static function _bundle ($service) {
		return Quark::NormalizePath(Quark::Host() . '/' . Quark::Config()->Location(QuarkConfig::SERVICES) . '/' . $service . 'Service.php', false);
	}

	/**
	 * @param string $uri = ''
	 *
	 * @return IQuarkService
	 *
	 * @throws QuarkArchException
	 * @throws QuarkHTTPException
	 */
	public static function Resolve ($uri = '') {
		if ($uri == 'index.php') $uri = '';

		$route = QuarkURI::FromURI(Quark::NormalizePath($uri), false);
		$path = QuarkURI::ParseRoute($route->path);

		$buffer = array();

		foreach ($path as $item)
			if (strlen(trim($item)) != 0)
				$buffer[] = ucfirst(trim($item));

		$route = $buffer;
		unset($buffer);
		$length = sizeof($route);
		$service = $length == 0 ? 'Index' : implode('/', $route);
		$path = self::_bundle($service);

		while ($length > 0) {
			if (is_file($path)) break;

			$index = self::_bundle($service . '\\Index');

			if (is_file($index)) {
				$service .= '\\Index';
				$path = $index;

				break;
			}

			$length--;
			$service = preg_replace('#\/' . preg_quote(ucfirst(trim($route[$length]))) . '$#Uis', '', $service);
			$path = self::_bundle($service);
		}

		if (Quark::Config()->AllowIndexFallback() && !file_exists($path)) {
			$service = 'Index';
			$path = self::_bundle($service);
		}

		if (!file_exists($path))
			throw QuarkHTTPException::ForStatus(QuarkDTO::STATUS_404_NOT_FOUND, 'Unknown service file ' . $path);

		$class = str_replace('/', '\\', '/Services/' . $service . 'Service');
		$bundle = new $class();

		if (!($bundle instanceof IQuarkService))
			throw new QuarkArchException('Class ' . $class . ' is not an IQuarkService');

		unset($class, $length, $path, $service, $index, $route);

		return $bundle;
	}

	/**
	 * @param IQuarkService|string $uri
	 * @param IQuarkIOProcessor $input = null
	 * @param IQuarkIOProcessor $output = null
	 *
	 * @throws QuarkArchException
	 * @throws QuarkHTTPException
	 */
	public function __construct ($uri, IQuarkIOProcessor $input = null, IQuarkIOProcessor $output = null) {
		if ($uri instanceof IQuarkService) {
			$this->_service = $uri;
			$class = get_class($this->_service);
			$uri = substr(substr($class, 8), 0, -7);
		}
		else $this->_service = self::Resolve($uri);

		$this->_input = new QuarkDTO();
		$this->_input->Processor($input ? $input : new QuarkFormIOProcessor());
		$this->_output = new QuarkDTO();
		$this->_output->Processor($output ? $output : new QuarkHTMLIOProcessor());
		$this->_input->URI(QuarkURI::FromURI(Quark::NormalizePath($uri, false), false));
		
		Quark::Container($this);
	}

	/**
	 * @return QuarkService
	 */
	public function InitProcessors () {
		if ($this->_service instanceof IQuarkServiceWithCustomProcessor) {
			$processor = $this->_service->Processor($this->_input);

			$this->_input->Processor($processor);
			$this->_output->Processor($processor);
		}

		if ($this->_service instanceof IQuarkServiceWithCustomRequestProcessor)
			$this->_input->Processor($this->_service->RequestProcessor($this->_input));

		if ($this->_service instanceof IQuarkServiceWithCustomResponseProcessor)
			$this->_output->Processor($this->_service->ResponseProcessor($this->_input));

		return $this;
	}

	/**
	 * @return QuarkService
	 */
	public function InitFilters () {
		if ($this->_service instanceof IQuarkServiceWithFilter) {
			$filter = $this->_service->Filter($this->_input, $this->_session);

			$this->_inputFilter = $filter;
			$this->_outputFilter = $filter;
		}

		if ($this->_service instanceof IQuarkServiceWithRequestFilter)
			$this->_inputFilter = $this->_service->RequestFilter($this->_input, $this->_session);

		if ($this->_service instanceof IQuarkServiceWithResponseFilter)
			$this->_outputFilter = $this->_service->ResponseFilter($this->_input, $this->_session);

		return $this;
	}

	/**
	 * @param IQuarkPrimitive $primitive = null
	 *
	 * @return IQuarkPrimitive
	 */
	public function &Primitive (IQuarkPrimitive $primitive = null) {
		if (func_num_args() != 0)
			$this->_service = $primitive;

		return $this->_service;
	}

	/**
	 * @param IQuarkService|IQuarkServiceWithAccessControl|IQuarkPolymorphicService $service = null
	 *
	 * @return IQuarkService|IQuarkServiceWithAccessControl|IQuarkPolymorphicService
	 */
	public function &Service (IQuarkService $service = null) {
		if (func_num_args() != 0)
			$this->_service = $service;

		return $this->_service;
	}

	/**
	 * @param QuarkDTO $input = null
	 *
	 * @return QuarkDTO
	 */
	public function &Input (QuarkDTO $input = null) {
		if (func_num_args() != 0)
			$this->_input = $input;

		return $this->_input;
	}

	/**
	 * @param QuarkDTO $output = null
	 *
	 * @return QuarkDTO
	 */
	public function &Output (QuarkDTO $output = null) {
		if (func_num_args() != 0)
			$this->_output = $output;

		return $this->_output;
	}

	/**
	 * @param QuarkSession $session = null
	 *
	 * @return QuarkSession
	 */
	public function &Session (QuarkSession $session = null) {
		if (func_num_args() != 0)
			$this->_session = $session;
		
		return $this->_session;
	}

	/**
	 * @param IQuarkIOFilter $filter = null
	 *
	 * @return IQuarkIOFilter
	 */
	public function InputFilter (IQuarkIOFilter $filter = null) {
		if (func_num_args() != 0)
			$this->_inputFilter = $filter;
		
		return $this->_inputFilter;
	}

	/**
	 * @param IQuarkIOFilter $filter = null
	 *
	 * @return IQuarkIOFilter
	 */
	public function OutputFilter (IQuarkIOFilter $filter = null) {
		if (func_num_args() != 0)
			$this->_outputFilter = $filter;

		return $this->_outputFilter;
	}

	/**
	 * @param IQuarkService $service = null
	 *
	 * @return string
	 */
	public function URL (IQuarkService $service = null) {
		return $service ? self::URLOf($service) : $this->_input->URI()->Query();
	}

	/**
	 * @param IQuarkService $service
	 *
	 * @return string
	 */
	public static function URLOf (IQuarkService $service) {
		return Quark::NormalizePath(str_replace('Service', '', str_replace('Services', '', get_class($service))), false);
	}

	/**
	 * @param bool $checkSignature = false
	 * @param QuarkClient $connection = null
	 *
	 * @return bool
	 *
	 * @throws QuarkArchException
	 */
	public function Authorize ($checkSignature = false, QuarkClient &$connection = null) {
		if (!($this->_service instanceof IQuarkAuthorizableService)) return true;

		$service = get_class($this->_service);
		$provider = $this->_service->AuthorizationProvider($this->_input);

		if ($provider == null)
			throw new QuarkArchException('Service ' . $service . ' does not specified AuthorizationProvider');

		$this->_session = QuarkSession::Init($provider, $this->_input, $connection);

		if (!($this->_service instanceof IQuarkAuthorizableServiceWithAuthentication) && $this->_session != null) return true;

		$criteria = $this->_service->AuthorizationCriteria($this->_input, $this->_session);

		$this->_output->Merge($this->_session->Output(), false);

		if ($criteria !== true) {
			$this->_output->Merge($this->_service->AuthorizationFailed($this->_input, $criteria));

			return false;
		}

		if (!$checkSignature) return true;
		if (!($this->_service instanceof IQuarkSignedService)) return true;

		$method = ucfirst(strtolower($this->_input->Method()));
		$action = 'SignatureCheckFailedOn' . $method;

		if (!method_exists($this->_service, $action)) return true;
		
		$sign = $this->_session->Signature();

		if ($sign != '' && $this->_input->Signature() == $sign) return true;

		$this->_output->Merge($this->_service->$action($this->_input));

		return false;
	}

	/**
	 * @param string $method
	 * @param array $args = []
	 * @param bool $session = false
	 *
	 * @return mixed
	 *
	 * @throws QuarkArchException
	 */
	public function Invoke ($method, $args = [], $session = false) {
		$empty = $this->_session == null;

		if ($empty)
			$this->_session = new QuarkSession();

		if ($session)
			$args[] = &$this->_session;

		if (!method_exists($this->_service, $method))
			throw new QuarkArchException('Method ' . $method . ' is not allowed for service ' . get_class($this->_service));
		
		$this->InitFilters();

		$morph = $this->_service instanceof IQuarkPolymorphicService;
		$selected = null;

		if ($morph) {
			$processors = $this->_service->Processors($this->_input);

			if (is_array($processors))
				foreach ($processors as $processor) {
					if (!($processor instanceof IQuarkIOProcessor)) continue;
					if ($processor->MimeType() == $this->_input->ExpectedType())
						$selected = $processor;
				}
		}
		
		$this->_filterInput();
		$output = call_user_func_array(array(&$this->_service, $method), $args);

		if ($morph)
			$this->_output->Processor($selected);

		$this->_output->Merge($morph && $selected != null && $output instanceof QuarkView
			? $output->ExtractVars()
			: $output
		);
		$this->_filterOutput();

		if ($this->_service instanceof IQuarkAuthorizableService && !$empty)
			$this->_output->Merge($this->_session->Output(), false);

		return $output;
	}

	/**
	 * @return QuarkService
	 */
	private function _filterInput () {
		$input = null;
		$filter = $this->_inputFilter;
		
		if ($filter instanceof IQuarkIOFilter)
			$input = $filter->FilterInput($this->_input, $this->_session);

		if ($input instanceof QuarkDTO)
			$this->_input = $input;

		return $this;
	}

	/**
	 * @return QuarkService
	 */
	private function _filterOutput () {
		$output = null;
		$filter = $this->_outputFilter;

		if ($filter instanceof IQuarkIOFilter)
			$output = $filter->FilterInput($this->_output, $this->_session);

		if ($output instanceof QuarkDTO)
			$this->_output = $output;

		return $this;
	}

	/**
	 * reset service
	 */
	public function __destruct () {
		unset($this->_service);
		unset($this->_session);
		unset($this->_input);
		unset($this->_output);
		unset($this->_filterInput);
		unset($this->_filterOutput);
	}
}

/**
 * Trait QuarkContainerBehavior
 *
 * @package Quark
 */
trait QuarkContainerBehavior {
	/**
	 * @var null $_null = null
	 */
	protected $_null = null;

	/** @noinspection PhpUnusedPrivateMethodInspection
	 * @return IQuarkContainer
	 */
	private function _envelope () {
		return null;
	}

	/**
	 * @param $method
	 * @param $args
	 *
	 * @return mixed
	 */
	public function __call ($method, $args) {
		$container = $this->Container();

		return method_exists($container, $method)
			? call_user_func_array(array($container, $method), $args)
			: null;
	}

	/**
	 * @return string
	 */
	public function ObjectID () {
		return spl_object_hash($this);
	}

	/**
	 * @return IQuarkContainer
	 */
	public function Container () {
		/**
		 * @var IQuarkPrimitive|QuarkContainerBehavior $this
		 */
		$container = Quark::ContainerOf($this->ObjectID());

		if ($container == null)
			$container = $this->_envelope();

		$container->Primitive($this);
		
		return $container;
	}

	/**
	 * @return IQuarkEnvironment
	 */
	public function &CurrentEnvironment () {
		return Quark::CurrentEnvironment();
	}

	/**
	 * @param IQuarkEnvironment $provider
	 *
	 * @return bool
	 */
	public function EnvironmentIs (IQuarkEnvironment $provider) {
		return $this->CurrentEnvironment() instanceof $provider;
	}

	/**
	 * @return bool
	 */
	public function EnvironmentIsFPM () {
		return $this->CurrentEnvironment() instanceof QuarkFPMEnvironment;
	}

	/**
	 * @return bool
	 */
	public function EnvironmentIsStream () {
		return $this->CurrentEnvironment() instanceof QuarkStreamEnvironment;
	}

	/**
	 * @return bool
	 */
	public function EnvironmentIsCLI () {
		return $this->CurrentEnvironment() instanceof QuarkCLIEnvironment;
	}

	/**
	 * @param string $key = ''
	 * @param string $language = QuarkLanguage::ANY
	 *
	 * @return string
	 */
	public function LocalizationOf ($key = '', $language = QuarkLanguage::ANY) {
		return Quark::Config()->LocalizationOf($key, $language);
	}

	/**
	 * @param string $key = ''
	 *
	 * @return object
	 */
	public function LocalizationDictionaryOf ($key = '') {
		return Quark::Config()->LocalizationDictionaryOf($key);
	}

	/**
	 * @param string $key = ''
	 * @param bool $strict = false
	 *
	 * @return string
	 */
	public function CurrentLocalizationOf ($key = '', $strict = false) {
		return Quark::Config()->CurrentLocalizationOf($key, $strict);
	}

	/**
	 * @return string
	 */
	public function CurrentLanguage () {
		return Quark::CurrentLanguage();
	}

	/**
	 * @return QuarkModel|IQuarkApplicationSettingsModel
	 */
	public function ApplicationSettings () {
		return Quark::Config()->ApplicationSettings();
	}

	/**
	 * @param string $key = ''
	 *
	 * @return mixed
	 */
	public function LocalSettings ($key = '') {
		return Quark::Config()->LocalSettings($key);
	}

	/**
	 * @param string $name = ''
	 *
	 * @return QuarkURI
	 */
	public function StreamConnectionURI ($name = '') {
		return QuarkStreamEnvironment::ConnectionURI($name);
	}

	/**
	 * @param string $value
	 *
	 * @return mixed
	 */
	public function ConstByValue ($value) {
		return QuarkObject::ClassConstByValue(get_class($this), $value);
	}

	/**
	 * @param string $const = ''
	 *
	 * @return mixed
	 */
	public function ConstValue ($const = '') {
		return QuarkObject::ClassConstValue(get_class($this), $const);
	}

	/**
	 * @return array
	 */
	public function Constants () {
		return QuarkObject::ClassConstants(get_called_class());
	}

	/**
	 * @return array
	 */
	public static function ClassConstants () {
		return QuarkObject::ClassConstants(get_called_class());
	}

	/**
	 * @param string $source = ''
	 * @param array $data = []
	 *
	 * @return string
	 */
	public function Template ($source = '', $data = []) {
		return QuarkView::TemplateString($source, $data);
	}

	/**
	 * @param string $key = ''
	 * @param array $data = []
	 * @param string $language = QuarkLanguage::ANY
	 *
	 * @return string
	 */
	public function TemplatedLocalizationOf ($key = '', $data = [], $language = QuarkLanguage::ANY) {
		return $this->Template($this->LocalizationOf($key, $language), $data);
	}

	/**
	 * @param string $key = ''
	 * @param array $data = []
	 *
	 * @return object
	 */
	public function TemplatedLocalizationDictionaryOf ($key = '', $data = []) {
		$out = new \stdClass();
		$locales = $this->LocalizationDictionaryOf($key);

		foreach ($locales as $key => $locale)
			$out->$key = $this->Template($locale, $data);

		return $out;
	}

	/**
	 * @param string $key = ''
	 * @param array $data = []
	 * @param bool $strict = false
	 *
	 * @return string
	 */
	public function TemplatedCurrentLocalizationOf ($key = '', $data = [], $strict = false) {
		return $this->Template($this->CurrentLocalizationOf($key, $strict), $data);
	}
}

/**
 * Interface IQuarkPrimitive
 *
 * @package Quark
 */
interface IQuarkPrimitive { }

/**
 * Interface IQuarkContainer
 *
 * @package Quark
 */
interface IQuarkContainer {
	/**
	 * @param IQuarkPrimitive $primitive = null
	 *
	 * @return IQuarkPrimitive
	 */
	public function &Primitive(IQuarkPrimitive $primitive = null);
}

/**
 * Class QuarkObject
 *
 * @package Quark
 */
class QuarkObject {
	/**
	 * @param $source
	 * @param callable $iterator
	 * @param string $key = ''
	 * @param $parent = null
	 */
	public static function Walk (&$source, callable $iterator, $key = '', &$parent = null) {
		if (self::isIterative($source)) {
			$i = 0;
			$size = sizeof($source);

			while ($i < $size) {
				self::Walk($source[$i], $iterator, $key . ($key == '' ? $i : '[' . $i . ']'), $source);

				$i++;
			}

			unset($i, $size);
		}
		else {
			if ($source instanceof QuarkFile)
				$source = new QuarkModel($source);

			if (is_scalar($source) || $source === null) $iterator($key, $source, $parent);
			elseif ($source instanceof QuarkModel) {
				$model = $source->Model();
				$iterator($key, $model, $parent);
			}
			else {
				foreach ($source as $k => $v) {
					self::Walk($v, $iterator, $key . ($key == '' ? $k : '[' . $k . ']'), $source);
				}
			}

			unset($k, $v);
		}
	}

	/**
	 * @param string[] $paths = []
	 * @param string $prefix = ''
	 *
	 * @return QuarkKeyValuePair[]
	 */
	public static function TreeBuilder ($paths = [], $prefix = '') {
		$out = array();
		$dirs = array();
		$files = array();

		foreach ($paths as $link) {
			$path = explode('/', $link);

			if (sizeof($path) == 1) $files[] = new QuarkKeyValuePair($prefix . '/' . $link, $link);
			else {
				if (!isset($dirs[$path[0]]))
					$dirs[$path[0]] = array();

				$dirs[$path[0]][] = implode('/', array_slice($path, 1));
			}
		}

		foreach ($dirs as $key => $link)
			$out[$key] = self::TreeBuilder($link, $prefix . '/' . $key);

		foreach ($files as $file)
			$out[] = $file;

		return $out;
	}

	/**
	 * @return mixed
	 */
	public static function Merge () {
		$args = func_get_args();

		if (sizeof($args) == 0) return null;
		if (sizeof($args) == 1)
			$args = array(new \stdClass(), $args[0]);
		
		$out = null;
		
		foreach ($args as $arg) {
			if ($arg === null) continue;

			$iterative = self::isIterative($arg);

			if (is_scalar($arg) || is_null($arg) || (is_object($arg) && !($arg instanceof \stdClass))) {
				$out = $arg;
				continue;
			}

			if ($iterative && sizeof($arg) == 0) {
				$out = (array)$arg;
				continue;
			}

			foreach ($arg as $key => $value) {
				if ($iterative) {
					if (!is_array($out))
						$out = array();

					$def = isset($out[$key]) ? $out[$key] : null;
					$out[] = self::Merge($def, $value);
				}
				else {
					if (!is_object($out))
						$out = new \stdClass();

					$def = isset($out->$key) ? $out->$key : null;

					if (!empty($key))
						$out->$key = self::Merge($def, $value);
				}
			}
		}
		
		return $out;
	}

	/**
	 * @param $source
	 *
	 * @return bool
	 */
	public static function isAssociative ($source) {
		return is_object($source) || is_array($source) && sizeof(array_filter(array_keys($source), 'is_string')) != 0;
	}

	/**
	 * @param $source
	 *
	 * @return bool
	 */
	public static function isIterative ($source) {
		return is_array($source) && (sizeof($source) == 0 || sizeof(array_filter(array_keys($source), 'is_int')) != 0);
	}

	/**
	 * @param $source
	 *
	 * @return bool
	 */
	public static function isTraversable ($source) {
		return is_array($source) || is_object($source);
	}

	/**
	 * @param $source
	 * @param $type
	 *
	 * @return bool
	 */
	public static function IsArrayOf ($source, $type) {
		if (!self::isIterative($source)) return false;

		$scalar = is_scalar($type);
		$typeof = gettype($type);

		foreach ($source as $item) {
			if ($scalar && gettype($item) != $typeof) return false;
			if (!$scalar && !($item instanceof $type)) return false;
		}

		return true;
	}

	/**
	 * @param $class
	 * @param string|array $interface = ''
	 * @param bool $silent = false
	 *
	 * @return bool
	 */
	public static function is ($class, $interface = '', $silent = false) {
		if (!is_array($interface))
			$interface = array($interface);

		if (is_object($class))
			$class = get_class($class);

		if (!class_exists($class)) {
			if (!$silent)
				Quark::Log('Class "' . $class . '" does not exists', Quark::LOG_WARN);

			return false;
		}

		$faces = class_implements($class);

		foreach ($interface as $face)
			if (in_array($face, $faces, true)) return true;

		return false;
	}

	/**
	 * @param $interface
	 * @param callable $filter = null
	 *
	 * @return array
	 */
	public static function Implementations ($interface, callable $filter = null) {
		$output = array();
		$classes = get_declared_classes();

		foreach ($classes as $class)
			if (self::is($class, $interface) && ($filter != null ? $filter($class) : true)) $output[] = $class;

		return $output;
	}

	/**
	 * http://stackoverflow.com/a/25900210/2097055
	 * 
	 * @param string $class = ''
	 * @param string $trait = ''
	 * @param bool $parents = true
	 *
	 * @return bool
	 */
	public static function Uses ($class = '', $trait = '', $parents = true) {
		$tree = $parents ? class_parents($class) : array();
		$tree[] = $class;

		foreach ($tree as $node) {
			$uses = class_uses($node);

			foreach ($uses as $use)
				if ($use == $trait) return true;
		}

		return false;
	}

	/**
	 * @param $target
	 *
	 * @return string
	 */
	public static function ClassOf ($target) {
		return is_object($target) ? array_reverse(explode('\\', get_class($target)))[0] : null;
	}

	/**
	 * @param string $file
	 *
	 * @return string
	 */
	public static function ClassIn ($file) {
		return is_string($file) ? '\\' . str_replace('/', '\\', str_replace(Quark::Host(), '', str_replace('.php', '', Quark::NormalizePath($file, false)))) : '';
	}

	/**
	 * @param string $class
	 *
	 * @return object
	 */
	public static function Of ($class) {
		return new $class;
	}

	/**
	 * @param $source
	 *
	 * @return array
	 */
	public static function Properties ($source) {
		return is_object($source)
			? array_intersect(
				get_object_vars($source),
				get_class_vars(get_class($source))
			)
			: array();
	}

	/**
	 * @param $source
	 * @param $name
	 * @param $default = null
	 *
	 * @return mixed
	 */
	public static function Property ($source, $name, $default = null) {
		if (is_object($source))
			return isset($source->$name) ? $source->$name : $default;

		if (is_array($source))
			return isset($source[$name]) ? $source[$name] : $default;

		return $default;
	}

	/**
	 * @param $source
	 * @param $name
	 *
	 * @return bool
	 */
	public static function PropertyExists ($source, $name) {
		if (is_object($source))
			return isset($source->$name);

		if (is_array($source))
			return isset($source[$name]);

		return false;
	}

	/**
	 * @param $value
	 *
	 * @return mixed
	 */
	public static function ConstByValue ($value) {
		$defined = get_defined_constants(true);

		if (!isset($defined['user']) || !is_array($defined['user'])) return null;

		foreach ($defined['user'] as $key => $val)
			if ($val === $value) return $key;

		return null;
	}

	/**
	 * @param string $const
	 *
	 * @return mixed
	 */
	public static function ConstValue ($const) {
		return defined($const) ? constant($const) : $const;
	}

	/**
	 * @param string|object $class
	 * @param $value
	 *
	 * @return mixed
	 */
	public static function ClassConstByValue ($class, $value) {
		$defined = self::ClassConstants($class);

		foreach ($defined as $key => $const)
			if ($const == $value) return $key;

		return null;
	}

	/**
	 * @param string|object $class
	 * @param string $const
	 *
	 * @return mixed
	 */
	public static function ClassConstValue ($class, $const) {
		return self::ConstValue(is_object($class) ? get_class($class) : $class . '::' . $const);
	}

	/**
	 * @param string|object $class
	 *
	 * @return array
	 */
	public static function ClassConstants ($class) {
		$reflection = new \ReflectionClass($class);
		return $reflection->getConstants();
	}

	/**
	 * @param $value
	 *
	 * @return string
	 */
	public static function Stringify ($value) {
		if (is_bool($value))
			return $value ? 'true' : 'false';

		if (is_null($value)) return 'null';
		if (is_array($value)) return 'array';

		return (string)$value;
	}

	/**
	 * @param $var
	 * @param bool $objectToNull = false
	 *
	 * @return bool|int|float|string|array|object|null
	 */
	public static function DefaultValueOfType ($var, $objectToNull = true) {
		if (is_bool($var)) return false;
		if (is_int($var)) return 0;
		if (is_float($var)) return 0.0;
		if (is_string($var)) return '';
		if (is_array($var)) return array();
		if (is_object($var) && !$objectToNull) return new \stdClass();
		
		return null;
	}
}

/**
 * Trait QuarkViewBehavior
 *
 * @package Quark
 */
trait QuarkViewBehavior {
	use QuarkContainerBehavior;

	/** @noinspection PhpUnusedPrivateMethodInspection
	 * @return QuarkView
	 */
	private function _envelope () {
		return new QuarkView($this);
	}

	/**
	 * @param IQuarkViewModel $view = null
	 *
	 * @return mixed
	 */
	public function Child (IQuarkViewModel $view = null) {
		return $this->__call('Child', func_get_args());
	}

	/**
	 * @param IQuarkViewModel $view = null
	 *
	 * @return mixed
	 */
	public function Layout (IQuarkViewModel $view = null) {
		return $this->__call('Layout', func_get_args());
	}

	/**
	 * @return QuarkModel|QuarkSessionBehavior|IQuarkAuthorizableModel
	 */
	public function User () {
		return $this->__call('User', func_get_args());
	}

	/**
	 * @param bool $localized = true
	 *
	 * @return string
	 */
	public function Theme ($localized = true) {
		return $this->__call('Theme', func_get_args());
	}

	/**
	 * @param bool $localized = true
	 *
	 * @return string
	 */
	public function ThemeURL ($localized = true) {
		return $this->__call('ThemeURL', func_get_args());
	}

	/**
	 * @param string $uri
	 * @param bool $signed = false
	 *
	 * @return string
	 */
	public function Link ($uri, $signed = false) {
		return $this->__call('Link', func_get_args());
	}

	/**
	 * @param bool $field = true
	 *
	 * @return mixed
	 */
	public function Signature ($field = true) {
		return $this->__call('Signature', func_get_args());
	}

	/**
	 * @param string $name = ''
	 *
	 * @return IQuarkExtension
	 */
	public function Extension ($name = '') {
		return $this->__call('Extension', func_get_args());
	}

	/**
	 * @param string $language = QuarkLanguage::ANY
	 *
	 * @return string
	 */
	public function Language ($language = QuarkLanguage::ANY) {
		return $this->__call('Language', func_get_args());
	}

	/**
	 * @param string $language = QuarkLanguage::ANY
	 * @param string[] $languages = []
	 *
	 * @return string
	 */
	public function Localization ($language = QuarkLanguage::ANY, $languages = []) {
		return $this->__call('Localization', func_get_args());
	}

	/**
	 * @return mixed
	 */
	public function Compile () {
		return $this->__call('Compile', func_get_args());
	}
}

/**
 * Class QuarkView
 *
 * @package Quark
 */
class QuarkView implements IQuarkContainer {
	const FIELD_ERROR_TEMPLATE = '<div class="quark-message warn fa fa-warning"><p class="content">{error}</p></div>';
	const SIGNED_ACTION_FORM_STYLE = 'display: inline-block; margin: 0; padding: 0; border: none;';
	const DEFAULT_THEME = 'Default';
	const GENERIC_LOCALIZATION = '_any';
	
	/**
	 * @var IQuarkViewModel|IQuarkViewModelWithResources|IQuarkViewModelWithVariableDiscovering $_view = null
	 */
	private $_view = null;

	/**
	 * @var IQuarkViewModel|IQuarkViewModelWithResources|IQuarkViewModelWithVariableDiscovering $_child = null
	 */
	private $_child = null;

	/**
	 * @var QuarkView $_layout = null
	 */
	private $_layout = null;

	/**
	 * @var string $_file = ''
	 */
	private $_file = '';

	/**
	 * @var object|array $_vars = []
	 */
	private $_vars = array();

	/**
	 * @var IQuarkViewResource[] $_resources = []
	 */
	private $_resources = array();

	/**
	 * @var string $_html = ''
	 */
	private $_html = '';

	/**
	 * @var bool $_inline = false
	 */
	private $_inline = false;

	/**
	 * @var null $_null = null
	 */
	private $_null = null;

	/**
	 * @var string $_language = QuarkLanguage::ANY
	 */
	private $_language = QuarkLanguage::ANY;
	
	/**
	 * @var string $_theme = ''
	 */
	private $_theme = '';

	/**
	 * @var bool $_localized = false
	 */
	private $_localized = false;

	/**
	 * @param IQuarkViewModel|QuarkViewBehavior $view
	 * @param QuarkDTO|object|array $vars = []
	 * @param IQuarkViewResource[] $resources = []
	 *
	 * @throws QuarkArchException
	 */
	public function __construct (IQuarkViewModel $view = null, $vars = [], $resources = []) {
		if ($view == null) return;

		$this->_language = Quark::CurrentLanguage();
		$this->_view = $view;
		
		$vars = $this->Vars($vars);

		foreach ($vars as $key => $value)
			$this->_view->$key = $value;
		
		$this->Vars($this->_view);

		$_file = $this->_file = $this->_localized_theme($this->_language == QuarkLanguage::ANY ? self::GENERIC_LOCALIZATION : $this->_language);
		
		if (Quark::Config()->LocalizationByFamily() && !is_file($this->_file))
			$_file = $this->_file = $this->_localized_theme(Quark::CurrentLanguageFamily());
		
		if (!is_file($this->_file))
			$_file = $this->_file = $this->_localized_theme(self::GENERIC_LOCALIZATION);
		
		if (!is_file($this->_file)) {
			$this->_file = $this->_view->View();
			$this->_theme = '';
		}
		
		if (!is_file($this->_file))
			throw new QuarkArchException('Unknown view file ' . $this->_file . ' (' . $_file . '). If you specified your view as IQuarkViewModelInTheme or its inheritor, check that theme structure is correct.');

		$this->_resources = $resources;

		Quark::Container($this);
	}

	/**
	 * @param $key
	 *
	 * @return mixed
	 */
	public function __get ($key) {
		return isset($this->_view->$key)
			? $this->_view->$key
			: (isset($this->_layout->$key) ? $this->_layout->$key : $this->_null);
	}

	/**
	 * @param $key
	 * @param $value
	 */
	public function __set ($key, $value) {
		$this->_view->$key = $value;
	}

	/**
	 * @param $key
	 *
	 * @return bool
	 */
	public function __isset ($key) {
		return isset($this->_view->$key) || isset($this->_layout->$key);
	}

	/**
	 * @param $name
	 */
	public function __unset ($name) {
		unset($this->_view->$name);
	}

	/**
	 * @param $method
	 * @param $args
	 *
	 * @return mixed
	 * @throws QuarkArchException
	 */
	public function __call ($method, $args) {
		if ($this->_view != null && method_exists($this->_view, $method))
			return call_user_func_array(array($this->_view, $method), $args);

		if ($this->_child != null && method_exists($this->_child, $method))
			return call_user_func_array(array($this->_child, $method), $args);

		if ($this->_layout != null && method_exists($this->_layout->ViewModel(), $method))
			return call_user_func_array(array($this->_layout, $method), $args);

		throw new QuarkArchException('Method ' . $method . ' not exists in ' . get_class($this->_view) . ' environment');
	}

	/**
	 * @param bool $obfuscate = true
	 *
	 * @return string
	 */
	public function Resources ($obfuscate = true) {
		$out = '';
		$type = null;
		$res = null;
		$location = null;
		$content = null;

		$this->ResourceList();

		/**
		 * @var IQuarkViewResource|IQuarkSpecifiedViewResource|IQuarkForeignViewResource|IQuarkLocalViewResource|IQuarkInlineViewResource|IQuarkViewResourceWithLocationControl $resource
		 */
		foreach ($this->_resources as $resource) {
			if ($resource instanceof IQuarkInlineViewResource) {
				$out .= $obfuscate && $resource instanceof IQuarkLocalViewResource && $resource->CacheControl()
					? QuarkSource::ObfuscateString($resource->HTML())
					: $resource->HTML();

				continue;
			}

			if (!($resource instanceof IQuarkSpecifiedViewResource)) continue;

			$type = $resource->Type();
			if (!($type instanceof IQuarkViewResourceType)) continue;

			$location = $resource->Location();
			$content = '';
			
			if ($resource instanceof IQuarkForeignViewResource || ($resource instanceof IQuarkViewResourceWithLocationControl && $resource->LocationControl(false))) { }

			if ($resource instanceof IQuarkLocalViewResource || ($resource instanceof IQuarkViewResourceWithLocationControl && $resource->LocationControl(true))) {
				$res = new QuarkSource($location, true);

				if ($obfuscate && $resource->CacheControl())
					$res->Obfuscate();

				$content = $res->Content();

				$location = '';
			}

			$out .= $type->Container($location, $content);
		}

		return $out;
	}

	/**
	 * @param bool $inline = false
	 *
	 * @return bool
	 */
	public function InlineStyles ($inline = false) {
		if (func_num_args() != 0)
			$this->_inline = $inline;

		return $this->_inline;
	}

	/**
	 * @return IQuarkViewResource[]
	 */
	public function ResourceList () {
		$buffer = array();
		
		if (sizeof($this->_resources) != 0) {
			$buffer = $this->_resources;
			$this->_resources = array();
		}

		if ($this->_view instanceof IQuarkViewModelWithVariableProxy) {
			$vars = $this->_view->ViewVariableProxy($this->_vars);

			if (QuarkObject::isTraversable($vars))
				foreach ($vars as $key => $value)
					$this->_resource(new QuarkProxyJSViewResource($key, $value instanceof QuarkModel ? $value->Extract() : $value));
		}
		
		if ($this->_view instanceof IQuarkViewModelWithResources)
			$this->_resources($this->_view->ViewResources());

		$this->_resources($buffer);

		if ($this->_view instanceof IQuarkViewModelWithComponents) {
			$this->_resource(QuarkGenericViewResource::CSS($this->_view->ViewStylesheet()));
			$this->_resource(QuarkGenericViewResource::JS($this->_view->ViewController()));
		}

		return $this->_resources;
	}

	/**
	 * @param IQuarkViewResource[] $resources
	 *
	 * @return QuarkView
	 */
	private function _resources ($resources = []) {
		if (is_array($resources))
			foreach ($resources as $resource)
				$this->_resource($resource);

		return $this;
	}

	/**
	 * @param IQuarkViewResource|IQuarkViewResourceWithDependencies $resource = null
	 *
	 * @return QuarkView
	 *
	 * @throws QuarkArchException
	 */
	private function _resource (IQuarkViewResource $resource = null) {
		if ($resource == null) return $this;

		if ($resource instanceof IQuarkViewResourceWithDependencies)
			$this->_resource_dependencies($resource->Dependencies(), 'ViewResource ' . get_class($resource) . ' specified invalid value for `Dependencies`. Expected array of IQuarkViewResource.');

		if ($resource instanceof IQuarkCombinedViewResource) {
			$this->_resource(QuarkGenericViewResource::CSS($resource->LocationStylesheet()));
			$this->_resource(QuarkGenericViewResource::JS($resource->LocationController()));
		}

		if (!$this->_resource_loaded($resource))
			$this->_resources[] = $resource;

		if ($resource instanceof IQuarkViewResourceWithBackwardDependencies)
			$this->_resource_dependencies($resource->BackwardDependencies(), 'ViewResource ' . get_class($resource) . ' specified invalid value for `BackwardDependencies`. Expected array of IQuarkViewResource.');

		return $this;
	}

	/**
	 * @param IQuarkViewResource[] $resources
	 * @param string $error
	 *
	 * @throws QuarkArchException
	 */
	private function _resource_dependencies ($resources = [], $error = '') {
		if (!is_array($resources))
			throw new QuarkArchException($error);

		/**
		 * @var IQuarkViewResource $dependency
		 */
		foreach ($resources as $dependency) {
			if ($dependency == null) continue;

			if ($dependency instanceof IQuarkViewResourceWithDependencies) $this->_resource($dependency);
			if ($this->_resource_loaded($dependency)) continue;

			$this->_resources[] = $dependency;
		}
	}

	/**
	 * @param IQuarkViewResource $dependency
	 *
	 * @return bool
	 */
	private function _resource_loaded (IQuarkViewResource $dependency) {
		if ($dependency instanceof IQuarkMultipleViewResource) return false;

		$class = get_class($dependency);

		/**
		 * @var IQuarkViewResource $resource
		 */
		foreach ($this->_resources as $resource)
			if (get_class($resource) == $class) return true;

		return false;
	}

	/**
	 * @param string $language
	 * 
	 * @return string
	 */
	private function _localized_theme ($language) {
		$this->_theme = Quark::Host() . '/' . Quark::Config()->Location(QuarkConfig::VIEWS);

		if ($this->_view instanceof IQuarkViewModelInTheme) {
			$theme = $this->_view->ViewTheme();

			if ($theme === null)
				$theme = self::DEFAULT_THEME;
			
			$this->_theme .= '/_themes/' . $theme;

			if ($this->_view instanceof IQuarkViewModelInLocalizedTheme) {
				$this->_localized = true;
				$this->_theme .= '/' . str_replace('/', '', str_replace('.', '', $language));
			}
		}

		return Quark::NormalizePath($this->_theme . '/' . $this->_view->View() . '.php', false);
	}
	
	/**
	 * @param IQuarkViewResource[] $resources
	 *
	 * @return QuarkView
	 */
	public function AppendResources ($resources = []) {
		$this->_resources = array_merge($this->_resources, $resources);
		return $this;
	}

	/**
	 * @param bool $localized = true
	 *
	 * @return string
	 */
	public function Theme ($localized = true) {
		return $this->_localized && !$localized
			? substr($this->_theme, 0, strripos($this->_theme, '/'))
			: $this->_theme;
	}

	/**
	 * @param bool $localized = true
	 *
	 * @return string
	 */
	public function ThemeURL ($localized = true) {
		return Quark::WebLocation($this->Theme($localized));
	}

	/**
	 * @return bool
	 */
	public function Localized () {
		return $this->_localized;
	}

	/**
	 * @param IQuarkViewFragment $fragment
	 *
	 * @return string
	 */
	public function Fragment (IQuarkViewFragment $fragment) {
		return $fragment->CompileFragment();
	}

	/**
	 * @param string $uri
	 * @param bool $signed = false
	 *
	 * @return string
	 */
	public function Link ($uri, $signed = false) {
		return Quark::WebLocation($uri . ($signed ? QuarkURI::BuildQuery($uri, array(
				QuarkDTO::KEY_SIGNATURE => $this->Signature(false)
			)) : ''));
	}

	/**
	 * @param string $uri
	 * @param string $button
	 * @param string $method = QuarkDTO::METHOD_POST
	 * @param string $formStyle = self::SIGNED_ACTION_FORM_STYLE
	 *
	 * @return string
	 */
	public function SignedAction ($uri, $button, $method = QuarkDTO::METHOD_POST, $formStyle = self::SIGNED_ACTION_FORM_STYLE) {
		/** @lang text */
		return '<form action="' . $uri . '" method="' . $method . '" style="' . $formStyle . '">' . $button . $this->Signature() . '</form>';
	}

	/**
	 * @param bool $field = true
	 *
	 * @return string
	 */
	public function Signature ($field = true) {
		$sign = QuarkSession::Current() ? QuarkSession::Current()->Signature() : '';

		return $field ? '<input type="hidden" name="' . QuarkDTO::KEY_SIGNATURE . '" value="' . $sign . '" />' : $sign;
	}

	/**
	 * @return QuarkConfig
	 */
	public function Config () {
		return Quark::Config();
	}

	/**
	 * @param string $name = ''
	 *
	 * @return IQuarkExtension
	 */
	public function Extension ($name = '') {
		$ext = Quark::Config()->Extension($name);

		return $ext ? $ext->ExtensionInstance() : null;
	}

	/**
	 * @return QuarkModel|QuarkSessionBehavior|IQuarkAuthorizableModel
	 */
	public function User () {
		return QuarkSession::Current() ? QuarkSession::Current()->User() : null;
	}

	/**
	 * @param IQuarkViewModel $view = null
	 * @param array|object $vars = []
	 * @param array $resources = []
	 *
	 * @return QuarkView
	 */
	public function Layout (IQuarkViewModel $view = null, $vars = [], $resources = []) {
		if (func_num_args() != 0 && $view != null) {
			$this->_layout = new QuarkView($view, $vars);

			if ($this->_view instanceof IQuarkViewModelWithLayoutResources)
				$this->_layout->_resources($this->_view->ViewLayoutResources());

			if ($this->_view instanceof IQuarkViewModelWithLayoutComponents) {
				$this->_layout->_resource(QuarkGenericViewResource::CSS($this->_view->ViewLayoutStylesheet()));
				$this->_layout->_resource(QuarkGenericViewResource::JS($this->_view->ViewLayoutController()));
			}

			$this->_layout->_resources($this->ResourceList());
			$this->_layout->_resources($resources);

			$this->_layout->View($this->Compile());
			$this->_layout->Child($this->_view);
			$this->_language = $this->_layout->Language();
		}

		return $this->_layout;
	}

	/**
	 * @param string $html = ''
	 *
	 * @return string
	 */
	public function View ($html = '') {
		if (func_num_args() == 1)
			$this->_html = $html;

		return $this->_html;
	}

	/**
	 * @param IQuarkViewModel $view
	 * @param IQuarkViewModel $layout
	 * @param array|object $vars = []
	 *
	 * @return QuarkView
	 */
	public static function InLayout (IQuarkViewModel $view, IQuarkViewModel $layout, $vars = []) {
		$inline = new QuarkView($view, $vars);

		return $inline->Layout($layout, $vars);
	}

	/**
	 * @param string $source = ''
	 * @param array|object $data = []
	 * @param string $prefix = ''
	 *
	 * @return string
	 */
	private static function _tpl ($source = '', $data = [], $prefix = '') {
		if (!QuarkObject::isTraversable($data)) return $source;

		if ($data instanceof QuarkModel)
			$data = $data->Model();

		foreach ($data as $key => $value) {
			$append = $prefix . $key;
			$source = QuarkObject::isTraversable($value)
				? self::_tpl($source, $value, $append . '.')
				: preg_replace('#\{' . $append . '\}#Uis', $value, $source);
		}

		return $source;
	}

	/**
	 * @param string $source = ''
	 * @param array|object $data = []
	 *
	 * @return string
	 */
	public static function TemplateString ($source = '', $data = []) {
		return self::_tpl($source, $data);
	}

	/**
	 * @param QuarkDTO|object|array $params
	 *
	 * @return array
	 */
	public function Vars ($params = []) {
		if (func_num_args() == 1) {
			$vars = $params instanceof QuarkDTO
				? $params->Data()
				: QuarkObject::Merge((object)$params);

			$this->_vars = $vars == null ? (object)array() : $vars;
		}

		return $this->_vars;
	}

	/**
	 * @return mixed
	 */
	public function ExtractVars () {
		if (!QuarkObject::isTraversable($this->_vars)) return $this->_vars;

		$out = new \stdClass();
		$discovering = $this->_view instanceof IQuarkViewModelWithVariableDiscovering;

		foreach ($this->_vars as $key => $value) {
			if ($discovering) $out->$key = $this->_view->ViewVariableDiscovering($key, $value);
			else {
				if ($value instanceof QuarkModel || $value instanceof QuarkCollection) $out->$key = $value->Extract();
				else $out->$key = $value;
			}
		}

		return $out;
	}

	/**
	 * @param string $language = QuarkLanguage::ANY
	 *
	 * @return string
	 */
	public function Language ($language = QuarkLanguage::ANY) {
		if (func_num_args() != 0)
			$this->_language = $language;

		return $this->_language;
	}

	/**
	 * @param string $language = QuarkLanguage::ANY
	 * @param string[] $languages = []
	 * 
	 * @return string
	 */
	public function Localization ($language = QuarkLanguage::ANY, $languages = []) {
		$args = func_num_args();

		if ($args == 0) {
			$language = Quark::CurrentLanguage();
			$languages = Quark::Config()->Languages();
		}

		if ($args == 1)
			$languages = Quark::Config()->Languages();

		return ' quark-language="' . $language . '" quark-languages="' . implode(QuarkConfig::LANGUAGE_DELIMITER, $languages) . '" ';
	}

	/**
	 * @param QuarkKeyValuePair[] $menu
	 * @param callable($href, $text) $button = null
	 * @param callable($text) $node = null
	 *
	 * @return string
	 */
	public static function TreeMenu ($menu = [], callable $button = null, callable $node = null) {
		$out = '';
		
		if ($button == null)
			$button = function ($href, $text) { return '<a href="' . $href . '">' . $text . '</a>'; };

		if ($node == null)
			$node = function ($text) { return '<div class="group-name">' . $text . '</div>'; };

		foreach ($menu as $key => $element) {
			if (!is_array($element)) $out .= $button($element->Key(), $element->Value());
			else {
				$out .= '<div class="quark-button-group">' . $node($key)
					. self::TreeMenu($element, $button, $node)
					. '</div>';
			}
		}

		return $out;
	}

	/**
	 * @param QuarkModel $model
	 * @param string $field
	 * @param string $template = self::FIELD_ERROR_TEMPLATE
	 *
	 * @return string
	 */
	public function FieldError (QuarkModel $model = null, $field = '', $template = self::FIELD_ERROR_TEMPLATE) {
		if ($model == null) return '';

		$errors = $model->RawValidationErrors();
		$out = '';

		foreach ($errors as $error)
			if ($error->Key() == $field)
				$out .= str_replace('{error}', $error->Value()->Of(Quark::CurrentLanguage()), $template);

		return $out;
	}

	/**
	 * @return string
	 */
	public function Compile () {
		if ($this->_view instanceof IQuarkViewModelWithVariableProcessing)
			$this->_view->ViewVariableProcessing($this->_vars);

		foreach ($this->_vars as $___name___ => &$___value___)
			$$___name___ = $___value___;

		ob_start();
		/** @noinspection PhpIncludeInspection */
		include $this->_file;
		$out = ob_get_clean();

		if ($this->_inline) {
			if (preg_match_all('#id="(.*)"#Uis', $out, $ids, PREG_SET_ORDER)) {
				foreach ($ids as $id) {
					$css = '';

					if (preg_match_all('#\#' . $id[1] . '{(.*)}#Uis', $out, $id_css, PREG_SET_ORDER)) {

						foreach ($id_css as $id_c) {
							$css .= $id_c[1];
							$out = str_replace('#' . $id[1] . '{' . $id_c[1] . '}', '', $out);
						}
					}

					$out = str_replace('id="' . $id[1] . '"', 'id="' . $id[1] . '" style="' . $css . '"', $out);
				}
			}
		}

		return $out;
	}

	/**
	 * @param IQuarkViewModel $view = null
	 *
	 * @return IQuarkViewModel
	 */
	public function ViewModel (IQuarkViewModel $view = null) {
		if (func_num_args() != 0)
			$this->_view = $view;

		return $this->_view;
	}

	/**
	 * @param IQuarkViewModel $view = null
	 *
	 * @return IQuarkViewModel
	 */
	public function Child (IQuarkViewModel $view = null) {
		if (func_num_args() == 1)
			$this->_child = $view;

		return $this->_child;
	}

	/**
	 * @param IQuarkPrimitive $primitive = null
	 *
	 * @return IQuarkPrimitive
	 */
	public function &Primitive (IQuarkPrimitive $primitive = null) {
		if (func_num_args() != 0)
			$this->_view = $primitive;

		return $this->_view;
	}
}

/**
 * Interface IQuarkViewModel
 *
 * @package Quark
 */
interface IQuarkViewModel extends IQuarkPrimitive {
	/**
	 * @return string
	 */
	public function View();
}

/**
 * Interface IQuarkViewModelWithComponents
 *
 * @package Quark
 */
interface IQuarkViewModelWithComponents extends IQuarkViewModel {
	/**
	 * @return IQuarkViewResource|string
	 */
	public function ViewStylesheet();

	/**
	 * @return IQuarkViewResource|string
	 */
	public function ViewController();
}

/**
 * Interface IQuarkViewModelWithResources
 *
 * @package Quark
 */
interface IQuarkViewModelWithResources extends IQuarkViewModel {
	/**
	 * @return IQuarkViewResource[]
	 */
	public function ViewResources();
}

/**
 * Interface IQuarkViewModelWithLayoutComponents
 *
 * @package Quark
 */
interface IQuarkViewModelWithLayoutComponents extends IQuarkViewModel {
	/**
	 * @return IQuarkViewResource|string
	 */
	public function ViewLayoutStylesheet();

	/**
	 * @return IQuarkViewResource|string
	 */
	public function ViewLayoutController();
}

/**
 * Interface IQuarkViewModelWithLayoutResources
 *
 * @package Quark
 */
interface IQuarkViewModelWithLayoutResources extends IQuarkViewModel {
	/**
	 * @return IQuarkViewResource[]
	 */
	public function ViewLayoutResources();
}

/**
 * Interface IQuarkViewModelWithCustomizableLayout
 *
 * @package Quark
 */
interface IQuarkViewModelWithCustomizableLayout extends IQuarkViewModelWithLayoutComponents, IQuarkViewModelWithLayoutResources { }

/**
 * Interface IQuarkViewModelInTheme
 *
 * @package Quark
 */
interface IQuarkViewModelInTheme {
	/**
	 * @return string
	 */
	public function ViewTheme();
}

/**
 * Interface IQuarkViewModelInLocalizedTheme
 *
 * @package Quark
 */
interface IQuarkViewModelInLocalizedTheme extends IQuarkViewModelInTheme { }

/**
 * Interface IQuarkViewModelWithVariableProcessing
 *
 * @package Quark
 */
interface IQuarkViewModelWithVariableProcessing extends IQuarkViewModel {
	/**
	 * @param $vars
	 *
	 * @return mixed
	 */
	public function ViewVariableProcessing($vars);
}

/**
 * Interface IQuarkViewModelWithVariableDiscovering
 *
 * @package Quark
 */
interface IQuarkViewModelWithVariableDiscovering extends IQuarkViewModel {
	/**
	 * @param string $key
	 * @param $var
	 *
	 * @return mixed
	 */
	public function ViewVariableDiscovering($key, $var);
}

/**
 * Interface IQuarkViewModelWithVariableProxy
 *
 * @package Quark
 */
interface IQuarkViewModelWithVariableProxy extends IQuarkViewModel {
	/**
	 * @param $vars
	 *
	 * @return mixed
	 */
	public function ViewVariableProxy($vars);
}

/**
 * Interface IQuarkViewResource
 *
 * @package Quark
 */
interface IQuarkViewResource { }

/**
 * Interface IQuarkViewSpecifiedResource
 *
 * @package Quark
 */
interface IQuarkSpecifiedViewResource extends IQuarkViewResource {
	/**
	 * @return IQuarkViewResourceType
	 */
	public function Type();

	/**
	 * @return string
	 */
	public function Location();

}

/**
 * Interface IQuarkViewResourceWithDependencies
 *
 * @package Quark
 */
interface IQuarkViewResourceWithDependencies extends IQuarkViewResource {
	/**
	 * @return IQuarkViewResource[]
	 */
	public function Dependencies();
}

/**
 * Interface IQuarkViewResourceWithBackwardDependencies
 *
 * @package Quark
 */
interface IQuarkViewResourceWithBackwardDependencies extends IQuarkViewResource {
	/**
	 * @return IQuarkViewResource[]
	 */
	public function BackwardDependencies();
}

/**
 * Interface IQuarkLocalViewResource
 *
 * @package Quark
 */
interface IQuarkLocalViewResource extends IQuarkViewResource {
	/**
	 * @return bool
	 */
	public function CacheControl();
}

/**
 * Interface IQuarkForeignViewResource
 *
 * @package Quark
 */
interface IQuarkForeignViewResource extends IQuarkViewResource {
	/**
	 * @return QuarkDTO
	 */
	public function RequestDTO();
}

/**
 * Interface IQuarkViewResourceWithLocationControl
 *
 * @package Quark
 */
interface IQuarkViewResourceWithLocationControl extends IQuarkSpecifiedViewResource {
	/**
	 * @param bool $local
	 *
	 * @return bool
	 */
	public function LocationControl($local);
}

/**
 * Interface IQuarkInlineViewResource
 *
 * @package Quark
 */
interface IQuarkInlineViewResource extends IQuarkViewResource {
	/**
	 * @return string
	 */
	public function HTML();
}

/**
 * Interface IQuarkMultipleViewResource
 *
 * @package Quark
 */
interface IQuarkMultipleViewResource extends IQuarkViewResource { }

/**
 * Interface IQuarkCombinedViewResource
 *
 * @package Quark
 */
interface IQuarkCombinedViewResource extends IQuarkViewResource {
	/**
	 * @return string
	 */
	public function LocationStylesheet();

	/**
	 * @return string
	 */
	public function LocationController();
}

/**
 * Class QuarkGenericViewResource
 *
 * @package Quark
 */
class QuarkGenericViewResource implements IQuarkSpecifiedViewResource, IQuarkViewResourceWithLocationControl, IQuarkMultipleViewResource, IQuarkViewResourceWithDependencies {
	/**
	 * @var IQuarkViewResourceType $_type = null
	 */
	private $_type = null;

	/**
	 * @var string $_location = ''
	 */
	private $_location = '';

	/**
	 * @var bool $_minimize = true
	 */
	private $_minimize = true;

	/**
	 * @var IQuarkViewResource[] $_dependencies = []
	 */
	private $_dependencies = array();

	/**
	 * @var bool $_local = true
	 */
	private $_local = true;

	/**
	 * @param string $location
	 * @param IQuarkViewResourceType $type
	 * @param bool $minimize = true
	 * @param IQuarkViewResource[] $dependencies = []
	 */
	public function __construct ($location, IQuarkViewResourceType $type, $minimize = true, $dependencies = []) {
		$this->_location = $location;
		$this->_type = $type;
		$this->_minimize = $minimize;
		$this->_dependencies = $dependencies;
	}

	/**
	 * @param bool $local = true
	 *
	 * @return bool
	 */
	public function Local ($local = true) {
		if (func_num_args() != 0)
			$this->_local = $local;

		return $this->_local;
	}

	/**
	 * @return IQuarkViewResourceType
	 */
	public function Type () {
		return $this->_type;
	}

	/**
	 * @return string
	 */
	public function Location () {
		return $this->_location;
	}

	/**
	 * @return bool
	 */
	public function CacheControl () {
		return $this->_minimize;
	}

	/**
	 * @return void
	 */
	public function RequestDTO () {
		// TODO: Implement RequestDTO() method.
	}

	/**
	 * @param bool $local
	 *
	 * @return bool
	 */
	public function LocationControl ($local) {
		return $this->_local;
	}

	/**
	 * @return IQuarkViewResource[]
	 */
	public function Dependencies () {
		return $this->_dependencies;
	}

	/**
	 * @param IQuarkViewResource|string $location
	 * @param bool $minimize = true
	 * @param IQuarkViewResource[] $dependencies = []
	 *
	 * @return QuarkGenericViewResource|IQuarkViewResource
	 */
	public static function CSS ($location, $minimize = true, $dependencies = []) {
		return $location instanceof IQuarkViewResource
			? $location
			: ($location === null
				? null
				: new self($location, new QuarkCSSViewResourceType(), $minimize, $dependencies)
			);
	}

	/**
	 * @param IQuarkViewResource|string $location
	 * @param bool $minimize = true
	 * @param IQuarkViewResource[] $dependencies = []
	 *
	 * @return QuarkGenericViewResource|IQuarkViewResource
	 */
	public static function JS ($location, $minimize = true, $dependencies = []) {
		return $location instanceof IQuarkViewResource
			? $location
			: ($location === null
				? null
				: new self($location, new QuarkJSViewResourceType(), $minimize, $dependencies)
			);
	}
}

/**
 * Class QuarkLocalCoreJSViewResource
 *
 * @package Quark
 */
class QuarkLocalCoreJSViewResource implements IQuarkSpecifiedViewResource, IQuarkLocalViewResource {
	/**
	 * @return IQuarkViewResourceType
	 */
	public function Type () {
		return new QuarkJSViewResourceType();
	}

	/**
	 * @return string
	 */
	public function Location () {
		return __DIR__ . '/Quark.js';
	}

	/**
	 * @return bool
	 */
	public function CacheControl () {
		return true;
	}
}

/**
 * Class QuarkLocalCoreCSSViewResource
 *
 * @package Quark
 */
class QuarkLocalCoreCSSViewResource implements IQuarkSpecifiedViewResource, IQuarkLocalViewResource {
	/**
	 * @return IQuarkViewResourceType
	 */
	public function Type () {
		return new QuarkCSSViewResourceType();
	}

	/**
	 * @return string
	 */
	public function Location () {
		return __DIR__ . '/Quark.css';
	}

	/**
	 * @return bool
	 */
	public function CacheControl () {
		return true;
	}
}

/**
 * Trait QuarkInlineViewResource
 *
 * @package Quark
 */
trait QuarkInlineViewResource {
	/**
	 * @var string $_code
	 */
	private $_code = '';

	/**
	 * @param string $code
	 */
	public function __construct ($code = '') {
		$this->_code = $code;
	}

	/**
	 * @return void
	 */
	public function Location () { }

	/**
	 * @return void
	 */
	public function Type () { }

	/**
	 * @return bool
	 */
	public function CacheControl () {
		return true;
	}
}

/**
 * Class QuarkInlineCSSViewResource
 *
 * @package Quark
 */
class QuarkInlineCSSViewResource implements IQuarkViewResource, IQuarkLocalViewResource, IQuarkInlineViewResource, IQuarkMultipleViewResource {
	use QuarkInlineViewResource;

	/**
	 * @return string
	 */
	public function HTML () {
		return '<style type="text/css">' . $this->_code . '</style>';
	}
}

/**
 * Class QuarkInlineJSViewResource
 *
 * @package Quark
 */
class QuarkInlineJSViewResource implements IQuarkViewResource, IQuarkLocalViewResource, IQuarkInlineViewResource, IQuarkMultipleViewResource {
	use QuarkInlineViewResource;

	/**
	 * @info EXTERNAL_FRAGMENT need to suppress the PHPStorm 8+ invalid spell check
	 * @return string
	 */
	public function HTML () {
		return '<script type="text/javascript">var EXTERNAL_FRAGMENT;' . $this->_code . '</script>';
	}
}

/**
 * Class QuarkProxyJSViewResource
 *
 * @package Quark
 */
class QuarkProxyJSViewResource implements IQuarkViewResource, IQuarkLocalViewResource, IQuarkInlineViewResource, IQuarkMultipleViewResource {
	const PROXY_SESSION_VAR = 'session_user';

	use QuarkInlineViewResource;

	/**
	 * @param $var
	 * @param $value
	 */
	public function __construct ($var, $value) {
		$this->_code = 'var ' . $var . '=' . \json_encode($value) . ';';
	}

	/**
	 * @info EXTERNAL_FRAGMENT need to suppress the PHPStorm 8+ invalid spell check
	 * @return string
	 */
	public function HTML () {
		return '<script type="text/javascript">var EXTERNAL_FRAGMENT;' . $this->_code . '</script>';
	}

	/**
	 * @param QuarkModel|IQuarkAuthorizableModel $user = null
	 * @param array $fields = null
	 * @param string $var = self::PROXY_SESSION_VAR
	 *
	 * @return QuarkProxyJSViewResource
	 */
	public static function ForSession (QuarkModel $user = null, $fields = null, $var = self::PROXY_SESSION_VAR) {
		return new self($var, $user == null ? 'null' : $user->Extract($fields));
	}
}

/**
 * Trait QuarkLexingViewResourceBehavior
 *
 * @package Quark
 */
trait QuarkLexingViewResourceBehavior {
	/**
	 * @param string $content = ''
	 * @param bool $full = false
	 *
	 * @return string
	 */
	private static function _htmlTo ($content = '', $full = false) {
		return $full
			? preg_replace(/** @lang text */'#\<\!DOCTYPE html\>\<html\>\<head\>\<title\>\<\/title\>\<style type\=\"text\/css\"\>(.*)\<\/style\>\<\/head\>\<body\>(.*)\<\/body\>\<\/html\>#Uis', '$2', $content)
			: $content;
	}

	/**
	 * @param string $content = ''
	 * @param bool $full = false
	 * @param string $css = ''
	 *
	 * @return string
	 */
	private static function _htmlFrom ($content = '', $full = false, $css = '') {
		return $full
			? '<!DOCTYPE html><html><head><title></title><style type="text/css">' . $css . '</style></head><body>' . $content . '</body></html>'
			: $content;
	}

	/**
	 * @param string $content = ''
	 *
	 * @return string
	 */
	public static function Styles ($content = '') {
		return preg_replace(/** @lang text */'#\<\!DOCTYPE html\>\<html\>\<head\>\<title\>\<\/title\>\<style type\=\"text\/css\"\>(.*)\<\/style\>\<\/head\>\<body\>(.*)\<\/body\>\<\/html\>#Uis', '$1', $content);
	}
}

/**
 * Trait QuarkCombinedViewResource
 *
 * @package Quark
 */
trait QuarkCombinedViewResourceBehavior {
	/**
	 * @param bool $minimize = true
	 *
	 * @return QuarkGenericViewResource
	 */
	public function ViewResourceStylesheet ($minimize = true) {
		return $this instanceof IQuarkCombinedViewResource
			? QuarkGenericViewResource::CSS($this->LocationStylesheet(), $minimize)
			: null;
	}

	/**
	 * @param bool $minimize = true
	 *
	 * @return QuarkGenericViewResource
	 */
	public function ViewResourceController ($minimize = true) {
		return $this instanceof IQuarkCombinedViewResource
			? QuarkGenericViewResource::JS($this->LocationController(), $minimize)
			: null;
	}
}

/**
 * Interface IQuarkViewResourceType
 *
 * @package Quark
 */
interface IQuarkViewResourceType {
	/**
	 * @param $location
	 * @param $content
	 *
	 * @return string
	 */
	public function Container($location, $content);
}

/**
 * Class QuarkCSSViewResourceType
 *
 * @package Quark
 */
class QuarkCSSViewResourceType implements IQuarkViewResourceType {
	/**
	 * @param $location
	 * @param $content
	 *
	 * @return string
	 */
	public function Container ($location, $content) {
		return strlen($location) != 0
			? '<link rel="stylesheet" type="text/css" href="' . $location . '" />'
			: '<style type="text/css">' . $content . '</style>';
	}
}

/**
 * Class QuarkJSViewResourceType
 *
 * @package Quark
 */
class QuarkJSViewResourceType implements IQuarkViewResourceType {
	/**
	 * @param $location
	 * @param $content
	 *
	 * @return string
	 */
	public function Container ($location, $content) {
		return /** @lang text */'<script type="text/javascript"' . (strlen($location) != 0 ? ' src="' . $location . '"' : '') . '>' . $content . '</script>';
	}
}

/**
 * Interface IQuarkViewFragment
 *
 * @package Quark
 */
interface IQuarkViewFragment {
	/**
	 * @return string
	 */
	public function CompileFragment();
}

/**
 * Trait QuarkCollectionBehavior
 *
 * @package Quark
 */
trait QuarkCollectionBehavior {
	/**
	 * @var array $_collection = []
	 */
	private $_collection = array();
	
	/**
	 * @return array
	 */
	public function Collection () {
		return $this->_collection;
	}
	
	/**
	 * @param $document = null
	 * @param array $query = []
	 *
	 * @return bool
	 */
	public function Match ($document = null, $query = []) {
		if (is_scalar($query) || is_null($query)) return $document == $query;
		if (is_scalar($document) || is_null($document))
			return $this->_matchTarget($document, $query);
		
		$out = true;
		$outChanged = false;
		
		foreach ($query as $key => &$rule) {
			$len = sizeof($key);
			
			if ($len == 0) continue;
			
			if (preg_match('#\.#', $key)) {
				$nodes = explode('.', $key);
				$parent = $nodes[0];
				
				if (!isset($document->$parent)) return false;
				
				$newKey = implode('.', array_slice($nodes, 1));
				
				if (is_object($document->$parent))
					return $this->Match($document->$parent, array($newKey => $rule));
				
				if (is_array($document->$parent))
					foreach ($document->$parent as $item)
						if ($this->Match($item, array($newKey => $rule))) return true;
				
				continue;
			}
			
			if ($key[0] == '$') {
				$aggregate = str_replace('$', '_aggregate_', $key);
				
				if (method_exists($this, $aggregate))
					$this->_matchOut($out, $outChanged, $this->$aggregate($document, $rule));
				
				continue;
			}
			
			if (isset($document->$key))
				$this->_matchOut($out, $outChanged, $this->_matchTarget($document->$key, $rule));
		}
		
		return $outChanged ? $out : false;
	}
	
	/**
	 * @param bool $out
	 * @param bool $outChanged
	 * @param bool $state
	 */
	private function _matchOut (&$out, &$outChanged, $state) {
		if (!$outChanged) {
			$out = true;
			$outChanged = true;
		}
		
		$out &= $state;
	}
	
	/**
	 * @param string $role
	 * @param $document
	 * @param array $query
	 * @param $append = null
	 *
	 * @return bool
	 */
	private function _matchDocument ($role, $document, $query, $append = null) {
		$out = true;
		
		foreach ($query as $state => $expected) {
			$hook = str_replace('$', $role, $state);
			$out &= method_exists($this, $hook)
				? $this->$hook($document, $expected, $append)
				: false;
		};
		
		return $out;
	}
	
	/**
	 * @param $target
	 * @param $rule
	 *
	 * @return bool
	 */
	private function _matchTarget ($target, $rule) {
		if (is_scalar($rule) || is_null($rule) || is_object($rule))
			return $target == $rule;
		
		$isoDate = null;
		$matcher = '_array_';
				
		if (!is_array($target)) {
			$date = QuarkField::DateTime($target);
			$isoDate = $date ? QuarkDate::From($target) : null;
			$matcher = '_compare_';
		}
				
		return $this->_matchDocument($matcher, $target, $rule, $isoDate);
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @param $document
	 * @param array $rule
	 *
	 * @return bool
	 */
	private function _aggregate_and ($document, $rule) {
		$state = true;
		
		foreach ($rule as $item)
			$state &= $this->Match($document, $item);
					
		return $state;
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @param $document
	 * @param array $rule
	 *
	 * @return bool
	 */
	private function _aggregate_nand ($document, $rule) {
		$state = true;
		
		foreach ($rule as $item)
			$state &= $this->Match($document, $item);
					
		return !$state;
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @param $document
	 * @param array $rule
	 *
	 * @return bool
	 */
	private function _aggregate_or ($document, $rule) {
		$state = false;
		
		foreach ($rule as $item)
			$state |= $this->Match($document, $item);
					
		return $state;
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @param $document
	 * @param array $rule
	 *
	 * @return bool
	 */
	private function _aggregate_nor ($document, $rule) {
		$state = false;
		
		foreach ($rule as $item)
			$state |= $this->Match($document, $item);
					
		return !$state;
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @param $document
	 * @param array $rule
	 *
	 * @return bool
	 */
	private function _aggregate_not ($document, $rule) {
		$state = true;
		
		foreach ($rule as $item)
			$state &= !$this->Match($document, $item);
					
		return $state;
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @param $property
	 * @param $expected
	 *
	 * @return bool
	 */
	private function _array_elemMatch ($property, $expected) {
		$out = false;
		
		foreach ($property as $item)
			$out |= $this->Match($item, $expected);
		
		return $out;
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @note This iterator behaves different from MongoDB: it match that ALL array elements match the criteria
	 *
	 * @param $property
	 * @param $expected
	 *
	 * @return bool
	 */
	private function _array_all ($property, $expected) {
		$out = true;
		
		foreach ($expected as $item) {
			if (is_scalar($item) || is_null($item)) {
				$out &= in_array($item, $property);
				continue;
			}
			
			$query = $item;
			
			if (isset($item['$elemMatch']))
				$query = $item['$elemMatch'];
			
			foreach ($property as $entry)
				$out &= $this->Match($entry, $query);
		}
		
		return $out;
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @param $property
	 * @param $expected
	 *
	 * @return bool
	 */
	private function _array_size ($property, $expected) {
		return $this->Match(sizeof($property), $expected);
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @param $property
	 * @param $expected
	 *
	 * @return bool
	 */
	private function _compare_eq ($property, $expected) {
		return $property == $expected;
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @param $property
	 * @param $expected
	 *
	 * @return bool
	 */
	private function _compare_eq_s ($property, $expected) {
		return $property === $expected;
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @param $property
	 * @param $expected
	 *
	 * @return bool
	 */
	private function _compare_ne ($property, $expected) {
		return $property != $expected;
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @param $property
	 * @param $expected
	 *
	 * @return bool
	 */
	private function _compare_ne_s ($property, $expected) {
		return $property !== $expected;
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @param $property
	 * @param $expected
	 *
	 * @return bool
	 */
	private function _compare_in ($property, $expected) {
		return is_array($expected) ? in_array($property, $expected) : false;
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @param $property
	 * @param $expected
	 *
	 * @return bool
	 */
	private function _compare_nin ($property, $expected) {
		return is_array($expected) ? !in_array($property, $expected) : false;
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @param $property
	 * @param $expected
	 *
	 * @return bool
	 */
	private function _compare_in_s ($property, $expected) {
		return is_array($expected) ? in_array($property, $expected, true) : false;
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @param $property
	 * @param $expected
	 *
	 * @return bool
	 */
	private function _compare_nin_s ($property, $expected) {
		return is_array($expected) ? !in_array($property, $expected, true) : false;
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @param $property
	 * @param $expected
	 * @param QuarkDate $date = null
	 *
	 * @return bool
	 */
	private function _compare_lt ($property, $expected, QuarkDate $date = null) {
		return $date ? $date->Earlier(QuarkDate::From($expected)) : $property < $expected;
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @param $property
	 * @param $expected
	 * @param QuarkDate $date = null
	 *
	 * @return bool
	 */
	private function _compare_lte ($property, $expected, QuarkDate $date = null) {
		return $date ? $date->Earlier(QuarkDate::From($expected)) : $property <= $expected;
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @param $property
	 * @param $expected
	 * @param QuarkDate $date = null
	 *
	 * @return bool
	 */
	private function _compare_gt ($property, $expected, QuarkDate $date = null) {
		return $date ? $date->Later(QuarkDate::From($expected)) : $property > $expected;
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @param $property
	 * @param $expected
	 * @param QuarkDate $date = null
	 *
	 * @return bool
	 */
	private function _compare_gte ($property, $expected, QuarkDate $date = null) {
		return $date ? $date->Later(QuarkDate::From($expected)) : $property >= $expected;
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @param $property
	 * @param $expected
	 *
	 * @return bool
	 */
	private function _compare_regex ($property, $expected) {
		return preg_match($expected, $property);
	}
	
	/** @noinspection PhpUnusedPrivateMethodInspection
	 *
	 * @param $document
	 * @param array $rule
	 *
	 * @return bool
	 */
	private function _compare_not ($document, $rule) {
		return !$this->Match($document, $rule);
	}
	
	/**
	 * @param array $query = []
	 * @param array $options = []
	 *
	 * @return array
	 */
	public function Select ($query = [], $options = []) {
		if (!QuarkObject::isTraversable($query)) return array();
		if (!QuarkObject::isTraversable($this->_collection)) return array();
		
		$out = array();
		
		if (sizeof($query) == 0) $out = $this->_collection;
		else foreach ($this->_collection as $i => &$item) {
			if ($this->Match($item, $query))
				$out[] = $item;
		}
		
		return $this->_slice($out, $options);
	}

	/**
	 * @param array $query = []
	 * @param array $options = []
	 *
	 * @return mixed|null
	 */
	public function SelectOne ($query = [], $options = []) {
		$options[QuarkModel::OPTION_LIMIT] = 1;

		$out = $this->Select($query, $options);

		return sizeof($out) == 0 ? null : $out[0];
	}
	
	/**
	 * @param array $list = []
	 * @param array $options = []
	 * @param bool $preserveKeys = null
	 *
	 * @return array
	 */
	private function _slice ($list = [], $options = [], $preserveKeys = null) {
		if (isset($options[QuarkModel::OPTION_SORT]) && QuarkObject::isTraversable($options[QuarkModel::OPTION_SORT])) {
			$sort = $options[QuarkModel::OPTION_SORT];
			
			/**
			 * http://wp-kama.ru/question/php-usort-sortirovka-massiva-po-dvum-polyam
			 */
			usort($list, function ($a, $b) use ($sort) {
				$res = 0;
				
				/** @noinspection PhpUnusedLocalVariableInspection */
				$a = (object)$a;
				/** @noinspection PhpUnusedLocalVariableInspection */
				$b = (object)$b;
		
				foreach ($sort as $key => $mode) {
					$accessor = str_replace('.', '->', str_replace('->', '', $key));
					
					$elem_a = eval('return isset($a->' . $accessor . ') ? $a->' . $accessor . ' : null;');
					$elem_b = eval('return isset($b->' . $accessor . ') ? $b->' . $accessor . ' : null;');
					
					$dir = $mode;
					
					if (!is_string($elem_a) && !is_string($elem_b)) {
						if ($elem_a == $elem_b) continue;
						$res = $elem_a < $elem_b ? -1 : 1;
					}
					else {
						$elem_a = (string)$elem_a;
						$elem_b = (string)$elem_b;
						
						$nat = isset($mode['$natural']);
						$icase = isset($mode['$icase']);
						
						if (is_array($mode)) {
							if ($nat) $dir = $mode['$natural'];
							if ($icase) $dir = $mode['$icase'];
						}
						
						$res = $nat
							? ($icase ? strnatcasecmp($elem_a, $elem_b) : strnatcmp($elem_a, $elem_b))
							: ($icase ? strcasecmp($elem_a, $elem_b) : strcmp($elem_a, $elem_b));
						
						if ($res == 0) continue;
					}
					
					if ($dir == QuarkModel::SORT_DESC) $res = -$res;
					break;
				}
				
				return $res;
			});
		}
		
		$skip = isset($options[QuarkModel::OPTION_SKIP])
			? (int)$options[QuarkModel::OPTION_SKIP]
			: 0;
		
		$limit = isset($options[QuarkModel::OPTION_LIMIT]) && $options[QuarkModel::OPTION_LIMIT] !== null
			? (int)$options[QuarkModel::OPTION_LIMIT]
			: null;
		
		return array_slice($list, $skip, $limit, $preserveKeys);
	}
	
	/**
	 * @param array $query = []
	 * @param array|callable $update
	 * @param array $options = []
	 *
	 * @return int
	 */
	public function Change ($query = [], $update = null, $options = []) {
		if (!QuarkObject::isTraversable($query)) return 0;
		if (!QuarkObject::isTraversable($this->_collection)) return 0;
		
		$size = sizeof($query);
		$change = array();
		
		if (!isset($options[QuarkModel::OPTION_FORCE_DEFINITION]))
			$options[QuarkModel::OPTION_FORCE_DEFINITION] = false;
		
		foreach ($this->_collection as $i => &$item)
			if ($size == 0 || $this->Match($item, $query))
				$change[$i] = $item;
		
		$change = $this->_slice($change, $options, true);
		$keys = array_keys($change);
		
		foreach ($keys as &$i) {
			if (is_callable($update)) $update($this->_collection[$i]);
			else {
				if ($options[QuarkModel::OPTION_FORCE_DEFINITION]) $this->_collection[$i] = $update;
				else $this->_change($i, $update);
			}
		}
		
		return sizeof($change);
	}
	
	/**
	 * @param int $i
	 * @param $update
	 */
	private function _change ($i, $update) {
		if (!QuarkObject::isTraversable($update)) return;
		
		foreach ($update as $key => &$value) {
			$val = QuarkObject::isAssociative($value) ? (array)$value : $value;
			
			if (isset($this->_collection[$i]->$key) && is_numeric($this->_collection[$i]->$key)) {
				if (isset($val['$inc'])) $this->_collection[$i]->$key += $val['$inc'];
				elseif (isset($val['$dec'])) $this->_collection[$i]->$key -= $val['$dec'];
			}
			else $this->_collection[$i]->$key = $value;
		}
	}
	
	/**
	 * @param array $query = []
	 * @param array $options = []
	 * @param bool $preserveKeys = false
	 *
	 * @return int
	 */
	public function Purge ($query = [], $options = [], $preserveKeys = false) {
		if (!QuarkObject::isTraversable($query)) return 0;
		if (!QuarkObject::isTraversable($this->_collection)) return 0;
		
		$size = sizeof($query);
		$purge = array();
		
		foreach ($this->_collection as $i => &$item)
			if ($size == 0 || $this->Match($item, $query))
				$purge[$i] = $item;
		
		$purge = $this->_slice($purge, $options, true);

		/** @noinspection PhpUnusedLocalVariableInspection */
		foreach ($purge as $i => &$item)
			unset($this->_collection[$i]);
		
		if (!$preserveKeys)
			$this->_collection = array_values($this->_collection);
		
		return sizeof($purge);
	}
	
	/**
	 * Count elements of an object
	 *
	 * @param array $query = []
	 * @param array $options = []
	 *
	 * @link http://php.net/manual/en/countable.count.php
	 * @return int The custom count as an integer.
	 * </p>
	 * <p>
	 * The return value is cast to an integer.
	 * @since 5.1.0
	 */
	public function Count ($query = [], $options = []) {
		return sizeof(func_num_args() == 0
			? $this->_collection
			: $this->Select($query, $options)
		);
	}

	/**
	 * @param array $options = []
	 *
	 * @return array
	 */
	public function Aggregate ($options = []) {
		return $this->_slice($this->_collection, $options);
	}

	/**
	 * @param array $initial = []
	 * @param callable $navigator = null
	 *
	 * @return void
	 */
	public function Navigate ($initial = [], callable $navigator = null) {
		if ($navigator == null) return;

		$item = $this->SelectOne($initial);
		$next = $navigator($item);

		if ($next === null) return;

		$this->Navigate($next, $navigator);
	}
}

/**
 * Class QuarkCollection
 *
 * @package Quark
 */
class QuarkCollection implements \Iterator, \ArrayAccess, \Countable {
	use QuarkCollectionBehavior {
		Select as private _select;
		Aggregate as private _aggregate;
	}
	
	/**
	 * @var IQuarkModel|mixed $_type  = null
	 */
	private $_type = null;
	
	/**
	 * @var bool $_model = true
	 */
	private $_model = true;

	/**
	 * @var int $_index = 0
	 */
	private $_index = 0;
	
	/**
	 * @var int $_page = 0
	 */
	private $_page = 0;
	
	/**
	 * @var int $_pages = 0
	 */
	private $_pages = 0;

	/**
	 * @param object $type
	 * @param array $source = []
	 */
	public function __construct ($type, $source = []) {
		$this->_type = $type;
		$this->_model = $this->_type instanceof IQuarkModel;
		
		$this->PopulateWith($source);
	}

	/**
	 * @return mixed
	 */
	public function Type () {
		return $this->_type;
	}

	/**
	 * @param $item
	 *
	 * @return bool
	 */
	public function TypeIs ($item) {
		return $item instanceof $this->_type || ($item instanceof QuarkModel && $item->Model() instanceof $this->_type) || ($this->_type instanceof \stdClass && is_object($item));
	}

	/**
	 * @param $item
	 *
	 * @return bool
	 */
	public function Add ($item) {
		if (!$this->TypeIs($item)) return false;

		$this->_collection[] = !$this->_model || $item instanceof QuarkModel ? $item : new QuarkModel($item);

		return true;
	}

	/**
	 * @param $needle
	 * @param callable $compare
	 *
	 * @return bool
	 */
	public function Remove ($needle, callable $compare) {
		if (!$this->TypeIs($needle)) return false;

		foreach ($this->_collection as $key => &$item)
			if ($compare($item, $needle, $key))
				unset($this->_collection[$key]);

		return true;
	}

	/**
	 * @param $source
	 *
	 * @return QuarkCollection
	 */
	public function Instance ($source) {
		if ($this->_type instanceof IQuarkModel)
			$this->_collection[] = new QuarkModel($this->_type, $source);

		return $this;
	}

	/**
	 * @return QuarkCollection
	 */
	public function Reverse () {
		$this->_collection = array_reverse($this->_collection);

		return $this;
	}
	
	/**
	 * @return QuarkCollection
	 */
	public function Shuffle () {
		shuffle($this->_collection);
		
		return $this;
	}

	/**
	 * @param $needle
	 * @param callable $compare
	 *
	 * @return bool
	 */
	public function In ($needle, callable $compare) {
		foreach ($this->_collection as $key => &$item)
			if ($compare($item, $needle, $key)) return true;

		return false;
	}

	/**
	 * @param array $source
	 * @param callable $iterator = null
	 *
	 * @return QuarkCollection
	 */
	public function PopulateWith ($source, callable $iterator = null) {
		if ($source instanceof QuarkCollection)
			$source = $source->_collection;

		if (is_array($source)) {
			$this->_collection = array();

			foreach ($source as $key => &$item)
				$this->Add($iterator == null ? $item : $iterator($item, $key));
		}

		return $this;
	}

	/**
	 * @param array $source = []
	 *
	 * @return QuarkCollection
	 */
	public function PopulateModelsWith ($source = []) {
		return $this->PopulateWith($source, function ($item) {
			return new QuarkModel($this->_type, $item);
		});
	}

	/**
	 * @param callable $iterator = null
	 *
	 * @return array
	 */
	public function Collection (callable $iterator = null) {
		$output = array();

		foreach ($this->_collection as $key => &$item)
			$output[] = $iterator == null ? $item : $iterator($item, $key);

		return $output;
	}

	/**
	 * @param array $fields = null
	 * @param bool $weak = false
	 *
	 * @return array
	 */
	public function Extract ($fields = null, $weak = false) {
		if (!($this->_type instanceof IQuarkModel)) return $this->_collection;

		$out = array();

		foreach ($this->_collection as $key => &$item)
			/**
			 * @var QuarkModel $item
			 */
			$out[] = $item->Extract($fields, $weak);

		return $out;
	}

	/**
	 * @return QuarkCollection
	 */
	public function Flush () {
		$this->_collection = array();
		$this->_index = 0;

		return $this;
	}
	
	/**
	 * @param int $page = 0
	 *
	 * @return int
	 */
	public function Page ($page = 0) {
		if (func_num_args() != 0)
			$this->_page = $page;
		
		return $this->_page;
	}
	
	/**
	 * @param int $pages = 0
	 *
	 * @return int
	 */
	public function Pages ($pages = 0) {
		if (func_num_args() != 0)
			$this->_pages = $pages;
		
		return $this->_pages;
	}
	
	/**
	 * @param array $query = []
	 * @param array $options = []
	 *
	 * @return QuarkCollection
	 */
	public function Select ($query = [], $options = []) {
		return new self($this->_type, $this->_select($query, $options));
	}

	/**
	 * @param array $options = []
	 *
	 * @return QuarkCollection
	 */
	public function Aggregate ($options = []) {
		return new self($this->_type, $this->_aggregate($options));
	}

	/**
	 * @return QuarkCollection|IQuarkLinkedModel[]
	 */
	public function RetrieveLazy () {
		$out = new self($this->_type->Model());
		$model = null;

		foreach ($this->_collection as $i => &$item) {
			/**
			 * @var QuarkLazyLink|IQuarkLinkedModel $item
			 * @var QuarkLazyLink $model
			 */
			$model = clone $item->Model();
			$out[] = $model->Retrieve();
		}

		return $out;
	}

	/**
	 * @param QuarkModel|IQuarkLinkedModel $item
	 * @param $value = null
	 *
	 * @return bool
	 */
	public function AddLazy ($item, $value = null) {
		$type = $this->_type->Model();
		$add = $item instanceof QuarkModel ? $item->Model() : $item;
		$typeOk = $add instanceof $type;

		return $typeOk ? $this->Add(new QuarkLazyLink($add, $value, true)) : false;
	}

	/**
	 * @param IQuarkLinkedModel $model
	 * @param $value = null
	 *
	 * @return QuarkCollection|QuarkLazyLink[]|IQuarkLinkedModel[]
	 */
	public static function Lazy (IQuarkLinkedModel $model, $value = null) {
		return new self(new QuarkLazyLink($model, $value));
	}
	
	/**
	 * (PHP 5 &gt;= 5.0.0)<br/>
	 * Return the current element
	 *
	 * @link http://php.net/manual/en/iterator.current.php
	 * @return mixed Can return any type.
	 */
	public function current () {
		return $this->_collection[$this->_index];
	}

	/**
	 * (PHP 5 &gt;= 5.0.0)<br/>
	 * Move forward to next element
	 *
	 * @link http://php.net/manual/en/iterator.next.php
	 * @return void Any returned value is ignored.
	 */
	public function next () {
		$this->_index++;
	}

	/**
	 * (PHP 5 &gt;= 5.0.0)<br/>
	 * Return the key of the current element
	 *
	 * @link http://php.net/manual/en/iterator.key.php
	 * @return mixed scalar on success, or null on failure.
	 */
	public function key () {
		return $this->_index;
	}

	/**
	 * (PHP 5 &gt;= 5.0.0)<br/>
	 * Checks if current position is valid
	 *
	 * @link http://php.net/manual/en/iterator.valid.php
	 * @return boolean The return value will be casted to boolean and then evaluated.
	 *       Returns true on success or false on failure.
	 */
	public function valid () {
		return isset($this->_collection[$this->_index]);
	}

	/**
	 * (PHP 5 &gt;= 5.0.0)<br/>
	 * Rewind the Iterator to the first element
	 *
	 * @link http://php.net/manual/en/iterator.rewind.php
	 * @return void Any returned value is ignored.
	 */
	public function rewind () {
		$this->_index = 0;
	}

	/**
	 * (PHP 5 &gt;= 5.0.0)<br/>
	 * Whether a offset exists
	 *
	 * @link http://php.net/manual/en/arrayaccess.offsetexists.php
	 *
	 * @param mixed $offset <p>
	 *                      An offset to check for.
	 *                      </p>
	 *
	 * @return boolean true on success or false on failure.
	 * </p>
	 * <p>
	 * The return value will be casted to boolean if non-boolean was returned.
	 */
	public function offsetExists ($offset) {
		return isset($this->_collection[$offset]);
	}

	/**
	 * (PHP 5 &gt;= 5.0.0)<br/>
	 * Offset to retrieve
	 *
	 * @link http://php.net/manual/en/arrayaccess.offsetget.php
	 *
	 * @param mixed $offset <p>
	 *                      The offset to retrieve.
	 *                      </p>
	 *
	 * @return mixed Can return all value types.
	 */
	public function offsetGet ($offset) {
		return isset($this->_collection[$offset]) ? $this->_collection[$offset] : null;
	}

	/**
	 * (PHP 5 &gt;= 5.0.0)<br/>
	 * Offset to set
	 *
	 * @link http://php.net/manual/en/arrayaccess.offsetset.php
	 *
	 * @param mixed $offset <p>
	 *                      The offset to assign the value to.
	 *                      </p>
	 * @param mixed $value  <p>
	 *                      The value to set.
	 *                      </p>
	 *
	 * @return void
	 */
	public function offsetSet ($offset, $value) {
		if (!$this->TypeIs($value)) return;

		if ($offset === null) $this->_collection[] = $value;
		else $this->_collection[(int)$offset] = $value;
	}

	/**
	 * (PHP 5 &gt;= 5.0.0)<br/>
	 * Offset to unset
	 *
	 * @link http://php.net/manual/en/arrayaccess.offsetunset.php
	 *
	 * @param mixed $offset <p>
	 *                      The offset to unset.
	 *                      </p>
	 *
	 * @return void
	 */
	public function offsetUnset ($offset) {
		unset($this->_collection[(int)$offset]);
	}
}

/**
 * Trait QuarkModelBehavior
 *
 * @package Quark
 */
trait QuarkModelBehavior {
	use QuarkContainerBehavior;

	/** @noinspection PhpUnusedPrivateMethodInspection
	 * @return QuarkModel
	 */
	private function _envelope () {
		return new QuarkModel($this);
	}

	/**
	 * @return string
	 */
	public function Pk () {
		return $this->__call('PrimaryKey', func_get_args());
	}

	/**
	 * @return QuarkKeyValuePair
	 */
	public function DataProviderPk () {
		$source = $this->Source();

		return $source instanceof QuarkModelSource ? $source->Provider()->PrimaryKey($this->Model()) : null;
	}

	/**
	 * @param bool $runtime = true
	 *
	 * @return array|null
	 */
	public function FieldKeys ($runtime = true) {
		return $this->__call('FieldKeys', func_get_args());
	}

	/**
	 * @param string[] $exclude = []
	 * @param bool $runtime = true
	 * 
	 * @return array|null
	 */
	public function FieldValues ($exclude = [], $runtime = true) {
		return $this->__call('FieldValues', func_get_args());
	}

	/**
	 * @param bool $runtime = true
	 *
	 * @return array
	 */
	public function PropertyKeys ($runtime = true) {
		return $this->__call('PropertyKeys', func_get_args());
	}

	/**
	 * @param string[] $exclude = []
	 * @param bool $runtime = true
	 * 
	 * @return array
	 */
	public function PropertyValues ($exclude = [], $runtime = true) {
		return $this->__call('PropertyValues', func_get_args());
	}
	
	/**
	 * @param $default
	 *
	 * @return callable
	 */
	public function Nullable ($default) {
		$store = $default;
		$stored = false;
		
		/** @noinspection PhpUnusedParameterInspection
		 *
		 * @param string $key
		 * @param mixed $value
		 * @param bool $changed
		 *
		 * @return mixed
		 */
		return function ($key, $value, $changed) use ($default, &$store, &$stored) {
			if ($changed) {
				if (is_scalar($value))
					settype($value, gettype($default));
					
				$store = $value;
				$stored = true;
			}
			
			return $stored ? $store : null;
		};
	}

	/**
	 * @param array $options
	 *
	 * @return mixed
	 */
	public function Create ($options = []) {
		return $this->__call('Create', func_get_args());
	}

	/**
	 * @param array $options
	 *
	 * @return mixed
	 */
	public function Save ($options = []) {
		return $this->__call('Save', func_get_args());
	}

	/**
	 * @param array $options
	 *
	 * @return mixed
	 */
	public function Remove ($options = []) {
		return $this->__call('Remove', func_get_args());
	}

	/**
	 * @return bool
	 */
	public function Validate () {
		return $this->__call('Validate', func_get_args());
	}

	/**
	 * @param $source
	 *
	 * @return QuarkModel
	 */
	public function PopulateWith ($source) {
		return $this->__call('PopulateWith', func_get_args());
	}

	/**
	 * @param array $fields = null
	 * @param bool $weak = false
	 *
	 * @return \stdClass
	 */
	public function Extract ($fields = null, $weak = false) {
		return $this->__call('Extract', func_get_args());
	}

	/**
	 * @return QuarkModelSource
	 */
	public function Source () {
		return $this->__call('Source', func_get_args());
	}
	
	/**
	 * @return bool[]
	 */
	public function ValidationRules () {
		return $this->__call('ValidationRules', func_get_args());
	}

	/**
	 * @return QuarkKeyValuePair[]
	 */
	public function RawValidationErrors () {
		return $this->__call('RawValidationErrors', func_get_args());
	}

	/**
	 * @param string $language = QuarkLanguage::ANY
	 *
	 * @return string[]
	 */
	public function ValidationErrors ($language = QuarkLanguage::ANY) {
		return $this->__call('ValidationErrors', func_get_args());
	}
	
	/**
	 * @return string
	 */
	public function Operation () {
		return $this->__call('Operation', func_get_args());
	}

	/**
	 * @return IQuarkModel
	 */
	public function Model () {
		return $this->__call('Model', func_get_args());
	}

	/**
	 * @return QuarkModel
	 */
	public function User () {
		return QuarkSession::Current() ? QuarkSession::Current()->User() : null;
	}
	
	/**
	 * @param bool $rule
	 * @param QuarkLocalizedString $message = null
	 * @param string $field = ''
	 *
	 * @return bool
	 */
	public function Assert ($rule, QuarkLocalizedString $message = null, $field = '') {
		return QuarkField::Assert($rule, $message, $field);
	}
	
	/**
	 * @param bool $rule
	 * @param string|array $message = ''
	 * @param string $field = ''
	 *
	 * @return bool
	 */
	public function LocalizedAssert ($rule, $message = '', $field = '') {
		return QuarkField::LocalizedAssert($rule, $message, $field);
	}
	
	/**
	 * @param string $field = ''
	 * @param string[] $op = [QuarkModel::OPERATION_CREATE]
	 *
	 * @return bool
	 */
	public function Unique ($field = '', $op = [QuarkModel::OPERATION_CREATE]) {
		return $this instanceof IQuarkModel ? QuarkField::Unique($this, $field, $op) : false;
	}

	/**
	 * @param array $options
	 * @param string $key = ''
	 *
	 * @return mixed
	 */
	public function UserOption ($options, $key = '') {
		return !isset($options[QuarkModel::OPTION_USER_OPTIONS])
			? null
			: (func_num_args() == 2
				? (isset($options[QuarkModel::OPTION_USER_OPTIONS][$key])
					? $options[QuarkModel::OPTION_USER_OPTIONS][$key]
					: null
				)
				: $options[QuarkModel::OPTION_USER_OPTIONS]
			);
	}
}

/**
 * Class QuarkModelSource
 *
 * @package Quark
 */
class QuarkModelSource implements IQuarkStackable {
	/**
	 * @var IQuarkDataProvider $_provider
	 */
	private $_provider;

	/**
	 * @var $_connection
	 */
	private $_connection;

	/**
	 * @var QuarkURI $_uri
	 */
	private $_uri;

	/**
	 * @var string $_name = ''
	 */
	private $_name = '';

	/**
	 * @var bool $_connected = false
	 */
	private $_connected = false;

	/**
	 * @param string $name
	 * @param IQuarkDataProvider $provider
	 * @param QuarkURI $uri
	 */
	public function __construct ($name, IQuarkDataProvider $provider, QuarkURI $uri = null) {
		$this->_name = $name;
		$this->_provider = $provider;
		$this->_uri = $uri;
	}

	/**
	 * @param string $name
	 */
	public function Stacked ($name) { }

	/**
	 * @param $method
	 * @param $args
	 *
	 * @return mixed
	 */
	public function __call ($method, $args) {
		return method_exists($this->_provider, $method)
			? call_user_func_array(array($this->_provider, $method), $args)
			: null;
	}

	/**
	 * @return QuarkModelSource
	 *
	 * @throws QuarkArchException
	 */
	public function &Connect () {
		if ($this->_uri == null)
			throw new QuarkArchException('[QuarkModelSource::Connect] Unable to connect ' . $this->_name . ': connection URI is null');

		$this->_connection = $this->_provider->Connect($this->_uri);
		$this->_connected = true;

		return $this;
	}

	/**
	 * @return mixed
	 */
	public function &Connection () {
		return $this->_connection;
	}

	/**
	 * @param IQuarkDataProvider $provider
	 *
	 * @return IQuarkDataProvider
	 */
	public function &Provider (IQuarkDataProvider $provider = null) {
		if (func_num_args() != 0)
			$this->_provider = $provider;

		return $this->_provider;
	}

	/**
	 * @param QuarkURI $uri
	 *
	 * @return QuarkURI
	 */
	public function &URI (QuarkURI $uri = null) {
		if (func_num_args() != 0)
			$this->_uri = $uri;

		return $this->_uri;
	}

	/**
	 * @return bool
	 */
	public function &Connected () {
		return $this->_connected;
	}

	/**
	 * @param string $name
	 * @param IQuarkDataProvider $provider
	 * @param QuarkURI $uri
	 *
	 * @return QuarkModelSource|IQuarkStackable
	 */
	public static function Register ($name, IQuarkDataProvider $provider, QuarkURI $uri) {
		return Quark::Component($name, new self($name, $provider, $uri));
	}

	/**
	 * @param string $name
	 *
	 * @return QuarkModelSource|IQuarkStackable
	 */
	public static function Get ($name) {
		return Quark::Component($name);
	}
}

/**
 * Class QuarkModel
 *
 * @package Quark
 */
class QuarkModel implements IQuarkContainer {
	const OPTION_SORT = 'sort';
	const OPTION_SKIP = 'skip';
	const OPTION_LIMIT = 'limit';

	const OPTION_COLLECTION = 'collection';
	const OPTION_FIELDS = 'fields';

	const OPTION_EXTRACT = 'extract';
	const OPTION_VALIDATE = 'validate';
	const OPTION_EXPORT_SUB_MODEL = 'export_sub';
	const OPTION_REVERSE = 'reverse';

	const OPTION_USER_OPTIONS = '___user___';
	const OPTION_FORCE_DEFINITION = '___force_definition___';

	const OPERATION_CREATE = 'Create';
	const OPERATION_SAVE = 'Save';
	const OPERATION_REMOVE = 'Remove';
	const OPERATION_EXPORT = 'Export';

	const SORT_ASC = 1;
	const SORT_DESC = -1;
	const LIMIT_NO = '___limit_no___';
	const LIMIT_RANDOM = 1;
	const LIMIT_PAGED = 25;

	const CONFIG_VALIDATION_ALL = 'model.validation.all';
	const CONFIG_VALIDATION_STORE = 'model.validation.store';

	/**
	 * @var IQuarkModel|IQuarkStrongModelWithRuntimeFields|QuarkModelBehavior $_model = null
	 */
	private $_model = null;

	/**
	 * @var QuarkKeyValuePair[] $_errors
	 */
	private $_errors = array();

	/**
	 * @var bool $_default = false
	 */
	private $_default = false;

	/**
	 * @var string $_op = ''
	 */
	private $_op = '';

	/**
	 * @var QuarkKeyValuePair[] $_errorFlux
	 */
	private static $_errorFlux = array();

	/**
	 * @param IQuarkModel|QuarkModelBehavior $model
	 * @param $source
	 */
	public function __construct (IQuarkModel $model, $source = null) {
		/**
		 * Attention!
		 * Cloning need to opposite non-controlled passing by reference
		 */
		$this->_model = clone $model;

		if (func_num_args() == 1) {
			$source = $model;
			$this->_default = true;
		}

		if ($source instanceof QuarkModel)
			$source = $source->Model();

		Quark::Container($this);

		$this->PopulateWith($source);
	}

	/**
	 * @param $key
	 *
	 * @return mixed
	 */
	public function &__get ($key) {
		return $this->_model->$key;
	}

	/**
	 * @param $key
	 * @param $value
	 */
	public function __set ($key, $value) {
		$this->_model->$key = $this->Field($key) instanceof IQuarkModel && $value instanceof IQuarkModel ? new QuarkModel($value) : $value;
	}

	/**
	 * @param $key
	 *
	 * @return bool
	 */
	public function __isset ($key) {
		return isset($this->_model->$key);
	}

	/**
	 * @param $key
	 */
	public function __unset ($key) {
		unset($this->_model->$key);
	}

	/**
	 * @param $method
	 * @param $args
	 *
	 * @throws QuarkArchException
	 * @return mixed
	 */
	public function __call ($method, $args) {
		if (method_exists($this->_model, $method))
			return call_user_func_array(array($this->_model, $method), $args);

		$model = $this->_model == null ? 'null' : get_class($this->_model);

		if ($this->_model instanceof IQuarkModelWithDataProvider) {
			$provider = self::_provider($this->_model)->Provider();
			array_unshift($args, $this->_model);

			if (method_exists($provider, $method))
				return call_user_func_array(array($provider, $method), $args);

			$model .= ' or provider ' . ($provider == null ? 'null' : get_class($provider));
		}

		throw new QuarkArchException('Method ' . $method . ' not found in model ' . $model);
	}

	/**
	 * @return string
	 */
	public function __toString () {
		return method_exists($this->_model, '__toString') ? (string)$this->_model : '';
	}

	/**
	 * @var IQuarkModel|QuarkModelBehavior $model = null
	 *
	 * @return IQuarkModel|QuarkModelBehavior
	 */
	public function &Model ($model = null) {
		if (func_num_args() != 0)
			$this->_model = $model;

		return $this->_model;
	}

	/**
	 * @param IQuarkPrimitive $primitive = null
	 *
	 * @return IQuarkPrimitive
	 */
	public function &Primitive (IQuarkPrimitive $primitive = null) {
		if (func_num_args() != 0)
			$this->_model = $primitive;

		return $this->_model;
	}

	/**
	 * @param QuarkURI|string $uri = ''
	 *
	 * @return QuarkModelSource|IQuarkDataProvider
	 * @throws QuarkArchException
	 */
	public function Source ($uri = '') {
		return isset($this->_model) ? self::_provider($this->_model, $uri) : null;
	}

	/**
	 * @param $source
	 *
	 * @return QuarkModel
	 */
	public function PopulateWith ($source) {
		if ($this->_model instanceof IQuarkModelWithBeforePopulate) {
			$out = $this->_model->BeforePopulate($source);

			if ($out === false) return $this;
		}

		$this->_model = self::_import($this->_model, $source, $this->_default);

		if ($this->_model instanceof IQuarkModelWithAfterPopulate) {
			$out = $this->_model->AfterPopulate($source);

			if ($out === false)
				$this->_model = null;
		}

		return $this;
	}

	/**
	 * @param IQuarkModel $model
	 * @param QuarkURI|string $uri
	 *
	 * @return QuarkModelSource|IQuarkDataProvider
	 * @throws QuarkArchException
	 */
	private static function _provider (IQuarkModel $model, $uri = '') {
		if (!($model instanceof IQuarkModelWithDataProvider))
			throw new QuarkArchException('Attempt to get data provider of model ' . get_class($model) . ' which is not defined as IQuarkModelWithDataProvider');

		$name = $model->DataProvider();
		$source = null;

		try {
			$source = Quark::Stack($name);
		}
		catch (\Exception $e) { }

		if (!($source instanceof QuarkModelSource))
			throw new QuarkArchException('Model source for model ' . get_class($model) . ' is not connected');

		if ($uri)
			$source->URI(QuarkURI::FromURI($uri));

		return $uri || !$source->Connected() ? $source->Connect() : $source;
	}

	/**
	 * @param string $key
	 * @param string $value = ''
	 *
	 * @return array
	 */
	public static function StructureFromKey ($key, $value = '') {
		$structure = explode('.', $key);

		return array($structure[0] => sizeof($structure) == 1
			? $value
			: self::StructureFromKey(substr($key, strpos($key, '.') + 1), $value)
		);
	}

	/**
	 * @param IQuarkModel|IQuarkModelWithCustomCollectionName $model
	 * @param array $options
	 *
	 * @return string
	 */
	public static function CollectionName (IQuarkModel $model = null, $options = []) {
		if (isset($options[QuarkModel::OPTION_COLLECTION]))
			return $options[QuarkModel::OPTION_COLLECTION];

		if ($model instanceof IQuarkModelWithCustomCollectionName) {
			$name = $model->CollectionName();

			if ($name !== null)
				return $name;
		}

		return QuarkObject::ClassOf($model);
	}

	/**
	 * @param $model
	 * @param $field
	 *
	 * @return QuarkModel|null
	 */
	public static function Build ($model, $field) {
		return $field == null && $model instanceof IQuarkNullableModel
			? null
			: new QuarkModel($model);
	}

	/**
	 * @param IQuarkModel $model
	 * @param array       $fields
	 *
	 * @return IQuarkModel
	 */
	private static function _normalize (IQuarkModel $model, $fields = []) {
		if (func_num_args() == 1 || (!is_array($fields) && !is_object($fields)))
			$fields = $model->Fields();

		if ($model instanceof IQuarkStrongModelWithRuntimeFields)
			$fields = array_replace($fields, (array)$model->RuntimeFields());

		$output = $model;

		if (!is_array($fields) && !is_object($fields)) return $output;
		
		foreach ($fields as $key => &$field) {
			/**
			 * @var mixed|callable $field
			 */
			if (is_int($key) && $field instanceof QuarkKeyValuePair) {
				$fields[$field->Key()] = $field->Value();
				unset($fields[$key]);

				$key = $field->Key();
				$field = $field->Value();
			}

			if ($key == '') continue;
			
			if (isset($model->$key)) {
				if (self::_callableField($field)) $output->$key = $field($key, $model->$key, !is_callable($model->$key));
				else {
					$output->$key = $model->$key;
					
					if (is_scalar($field) && is_scalar($model->$key))
						settype($output->$key, gettype($field));
				}
			}
			else $output->$key = $field instanceof IQuarkModel
				? QuarkModel::Build($field, empty($model->$key) ? null : $model->$key)
				: (is_callable($field)
					? $field($key, null, false)
					: $field
				);
		}

		return $output;
	}

	/**
	 * @param IQuarkModel|QuarkModelBehavior $model
	 * @param $source
	 * @param bool $default = false
	 *
	 * @return IQuarkModel|QuarkModelBehavior
	 */
	private static function _import (IQuarkModel $model, $source, $default = false) {
		if (!is_array($source) && !is_object($source)) return $model;

		$fields = (array)$model->Fields();

		if ($model instanceof IQuarkStrongModelWithRuntimeFields)
			$fields = array_replace($fields, (array)$model->RuntimeFields());

		if (!$default && $model instanceof IQuarkModelWithDataProvider && ($model instanceof IQuarkModelWithManageableDataProvider ? $model->DataProviderForSubModel($source) : true)) {
			/**
			 * @var IQuarkModel $model
			 */
			$ppk = self::_provider($model)->PrimaryKey($model);

			if ($ppk instanceof QuarkKeyValuePair) {
				$pk = $ppk->Key();

				if (!isset($fields[$pk]))
					$fields[$pk] = $ppk->Value();
			}
		}

		foreach ($source as $key => &$value) {
			if ($key == '') continue;
			if (!QuarkObject::PropertyExists($fields, $key) && $model instanceof IQuarkStrongModel) continue;

			$property = QuarkObject::Property($fields, $key, $value);

			if ($property instanceof QuarkCollection) {
				$class = $property->Type();

				$model->$key = $property->PopulateWith($value, function ($item) use ($key, $class) {
					return self::_link(clone $class, $item, $key);
				});
			}
			else $model->$key = self::_link($property, $value, $key);
		}

		unset($key, $value);

		return self::_normalize($model);
	}

	/**
	 * @param $property
	 * @param $value
	 * @param $key
	 *
	 * @return mixed|QuarkModel
	 */
	private static function _link ($property, $value, $key) {
		return $property instanceof IQuarkLinkedModel
			? ($value instanceof QuarkModel ? $value : $property->Link(QuarkObject::isAssociative($value) ? (object)$value : $value))
			: ($property instanceof IQuarkModel
				? ($property instanceof IQuarkNullableModel && $value == null ? null : new QuarkModel($property, $value))
				: (self::_callableField($property) ? $property($key, $value, true) : $value)
			);
	}

	/**
	 * @param $property
	 *
	 * @return bool
	 */
	private static function _callableField ($property) {
		return !is_scalar($property) && is_callable($property);
	}

	/**
	 * @param IQuarkModel|QuarkModelBehavior $model
	 * @param $options
	 *
	 * @return IQuarkModel|QuarkModelBehavior|bool
	 */
	private static function _export (IQuarkModel $model, $options = []) {
		$fields = self::_normalizeFields($model);
		$forceDefinition = isset($options[self::OPTION_FORCE_DEFINITION]) && $options[self::OPTION_FORCE_DEFINITION];

		if (!isset($options[self::OPTION_VALIDATE]))
			$options[self::OPTION_VALIDATE] = true;

		if (!$forceDefinition && $options[self::OPTION_VALIDATE] && !self::_validate($model)) return false;

		$output = self::_normalize(clone $model);

		foreach ($model as $key => &$value) {
			if ($key == '') continue;

			if (!QuarkObject::PropertyExists($fields, $key) && $model instanceof IQuarkStrongModel) {
				unset($output->$key);
				continue;
			}

			if ($value instanceof QuarkCollection) {
				$output->$key = $value->Collection(function ($item) use ($fields, $key) {
					return self::_unlink(isset($fields[$key]) ? $fields[$key] : null, $item, $key);
				});
			}
			else $output->$key = self::_unlink(isset($fields[$key]) ? $fields[$key] : null, $value, $key);
		}
		
		if ($forceDefinition)
			foreach ($output as $key => &$value)
				if (!isset($model->$key))
					unset($output->$key);

		unset($key, $value);

		return $output;
	}

	/**
	 * @param $property
	 * @param mixed|callable $value
	 * @param $key
	 *
	 * @return mixed|IQuarkModel
	 */
	private static function _unlink ($property, $value, $key) {
		if ($value instanceof QuarkModel)
			$value = self::_export($value->Model());

		return $value instanceof IQuarkLinkedModel
			? $value->Unlink()
			: (self::_callableField($property) ? $property($key, $value, !is_callable($value)) : $value);
	}

	/**
	 * @param IQuarkModel $model
	 *
	 * @return mixed
	 */
	private static function _normalizeFields (IQuarkModel $model) {
		$fields = $model->Fields();

		if (!QuarkObject::isTraversable($fields)) return $fields;

		foreach ($fields as $key => &$field) {
			if (!is_int($key) || (!$field instanceof QuarkKeyValuePair)) continue;

			$fields[$field->Key()] = $field->Value();
			unset($fields[$key]);
		}
		
		return $fields;
	}

	/**
	 * @param IQuarkModel|QuarkModelBehavior $model
	 * @param bool $check = true
	 *
	 * @return bool|array
	 */
	private static function _validate (IQuarkModel $model, $check = true) {
		QuarkField::FlushValidationErrors();

		if ($model instanceof IQuarkNullableModel && sizeof((array)$model) == 0) return true;

		$output = $model;

		if ($model instanceof IQuarkStrongModel) {
			$fields = (array)$model->Fields();

			if ($model instanceof IQuarkStrongModelWithRuntimeFields)
				$fields = array_replace($fields, (array)$model->RuntimeFields());

			if (is_array($fields) || is_object($fields))
				foreach ($fields as $key => $field) {
					if ($key == '' || isset($model->$key)) continue;

					$output->$key = $field instanceof IQuarkModel
						? QuarkModel::Build($field, empty($model->$key) ? null : $model->$key)
						: $field;
				}
		}

		if ($output instanceof IQuarkModelWithBeforeValidate && $output->BeforeValidate() === false) return false;

		$valid = $check ? QuarkField::Rules($model->Rules()) : $model->Rules();
		self::$_errorFlux = array_merge(self::$_errorFlux, QuarkField::FlushValidationErrors());
		
		foreach ($output as $key => $value) {
			if ($key == '' || !($value instanceof QuarkModel)) continue;

			if ($check) $valid &= $value->Validate();
			else $valid[$key] = $value->ValidationRules();
		}

		return $valid;
	}

	/**
	 * @param IQuarkModel|QuarkModelBehavior $model
	 * @param mixed $data
	 * @param array $options
	 * @param callable $after = null
	 *
	 * @return QuarkModel|QuarkModelBehavior|\stdClass
	 */
	private static function _record (IQuarkModel $model, $data, $options = [], callable $after = null) {
		if ($data == null) return null;

		$output = new QuarkModel($model, $data);

		$model = $output->Model();

		if ($model instanceof IQuarkModelWithAfterFind)
			$model->AfterFind($data, $options);

		if ($after) {
			$buffer = $after($output);

			if ($buffer === false) return null;
			if ($buffer !== null) $output = $buffer;
		}

		if ($output === null) return null;

		if ($output instanceof QuarkModel && is_array($options) && isset($options[self::OPTION_EXTRACT]) && $options[self::OPTION_EXTRACT] !== false)
			$output = $options[self::OPTION_EXTRACT] === true
				? $output->Extract()
				: $output->Extract($options[self::OPTION_EXTRACT]);

		return $output;
	}

	/**
	 * @param bool $subModel = false
	 * 
	 * @return IQuarkModel|QuarkModelBehavior|bool
	 */
	public function Export ($subModel = false) {
		$model = self::_export($this->_model);

		$ok = $subModel && $model instanceof IQuarkModelWithAfterExport
			? $model->AfterExport(self::OPERATION_EXPORT, array(
				self::OPTION_EXPORT_SUB_MODEL => $subModel
			))
			: true;

		return $ok || $ok === null ? $model : null;
	}

	/**
	 * @param array $fields = null
	 * @param bool $weak = false
	 *
	 * @return \stdClass
	 */
	public function Extract ($fields = null, $weak = false) {
		if ($this->_model instanceof IQuarkPolymorphicModel) {
			$morph = $this->_model->PolymorphicExtract();

			if ($morph !== null) return $morph;
		}

		$output = new \stdClass();

		$model = clone $this->_model;

		if ($model instanceof IQuarkModelWithBeforeExtract) {
			$out = $model->BeforeExtract($fields, $weak);

			if ($out !== null)
				return $out;
		}

		if ($model instanceof IQuarkModelWithDefaultExtract)
			$fields = $model->DefaultExtract($fields, $weak);

		foreach ($model as $key => $value) {
			if ($key == '') continue;

			$property = QuarkObject::Property($fields, $key, null);

			$output->$key = $value instanceof QuarkModel
				? $value->Extract($property)
				: ($value instanceof QuarkCollection
					? $value->Collection(function ($item) use ($property) {
						return $item instanceof QuarkModel ? $item->Extract($property) : $item;
					})
					: $value);
		}

		if ($fields === null) return $output;

		$buffer = new \stdClass();
		$property = null;

		$backbone = (array)($weak ? $model->Fields() : $fields);

		foreach ($backbone as $field => $rule) {
			if (property_exists($output, $field))
				$buffer->$field = QuarkObject::Property($output, $field, null);

			if ($weak && !isset($fields[$field])) continue;
			else {
				if (is_string($rule) && property_exists($output, $rule))
					$buffer->$rule = QuarkObject::Property($output, $rule, null);
			}
		}

		if ($model instanceof IQuarkModelWithAfterExtract) {
			$out = $model->AfterExtract($buffer, $fields, $weak);

			if ($out !== null)
				return $out;
		}

		return $buffer;
	}

	/**
	 * @return bool
	 */
	public function Validate () {
		$validate = self::_validate($this->_model);
		$this->_errors = self::$_errorFlux;

		return $validate;
	}

	/**
	 * @return bool[]
	 */
	public function ValidationRules () {
		return self::_validate($this->_model, false);
	}

	/**
	 * @return QuarkKeyValuePair[]
	 */
	public function RawValidationErrors () {
		return $this->_errors;
	}

	/**
	 * @param string $language = QuarkLanguage::ANY
	 *
	 * @return string[]
	 */
	public function ValidationErrors ($language = QuarkLanguage::ANY) {
		$out = array();

		foreach ($this->_errors as $error)
			$out[] = $error->Value()->Of($language);

		return $out;
	}

	/**
	 * @return string
	 */
	public function Operation () {
		return $this->_op;
	}

	/**
	 * @param string $name
	 *
	 * @return mixed
	 */
	public function Field ($name) {
		$fields = (object)$this->_model->Fields();

		return isset($fields->$name) ? $fields->$name : null;
	}
	
	/**
	 * @param bool $runtime = true
	 *
	 * @return array|null
	 */
	public function FieldKeys ($runtime = true) {
		$fields = $this->_model->Fields();
		if (!QuarkObject::isAssociative($fields)) return null;
		
		$out = array();
		
		foreach ($fields as $key => $value)
			$out[] = $key;
		
		if ($runtime && $this->_model instanceof IQuarkStrongModelWithRuntimeFields) {
			$fields = $this->_model->RuntimeFields();
			
			if (QuarkObject::isAssociative($fields))
				foreach ($fields as $key => $value)
					$out[] = $key;
		}
		
		return $out;
	}
	
	/**
	 * @param string[] $exclude = []
	 * @param bool $runtime = true
	 * 
	 * @return array|null
	 */
	public function FieldValues ($exclude = [], $runtime = true) {
		$fields = $this->_model->Fields();
		if (!QuarkObject::isAssociative($fields)) return null;
		
		$out = array();
		
		foreach ($fields as $key => $value)
			if (!in_array($key, $exclude))
				$out[] = $value;
		
		if ($runtime && $this->_model instanceof IQuarkStrongModelWithRuntimeFields) {
			$fields = $this->_model->RuntimeFields();
			
			if (QuarkObject::isAssociative($fields))
				foreach ($fields as $key => $value)
					$out[] = $value;
		}
		
		return $out;
	}
	
	/**
	 * @param bool $runtime = true
	 *
	 * @return array
	 */
	public function PropertyKeys ($runtime = true) {
		$out = array();
		$fields = $this->FieldKeys($runtime);
		
		foreach ($this->_model as $key => $value)
			if (!($this->_model instanceof IQuarkStrongModel) || in_array($key, $fields))
				$out[] = $key;
		
		return $out;
	}
	
	/**
	 * @param string[] $exclude = []
	 * @param bool $runtime = true
	 * 
	 * @return array
	 */
	public function PropertyValues ($exclude = [], $runtime = true) {
		$out = array();
		$fields = $this->FieldKeys($runtime);
		
		foreach ($this->_model as $key => $value)
			if (!in_array($key, $exclude) && (!($this->_model instanceof IQuarkStrongModel) || in_array($key, $fields)))
				$out[] = $value;
		
		return $out;
	}

	/**
	 * @param string $name
	 * @param array $options = []
	 *
	 * @return bool
	 */
	private function _op ($name, $options = []) {
		$name = ucfirst(strtolower($name));
		$this->_op = $name;

		$hook = 'Before' . $name;
		$ok = QuarkObject::is($this->_model, 'Quark\IQuarkModelWith' . $hook)
			? $this->_model->$hook($options)
			: true;

		if ($ok !== null && !$ok) return false;

		if ($name == self::OPERATION_REMOVE && !isset($options[self::OPTION_VALIDATE]) && Quark::Config()->ModelValidation() == self::CONFIG_VALIDATION_STORE)
			$options[self::OPTION_VALIDATE] = false;

		$model = self::_export($this->_model, $options);
		$this->_errors = self::$_errorFlux;
		$this->_op = '';

		if (!$model) return false;

		$ok = $model instanceof IQuarkModelWithAfterExport
			? $model->AfterExport($name, $options)
			: true;

		if ($ok !== null && !$ok) return false;

		$out = self::_provider($model)->$name($model, $options);

		$this->PopulateWith($model);

		$hook = 'After' . $name;
		$ok = QuarkObject::is($this->_model, 'Quark\IQuarkModelWith' . $hook)
			? $this->_model->$hook($options)
			: true;

		if ($ok !== null && !$ok) return false;

		return $out;
	}

	/**
	 * @param array $options = []
	 *
	 * @return mixed
	 */
	public function Create ($options = []) {
		return $this->_op(self::OPERATION_CREATE, $options);
	}

	/**
	 * @param array $options = []
	 *
	 * @return mixed
	 */
	public function Save ($options = []) {
		return $this->_op(self::OPERATION_SAVE, $options);
	}

	/**
	 * @param array $options = []
	 *
	 * @return mixed
	 */
	public function Remove ($options = []) {
		return $this->_op(self::OPERATION_REMOVE, $options);
	}

	/**
	 * @return mixed
	 * @throws QuarkArchException
	 */
	public function PrimaryKey () {
		if ($this->_model == null) return null;

		$pk = self::_provider($this->_model)->PrimaryKey($this->_model)->Key();

		if ($this->_model instanceof IQuarkModelWithCustomPrimaryKey)
			$pk = $this->_model->PrimaryKey();

		return (string)$this->$pk;
	}

	/**
	 * @param IQuarkModel|QuarkModelBehavior $model
	 * @param $criteria = []
	 * @param array $options = []
	 * @param callable(QuarkModel $model) $after = null
	 *
	 * @return QuarkCollection|array
	 */
	public static function Find (IQuarkModel $model, $criteria = [], $options = [], callable $after = null) {
		$records = array();

		if (isset($options[self::OPTION_LIMIT]) && $options[self::OPTION_LIMIT] == self::LIMIT_NO)
			unset($options[self::OPTION_LIMIT]);

		$raw = self::_provider($model)->Find($model, $criteria, $options);

		if ($raw != null)
			foreach ($raw as $item)
				$records[] = self::_record($model, $item, $options, $after);

		if (isset($options[self::OPTION_REVERSE]))
			$records = array_reverse($records);

		return isset($options[self::OPTION_EXTRACT])
			? $records
			: new QuarkCollection($model, $records);
	}

	/**
	 * @param IQuarkModel|QuarkModelBehavior $model
	 * @param $criteria = []
	 * @param array $options = []
	 * @param callable(QuarkModel $model) $after = null
	 *
	 * @return QuarkModel|\stdClass
	 */
	public static function FindOne (IQuarkModel $model, $criteria = [], $options = [], callable $after = null) {
		return self::_record($model, self::_provider($model)->FindOne($model, $criteria, $options), $options, $after);
	}

	/**
	 * @param IQuarkModel|QuarkModelBehavior $model
	 * @param $id
	 * @param array $options = []
	 * @param callable(QuarkModel $model) $after = null
	 *
	 * @return QuarkModel|\stdClass
	 */
	public static function FindOneById (IQuarkModel $model, $id, $options = [], callable $after= null) {
		return self::_record($model, self::_provider($model)->FindOneById($model, $id, $options), $options, $after);
	}

	/**
	 * @param IQuarkModel|QuarkModelBehavior $model
	 * @param $criteria = []
	 * @param array $options = []
	 *
	 * @return QuarkCollection|array
	 */
	public static function FindRandom (IQuarkModel $model, $criteria = [], $options = []) {
		$count = self::Count($model, $criteria);
		
		if (!isset($options[self::OPTION_SKIP]))
			$options[self::OPTION_SKIP] = mt_rand(0, $count == 0 ? 0 : $count - 1);
		
		if (!isset($options[self::OPTION_LIMIT]))
			$options[self::OPTION_LIMIT] = self::LIMIT_RANDOM;
		
		return self::Find($model, $criteria, $options);
	}
	
	/**
	 * @param IQuarkModel|QuarkModelBehavior $model
	 * @param int $page = 1
	 * @param array $criteria = []
	 * @param array $options = []
	 *
	 * @return QuarkCollection|array
	 */
	public static function FindByPage (IQuarkModel $model, $page = 1, $criteria = [], $options = []) {
		if (!isset($options[self::OPTION_LIMIT]))
			$options[self::OPTION_LIMIT] = self::LIMIT_PAGED;
		
		$pages = 1;
		$page = (int)$page;
		if ($page < 1) $page = 1;
		
		if ($options[self::OPTION_LIMIT] != self::LIMIT_NO) {
			$options[self::OPTION_LIMIT] = (int)$options[self::OPTION_LIMIT];
			
			if ($options[self::OPTION_LIMIT] < 1)
				$options[self::OPTION_LIMIT] = 1;
		
			$pages = (int)ceil(self::Count($model, $criteria) / $options[self::OPTION_LIMIT]);
		}
		
		if (!isset($options[self::OPTION_SKIP]))
			$options[self::OPTION_SKIP] = ($page - 1) * $options[self::OPTION_LIMIT];
		
		$out = self::Find($model, $criteria, $options);
		
		$out->Page($page);
		$out->Pages($pages);
		
		return $out;
	}

	/**
	 * @param IQuarkModel|QuarkModelBehavior $model
	 * @param $criteria = []
	 * @param int $limit = 0
	 * @param int $skip = 0
	 * @param array $options = []
	 *
	 * @return int
	 */
	public static function Count (IQuarkModel $model, $criteria = [], $limit = 0, $skip = 0, $options = []) {
		return (int)self::_provider($model)->Count($model, $criteria, $limit, $skip, $options);
	}

	/**
	 * @param IQuarkModel|QuarkModelBehavior $model
	 * @param $criteria = []
	 * @param array $options = []
	 *
	 * @return mixed
	 */
	public static function Update (IQuarkModel $model, $criteria = [], $options = []) {
		if (!isset($options[self::OPTION_FORCE_DEFINITION]))
			$options[self::OPTION_FORCE_DEFINITION] = true;
		
		$model = self::_export($model, $options);

		if (!$model) return false;
		
		$ok = $model instanceof IQuarkModelWithBeforeSave
			? $model->BeforeSave($options)
			: true;

		return ($ok || $ok === null) ? self::_provider($model)->Update($model, $criteria, $options) : false;
	}

	/**
	 * @param IQuarkModel|QuarkModelBehavior $model
	 * @param $criteria = []
	 * @param $options = []
	 *
	 * @return mixed
	 */
	public static function Delete (IQuarkModel $model, $criteria = [], $options = []) {
		$ok = $model instanceof IQuarkModelWithBeforeRemove
			? $model->BeforeRemove($options)
			: true;

		return ($ok || $ok === null) ? self::_provider($model)->Delete($model, $criteria, $options) : false;
	}
}

/**
 * Interface IQuarkModel
 *
 * @package Quark
 */
interface IQuarkModel extends IQuarkPrimitive {
	/**
	 * @return mixed
	 */
	public function Fields();

	/**
	 * @return mixed
	 */
	public function Rules();
}

/**
 * Interface IQuarkModelWithDataProvider
 *
 * @package Quark
 */
interface IQuarkModelWithDataProvider {
	/**
	 * @return string
	 */
	public function DataProvider();
}

/**
 * Interface IQuarkModelWithManageableDataProvider
 *
 * @package Quark
 */
interface IQuarkModelWithManageableDataProvider extends IQuarkModelWithDataProvider {
	/**
	 * @param $source
	 *
	 * @return bool
	 */
	public function DataProviderForSubModel($source);
}

/**
 * Interface IQuarkLinkedModel
 *
 * @package Quark
 */
interface IQuarkLinkedModel extends IQuarkModel {
	/**
	 * @param $raw
	 *
	 * @return mixed
	 */
	public function Link($raw);

	/**
	 * @return mixed
	 */
	public function Unlink();
}

/**
 * Interface IQuarkStrongModel
 *
 * @package Quark
 */
interface IQuarkStrongModel extends IQuarkModel { }

/**
 * Interface IQuarkStrongModelWithRuntimeFields
 *
 * @package Quark
 */
interface IQuarkStrongModelWithRuntimeFields extends IQuarkStrongModel {
	/**
	 * @return mixed
	 */
	public function RuntimeFields();
}

/**
 * Interface IQuarkNullableModel
 *
 * @package Quark
 */
interface IQuarkNullableModel { }

/**
 * Interface IQuarkPolymorphicModel
 *
 * @package Quark
 */
interface IQuarkPolymorphicModel {
	/**
	 * @return mixed
	 */
	public function PolymorphicExtract();
}

/**
 * Interface IQuarkModelWithCustomPrimaryKey
 *
 * @package Quark
 */
interface IQuarkModelWithCustomPrimaryKey {
	/**
	 * @return string
	 */
	public function PrimaryKey();
}
/**
 * Interface IQuarkModelWithCustomCollectionName
 *
 * @package Quark
 */
interface IQuarkModelWithCustomCollectionName {
	/**
	 * @return string
	 */
	public function CollectionName();
}

/**
 * Interface IQuarkModelWithAfterFind
 *
 * @package Quark
 */
interface IQuarkModelWithAfterFind {
	/**
	 * @param $raw
	 * @param array $options
	 *
	 * @return mixed
	 */
	public function AfterFind($raw, $options);
}

/**
 * Interface IQuarkModelWithBeforePopulate
 *
 * @package Quark
 */
interface IQuarkModelWithBeforePopulate {
	/**
	 * @param $raw
	 *
	 * @return mixed
	 */
	public function BeforePopulate($raw);
}

/**
 * Interface IQuarkModelWithAfterPopulate
 *
 * @package Quark
 */
interface IQuarkModelWithAfterPopulate {
	/**
	 * @param $raw
	 *
	 * @return mixed
	 */
	public function AfterPopulate($raw);
}

/**
 * Interface IQuarkModelWithBeforeSave
 *
 * @package Quark
 */
interface IQuarkModelWithBeforeCreate {
	/**
	 * @param $options
	 *
	 * @return mixed
	 */
	public function BeforeCreate($options);
}

/**
 * Interface IQuarkModelWithAfterCreate
 *
 * @package Quark
 */
interface IQuarkModelWithAfterCreate {
	/**
	 * @param $options
	 *
	 * @return mixed
	 */
	public function AfterCreate($options);
}

/**
 * Interface IQuarkModelWithBeforeSave
 *
 * @package Quark
 */
interface IQuarkModelWithBeforeSave {
	/**
	 * @param $options
	 *
	 * @return mixed
	 */
	public function BeforeSave($options);
}

/**
 * Interface IQuarkModelWithAfterSave
 *
 * @package Quark
 */
interface IQuarkModelWithAfterSave {
	/**
	 * @param $options
	 *
	 * @return mixed
	 */
	public function AfterSave($options);
}

/**
 * Interface IQuarkModelWithBeforeRemove
 *
 * @package Quark
 */
interface IQuarkModelWithBeforeRemove {
	/**
	 * @param $options
	 *
	 * @return mixed
	 */
	public function BeforeRemove($options);
}

/**
 * Interface IQuarkModelWithAfterRemove
 *
 * @package Quark
 */
interface IQuarkModelWithAfterRemove {
	/**
	 * @param $options
	 *
	 * @return mixed
	 */
	public function AfterRemove($options);
}
/**
 * Interface IQuarkModelWithAfterExport
 *
 * @package Quark
 */
interface IQuarkModelWithAfterExport {
	/**
	 * @param $operation
	 * @param $options
	 *
	 * @return mixed
	 */
	public function AfterExport($operation, $options);
}

/**
 * Interface IQuarkModelWithBeforeValidate
 *
 * @package Quark
 */
interface IQuarkModelWithBeforeValidate {
	/**
	 * @return mixed
	 */
	public function BeforeValidate();
}

/**
 * Interface IQuarkModelWithBeforeExtract
 *
 * @package Quark
 */
interface IQuarkModelWithBeforeExtract {
	/**
	 * @param array $fields
	 * @param bool $weak
	 *
	 * @return mixed
	 */
	public function BeforeExtract($fields, $weak);
}

/**
 * Interface IQuarkModelWithAfterExtract
 *
 * @package Quark
 */
interface IQuarkModelWithAfterExtract {
	/**
	 * @param $output
	 * @param $fields
	 * @param $weak
	 *
	 * @return mixed
	 */
	public function AfterExtract($output, $fields, $weak);
}

/**
 * Interface IQuarkModelWithDefaultExtract
 *
 * @package Quark
 */
interface IQuarkModelWithDefaultExtract {
	/**
	 * @param array $fields
	 * @param bool $weak
	 *
	 * @return array
	 */
	public function DefaultExtract($fields, $weak);
}

/**
 * Interface IQuarkApplicationSettingsModel
 *
 * @package Quark
 */
interface IQuarkApplicationSettingsModel extends IQuarkModel, IQuarkStrongModel, IQuarkModelWithDataProvider {
	/**
	 * @return array
	 */
	public function LoadCriteria();
}

/**
 * Interface IQuarkDataProvider
 *
 * @package Quark
 */
interface IQuarkDataProvider {
	/**
	 * @param QuarkURI $uri
	 *
	 * @return mixed
	 */
	public function Connect(QuarkURI $uri);

	/**
	 * @param IQuarkModel $model
	 *
	 * @return mixed
	 */
	public function Create(IQuarkModel $model);

	/**
	 * @param IQuarkModel $model
	 *
	 * @return mixed
	 */
	public function Save(IQuarkModel $model);

	/**
	 * @param IQuarkModel $model
	 *
	 * @return mixed
	 */
	public function Remove(IQuarkModel $model);

	/**
	 * @param IQuarkModel $model
	 *
	 * @return QuarkKeyValuePair
	 */
	public function PrimaryKey (IQuarkModel $model);

	/**
	 * @param IQuarkModel $model
	 * @param             $criteria
	 * @param             $options
	 *
	 * @return array
	 */
	public function Find(IQuarkModel $model, $criteria, $options);

	/**
	 * @param IQuarkModel $model
	 * @param             $criteria
	 * @param             $options
	 *
	 * @return mixed
	 */
	public function FindOne(IQuarkModel $model, $criteria, $options);

	/**
	 * @param IQuarkModel $model
	 * @param             $id
	 * @param             $options
	 *
	 * @return mixed
	 */
	public function FindOneById(IQuarkModel $model, $id, $options);

	/**
	 * @param IQuarkModel $model
	 * @param             $criteria
	 * @param             $options
	 *
	 * @return mixed
	 */
	public function Update(IQuarkModel $model, $criteria, $options);

	/**
	 * @param IQuarkModel $model
	 * @param             $criteria
	 * @param             $options
	 *
	 * @return mixed
	 */
	public function Delete(IQuarkModel $model, $criteria, $options);

	/**
	 * @param IQuarkModel $model
	 * @param             $criteria
	 * @param             $limit
	 * @param             $skip
	 * @param             $options
	 *
	 * @return int
	 */
	public function Count (IQuarkModel $model, $criteria, $limit, $skip, $options);
}

/**
 * Class QuarkField
 *
 * @package Quark
 */
class QuarkField {
	const TYPE_BOOL = 'bool';
	const TYPE_INT = 'int';
	const TYPE_FLOAT = 'float';
	const TYPE_STRING = 'string';

	const TYPE_ARRAY = 'array';
	const TYPE_OBJECT = 'object';

	const TYPE_RESOURCE = 'resource';
	const TYPE_NULL = 'null';

	const TYPE_DATE = 'QuarkDate';
	const TYPE_TIMESTAMP = '_timestamp';

	const ASSERT_LESS_THEN = '$lt';
	const ASSERT_LESS_THEN_OR_EQUAL = '$lte';
	const ASSERT_EQUAL = '$eq';
	const ASSERT_GREAT_THEN_OR_EQUAL = '$gte';
	const ASSERT_GREAT_THEN = '$gt';
	const ASSERT_IN = '$in';
	const ASSERT_NOT_EQUAL = '$ne';

	/**
	 * @var QuarkKeyValuePair[] $_errors
	 */
	private static $_errors = array();

	/**
	 * @var string $_name = ''
	 */
	private $_name = '';

	/**
	 * @var string $_type = self::TYPE_STRING
	 */
	private $_type = self::TYPE_STRING;

	/**
	 * @var string $_value = ''
	 */
	private $_value = '';

	/**
	 * @param string $name = ''
	 * @param string $type = self::TYPE_STRING
	 * @param string $value = ''
	 */
	public function __construct ($name = '', $type = self::TYPE_STRING, $value = '') {
		$this->_name = $name;
		$this->_type = $type;
		$this->_value = $value;
	}

	/**
	 * @param string $name = ''
	 *
	 * @return string
	 */
	public function Name ($name = '') {
		if (func_num_args() != 0)
			$this->_name = $name;

		return $this->_name;
	}

	/**
	 * @param string $type = self::TYPE_STRING
	 *
	 * @return string
	 */
	public function Type ($type = self::TYPE_STRING) {
		if (func_num_args() != 0)
			$this->_type = $type;

		return $this->_type;
	}

	/**
	 * @param string $value = ''
	 *
	 * @return string
	 */
	public function Value ($value = '') {
		if (func_num_args() != 0)
			$this->_value = $value;

		return $this->_value;
	}

	/**
	 * @return string
	 */
	public function StringifyValue () {
		if ($this->_type == self::TYPE_BOOL)
			return $this->_value ? 'true' : 'false';

		if ($this->_type == self::TYPE_INT)
			return $this->_value == 0 ? '0' : (int)$this->_value;

		if ($this->_type == self::TYPE_FLOAT)
			return $this->_value == 0 ? '0.0' : (float)$this->_value;

		if ($this->_type == self::TYPE_DATE)
			return 'new QuarkDate()';

		return $this->_value == 'null' ? 'null' : '\'' . $this->_value . '\'';
	}

	/**
	 * @param $property
	 *
	 * @return string
	 */
	public static function TypeOf ($property) {
		if (is_int($property)) return self::TYPE_INT;
		if (is_float($property)) return self::TYPE_FLOAT;
		if (is_bool($property)) return self::TYPE_BOOL;
		if (is_null($property)) return self::TYPE_NULL;
		if ($property instanceof QuarkDate) return self::TYPE_DATE;

		return self::TYPE_STRING;
	}

	/**
	 * @param $key
	 * @param bool $nullable = false
	 *
	 * @return bool
	 */
	public static function Valid ($key, $nullable = false) {
		if ($nullable && $key == null) return true;

		if ($key instanceof IQuarkModel)
			$key = new QuarkModel($key);

		return $key instanceof QuarkModel ? $key->Validate() : false;
	}

	/**
	 * @param $key
	 * @param string $type
	 * @param bool $nullable = false
	 *
	 * @return bool
	 */
	public static function is ($key, $type, $nullable = false) {
		if ($nullable && $key == null) return true;

		$comparator = 'is_' . $type;

		return $comparator($key);
	}

	/**
	 * @param $key
	 * @param $value
	 * @param bool $sever = false
	 * @param bool $nullable = false
	 *
	 * @return bool
	 */
	public static function Eq ($key, $value, $sever = false, $nullable = false) {
		if ($nullable && $key == null) return true;

		return $sever ? $key === $value : $key == $value;
	}

	/**
	 * @param $key
	 * @param $value
	 * @param bool $sever = false
	 * @param bool $nullable = false
	 *
	 * @return bool
	 */
	public static function Ne ($key, $value, $sever = false, $nullable = false) {
		if ($nullable && $key == null) return true;

		return $sever ? $key !== $value : $key != $value;
	}

	/**
	 * @param $key
	 * @param $value
	 * @param bool $nullable = false
	 *
	 * @return bool
	 */
	public static function Lt ($key, $value, $nullable = false) {
		if ($nullable && $key == null) return true;

		return $key < $value;
	}

	/**
	 * @param $key
	 * @param $value
	 * @param bool $nullable = false
	 *
	 * @return bool
	 */
	public static function Gt ($key, $value, $nullable = false) {
		if ($nullable && $key == null) return true;

		return $key > $value;
	}

	/**
	 * @param $key
	 * @param $value
	 * @param bool $nullable = false
	 *
	 * @return bool
	 */
	public static function Lte ($key, $value, $nullable = false) {
		if ($nullable && $key == null) return true;

		return $key <= $value;
	}

	/**
	 * @param $key
	 * @param $value
	 * @param bool $nullable = false
	 *
	 * @return bool
	 */
	public static function Gte ($key, $value, $nullable = false) {
		if ($nullable && $key == null) return true;

		return $key >= $value;
	}

	/**
	 * @param $key
	 * @param $value
	 * @param bool $nullable = false
	 *
	 * @return bool
	 */
	public static function MinLengthInclusive ($key, $value, $nullable = false) {
		if ($nullable && $key == null) return true;

		return is_array($key) ? sizeof($key) >= $value : strlen((string)$key) >= $value;
	}

	/**
	 * @param $key
	 * @param $value
	 * @param bool $nullable = false
	 *
	 * @return bool
	 */
	public static function MinLength ($key, $value, $nullable = false) {
		if ($nullable && $key == null) return true;

		return is_array($key) ? sizeof($key) > $value : strlen((string)$key) > $value;
	}

	/**
	 * @param $key
	 * @param $value
	 * @param bool $nullable = false
	 *
	 * @return bool
	 */
	public static function Length ($key, $value, $nullable = false) {
		if ($nullable && $key == null) return true;

		return is_array($key) ? sizeof($key) == $value : strlen((string)$key) == $value;
	}

	/**
	 * @param $key
	 * @param $value
	 * @param bool $nullable = false
	 *
	 * @return bool
	 */
	public static function MaxLength ($key, $value, $nullable = false) {
		if ($nullable && $key == null) return true;

		return is_array($key) ? sizeof($key) < $value : strlen((string)$key) < $value;
	}

	/**
	 * @param $key
	 * @param $value
	 * @param bool $nullable = false
	 *
	 * @return bool
	 */
	public static function MaxLengthInclusive ($key, $value, $nullable = false) {
		if ($nullable && $key == null) return true;

		return is_array($key) ? sizeof($key) <= $value : strlen((string)$key) <= $value;
	}

	/**
	 * @param $key
	 * @param $value
	 * @param bool $nullable
	 *
	 * @return bool|int
	 */
	public static function Match ($key, $value, $nullable = false) {
		if ($nullable && $key == null) return true;

		return preg_match($value, $key);
	}

	/**
	 * @param $key
	 * @param array $values = []
	 * @param bool $nullable = false
	 *
	 * @return bool
	 */
	public static function Enum ($key, $values = [], $nullable = false) {
		if ($nullable && $key == null) return true;

		return is_array($values) && in_array($key, $values, true);
	}

	/**
	 * @param string $type
	 * @param mixed $key
	 * @param bool $nullable = false
	 * @param IQuarkCulture $culture = null
	 *
	 * @return bool
	 */
	private static function _dateTime ($type, $key, $nullable = false, IQuarkCulture $culture = null) {
		if ($nullable && $key == null) return true;

		if ($culture == null)
			$culture = Quark::Config()->Culture();

		$format = $type . 'Format';

		/**
		 * code snippet from http://php.net/manual/ru/function.checkdate.php#113205
		 */
		$date = \DateTime::createFromFormat($culture->$format(), $key);

		return $date && $date->format($culture->$format()) == $key;
	}

	/**
	 * @param $key
	 * @param bool $nullable = false
	 * @param IQuarkCulture $culture = null
	 * @return bool|int
	 */
	public static function DateTime ($key, $nullable = false, IQuarkCulture $culture = null) {
		return self::_dateTime('DateTime', $key, $nullable, $culture);
	}

	/**
	 * @param $key
	 * @param bool $nullable = false
	 * @param IQuarkCulture $culture = null
	 * @return bool
	 */
	public static function Date ($key, $nullable = false, IQuarkCulture $culture = null) {
		return self::_dateTime('Date', $key, $nullable, $culture);
	}

	/**
	 * @param $key
	 * @param bool $nullable = false
	 * @param IQuarkCulture $culture = null
	 * @return bool
	 */
	public static function Time ($key, $nullable = false, IQuarkCulture $culture = null) {
		return self::_dateTime('Time', $key, $nullable, $culture);
	}

	/**
	 * @param $key
	 * @param bool $nullable = false
	 *
	 * @return bool
	 */
	public static function Email ($key, $nullable = false) {
		if ($nullable && $key == null) return true;
		if (!is_string($key)) return false;

		return preg_match('#(.*)\@(.*)#Uis', $key);
	}

	/**
	 * @param $key
	 * @param bool $nullable = false
	 *
	 * @return bool
	 */
	public static function Phone ($key, $nullable = false) {
		if ($nullable && $key == null) return true;
		if (!is_string($key)) return false;

		return preg_match('#^\+[0-9]#', $key);
	}

	/**
	 * https://tools.ietf.org/html/rfc3986#appendix-B
	 * 
	 * @param $key
	 * @param bool $nullable = false
	 *
	 * @return bool
	 */
	public static function URI ($key, $nullable = false) {
		if ($nullable && $key == null) return true;
		if (!is_string($key)) return false;

		return preg_match('#^([a-zA-Z0-9\-\+\.]+)\:\/\/((.*)(\:(.*))?\@)?(([a-zA-Z0-9\.\-]*)|(\[[\d\:]*\]))(\:[\d]*)?\/([a-zA-Z\\\%\&\=\!\#\$\^\(\)\[\]\{\}\~\`]*)#Uis', $key);
	}

	/**
	 * @param $key
	 * @param bool $type = false
	 * @param bool $nullable = false
	 *
	 * @return bool
	 */
	public static function Bool ($key, $type = false, $nullable = false) {
		if ($nullable && $key == null) return true;

		return preg_match('#^(true|false)$#Ui', QuarkObject::Stringify($key)) && ($type ? is_bool($key) : true);
	}

	/**
	 * @param $key
	 * @param bool $type = false
	 * @param bool $nullable = false
	 *
	 * @return bool
	 */
	public static function Int ($key, $type = false, $nullable = false) {
		if ($nullable && $key == null) return true;

		return preg_match('#^([0-9]+)$#', $key) && ($type ? is_int($key) : true);
	}

	/**
	 * @param $key
	 * @param bool $type = false
	 * @param bool $nullable = false
	 *
	 * @return bool
	 */
	public static function Float ($key, $type = false, $nullable = false) {
		if ($nullable && $key == null) return true;

		return preg_match('#^([0-9]+\.[0-9]+)$#', $key) && ($type ? is_float($key) : true);
	}

	/**
	 * @param $key
	 * @param $values
	 * @param bool $nullable = false
	 * 
	 * @return bool
	 */
	public static function In ($key, $values, $nullable = false) {
		if ($nullable && $key == null) return true;

		return in_array($key, $values, true);
	}

	/**
	 * @param IQuarkModel $model
	 * @param $field
	 * @param string[] $op = [QuarkModel::OPERATION_CREATE]
	 *
	 * @return bool
	 */
	public static function Unique (IQuarkModel $model, $field, $op = [QuarkModel::OPERATION_CREATE]) {
		/**
		 * @var QuarkModel $container
		 */
		$container = Quark::ContainerOfInstance($model);

		if ($container == null) {
			Quark::Log('[QuarkField::Unique] Cannot get container of given model instance of ' . get_class($model), Quark::LOG_WARN);
			return false;
		}
		
		return in_array($container->Operation(), $op)
			? QuarkModel::Count($model, array(
				$field => ($model->$field instanceof QuarkModel && $model->$field->Model() instanceof IQuarkLinkedModel
					? $model->$field->Unlink()
					: $model->$field
				)
			)) == 0
			: true;
	}

	/**
	 * @param $key
	 * @param $model
	 * @param bool $nullable = false
	 *
	 * @return bool
	 */
	public static function CollectionOf ($key, $model, $nullable = false) {
		if ($nullable && $key == null) return true;

		if (!is_array($key)) return false;

		foreach ($key as $item)
			if (!($item instanceof $model)) return false;

		return true;
	}

	/**
	 * @param $rules
	 * 
	 * @return bool
	 */
	public static function Rules ($rules) {
		if (!is_array($rules))
			return $rules == null ? true : (bool)$rules;

		$ok = true;

		foreach ($rules as $rule)
			$ok = $ok && $rule;

		return $ok;
	}

	/**
	 * @param bool $rule
	 * @param QuarkLocalizedString $message = null
	 * @param string $field = ''
	 *
	 * @return bool
	 */
	public static function Assert ($rule, QuarkLocalizedString $message = null, $field = '') {
		if (!$rule && $message != null)
			self::$_errors[] = new QuarkKeyValuePair($field, $message);

		return $rule;
	}
	
	/**
	 * @param bool $rule
	 * @param string|array $message = ''
	 * @param string $field = ''
	 *
	 * @return bool
	 */
	public static function LocalizedAssert ($rule, $message = '', $field = '') {
		return self::Assert(
			$rule,
			is_array($message)
				? QuarkLocalizedString::Dictionary($message)
				: QuarkLocalizedString::DictionaryFromKey($message),
			$field
		);
	}

	/**
	 * @param string $language = QuarkLanguage::ANY
	 *
	 * @return string[]
	 */
	public static function ValidationErrors ($language = QuarkLanguage::ANY) {
		$out = array();

		foreach (self:: $_errors as $error)
			$out[] = $error->Value()->Of($language);

		return $out;
	}

	/**
	 * @return QuarkKeyValuePair[]
	 */
	public static function FlushValidationErrors () {
		$errors = self::$_errors;
		self::$_errors = array();

		return $errors;
	}
}

/**
 * Class QuarkLocalizedString
 *
 * @package Quark
 */
class QuarkLocalizedString implements IQuarkModel, IQuarkLinkedModel, IQuarkModelWithBeforeExtract {
	const EXTRACT_CURRENT = 'localized.extract.current';
	const EXTRACT_ANY = 'localized.extract.any';
	const EXTRACT_VALUES = 'localized.extract.values';
	const EXTRACT_FULL = 'localized.extract.full';

	/**
	 * @var object $values = null
	 */
	public $values = null;

	/**
	 * @var string $default = QuarkLanguage::ANY
	 */
	public $default = QuarkLanguage::ANY;

	/**
	 * @param string $value
	 * @param string $language = QuarkLanguage::ANY
	 * @param string $default = QuarkLanguage::ANY
	 */
	public function __construct ($value = '', $language = QuarkLanguage::ANY, $default = QuarkLanguage::ANY) {
		$this->values = new \stdClass();
		$this->default = $default;

		if (func_num_args() != 0 && is_scalar($value))
			$this->values->$language = $value;
	}

	/**
	 * @return string
	 */
	public function __toString () {
		return $this->Of($this->default);
	}

	/**
	 * @param string $language
	 * @param string $value
	 *
	 * @return string
	 */
	public function Of ($language, $value = '') {
		if (func_num_args() == 2 && is_scalar($value))
			$this->values->$language = (string)$value;

		$default = $this->default;

		return isset($this->values->$language)
			? (string)$this->values->$language
			: (isset($this->values->$default) ? $this->values->$default : '');
	}

	/**
	 * @param string $value = ''
	 *
	 * @return string
	 */
	public function Current ($value = '') {
		return $this->Of(Quark::CurrentLanguage(), func_num_args() != 0 && is_scalar($value) ? $value : null);
	}

	/**
	 * @param string $value = ''
	 *
	 * @return string
	 */
	public function Any ($value = '') {
		return $this->Of(QuarkLanguage::ANY, func_num_args() != 0 && is_scalar($value) ? $value : null);
	}

	/**
	 * @return string
	 */
	public function ControlValue () {
		return base64_encode(json_encode($this->values));
	}

	/**
	 * @param callable $assert = null
	 * @param callable $onEmpty = null
	 *
	 * @return bool
	 */
	public function Assert (callable $assert = null, callable $onEmpty = null) {
		if ($assert == null) return true;

		$out = true;
		$empty = true;
		$_empty = null;

		foreach ($this->values as $language => $value) {
			$ok = $assert($value, $language);
			$out &= $ok === null ? true : $ok;
			$empty = false;
		}

		if ($empty && $onEmpty != null) {
			$_empty = $onEmpty();
			$_empty = $_empty === null ? true : $_empty;
		}

		return $empty && $onEmpty != null ? $_empty : $out;
	}

	/**
	 * @param array|object $dictionary = []
	 * @param string $default = QuarkLanguage::ANY
	 *
	 * @return QuarkLocalizedString
	 */
	public static function Dictionary ($dictionary = [], $default = QuarkLanguage::ANY) {
		if (!is_array($dictionary) && !is_object($dictionary)) return null;

		$str = new self('', QuarkLanguage::ANY, $default);
		$str->values = (object)$dictionary;

		return $str;
	}
	
	/**
	 * @param string $key = ''
	 *
	 * @return QuarkLocalizedString
	 */
	public static function DictionaryFromKey ($key = '') {
		$locale = Quark::Config()->LocalizationDictionaryOf($key);
		
		if ($locale == null) return null;
		
		$str = new self('', QuarkLanguage::ANY, QuarkLanguage::ANY);
		$str->values = $locale;

		return $str;
	}

	/**
	 * @return mixed
	 */
	public function Fields () {
		return array(
			'values' => new \stdClass(),
			'default' => QuarkLanguage::ANY
		);
	}

	/**
	 * @return void
	 */
	public function Rules () { }

	/**
	 * @param $raw
	 *
	 * @return mixed
	 */
	public function Link ($raw) {
		if ($raw instanceof QuarkLocalizedString)
			return new QuarkModel($this, $raw);
		
		if (!is_scalar($raw)) return null;
		
		$values = json_decode(strlen($raw) != 0 && $raw[0] == '{' ? $raw : base64_decode($raw));
		
		return new QuarkModel($this, array(
			'values' => json_last_error() == 0
				? $values
				: (Quark::Config()->LocalizationParseFailedToAny()
					? (object)array(QuarkLanguage::ANY => $raw)
					: null
				),
			'default' => $this->default
		));
	}

	/**
	 * @return mixed
	 */
	public function Unlink () {
		return json_encode($this->values);
	}

	/**
	 * @param array $fields
	 * @param bool $weak
	 *
	 * @return mixed
	 */
	public function BeforeExtract ($fields, $weak) {
		$extract = $this->_extract($fields);
		if ($extract !== null) return $extract;

		$extract = $this->_extract(Quark::Config()->LocalizationExtract());
		if ($extract !== null) return $extract;

		return $this->Of($this->default);
	}

	/**
	 * @param $criteria
	 *
	 * @return string|object|null
	 */
	private function _extract ($criteria) {
		switch ($criteria) {
			case self::EXTRACT_CURRENT: return $this->Current(); break;
			case self::EXTRACT_ANY: return $this->Of(QuarkLanguage::ANY); break;
			case self::EXTRACT_VALUES: return $this->values; break;
			case self::EXTRACT_FULL: return $this; break;
			default: break;
		}
		
		return null;
	}
}

/**
 * Class QuarkSecuredString
 *
 * @package Quark
 */
class QuarkSecuredString implements IQuarkModel, IQuarkLinkedModel, IQuarkPolymorphicModel {
	/**
	 * @var string $_val = ''
	 */
	private $_val = '';

	/**
	 * @var string $_key = ''
	 */
	private $_key = '';

	/**
	 * @var array $_rules = []
	 */
	private $_rules = array();

	/**
	 * @var QuarkCipher $_cipher = null
	 */
	private $_cipher = null;

	/**
	 * @var string $_extract = ''
	 */
	private $_extract = '';

	/**
	 * @var bool $_ciphered = false
	 */
	private $_ciphered = false;

	/**
	 * @param string $key = ''
	 * @param array $rules = []
	 * @param IQuarkEncryptionProtocol $cipher = null
	 */
	public function __construct ($key = '', $rules = [], IQuarkEncryptionProtocol $cipher = null) {
		$this->_key = $key;
		$this->_rules = $rules;
		$this->_cipher = new QuarkCipher($cipher ? $cipher : new QuarkOpenSSLCipher());
	}

	/**
	 * @return string
	 */
	public function __toString () {
		return (string)$this->_val;
	}
	
	/**
	 * @return void
	 */
	public function Fields () { }

	/**
	 * @return mixed
	 */
	public function Rules () {
		return $this->_rules;
	}

	/**
	 * @param $raw
	 *
	 * @return mixed
	 */
	public function Link ($raw) {
		$string = new self($this->_key);
		$string->_val = $raw;
		$string->_ciphered = true;
		$string->_extract = $this->_extract;

		return new QuarkModel($string);
	}

	/**
	 * @return mixed
	 */
	public function Unlink () {
		if ($this->_ciphered)
			$this->Decipher();

		return $this->_cipher->Encrypt($this->_key, $this->_val);
	}

	/**
	 * @return mixed
	 */
	public function PolymorphicExtract () {
		if ($this->_ciphered)
			$this->Decipher();
		
		return (string)($this->_extract
			? $this->_cipher->Encrypt($this->_extract, $this->_val)
			: $this->_val);
	}

	/**
	 * @return string
	 */
	public function Decipher () {
		$out = $this->_cipher->Decrypt($this->_key, $this->_val);
		$this->_ciphered = false;
		
		return $this->_val = $out === false ? $this->_val : $out;
	}

	/**
	 * @param string $keyStore = ''
	 * @param string $keyExtract = ''
	 * @param array $rules = []
	 * @param IQuarkEncryptionProtocol $cipher = null
	 *
	 * @return QuarkSecuredString
	 */
	public static function WithEncryptedExtract ($keyStore = '', $keyExtract = '', $rules = [], IQuarkEncryptionProtocol $cipher = null) {
		$string = new self($keyStore, $rules, $cipher);
		$string->_extract = $keyExtract;

		return $string;
	}
}

/**
 * Class QuarkDate
 *
 * @package Quark
 */
class QuarkDate implements IQuarkModel, IQuarkLinkedModel, IQuarkModelWithAfterPopulate, IQuarkModelWithBeforeExtract {
	const NOW = 'now';
	const NOW_FULL = 'Y-m-d H:i:s.u';
	const GMT = 'UTC';
	const CURRENT = '';
	const UNKNOWN_YEAR = '0000';

	/**
	 * @var IQuarkCulture|QuarkCultureISO $_culture
	 */
	private $_culture;

	/**
	 * @var \DateTime $_date
	 */
	private $_date;

	/**
	 * @var string $_timezone = self::CURRENT
	 */
	private $_timezone = self::CURRENT;
	
	/**
	 * @var bool $_fromTimestamp = false
	 */
	private $_fromTimestamp = false;

	/**
	 * @var bool $_isNull = false
	 */
	private $_isNull = false;

	/**
	 * @var bool $_nullable = false
	 */
	private $_nullable = false;

	/**
	 * @var array $_components
	 */
	private static $_components = array(
		'Y' => '([\d]{4})',
		'm' => '([\d]{2})',
		'd' => '([\d]{2})',
		'H' => '([\d]{2})',
		'i' => '([\d]{2})',
		's' => '([\d]{2})',
		'u' => '([\d]{6})'
	);
	
	/**
	 * @param IQuarkCulture $culture
	 * @param string $value = self::NOW
	 */
	public function __construct (IQuarkCulture $culture = null, $value = self::NOW) {
		$this->_culture = $culture ? $culture : Quark::Config()->Culture();
		$this->Value($value);
	}

	/**
	 * @return string
	 */
	public function __toString () {
		return $this->DateTime();
	}

	/**
	 * cloning behavior
	 */
	public function __clone () {
		$this->_date = clone $this->_date;
	}

	/**
	 * @param IQuarkCulture $culture
	 *
	 * @return IQuarkCulture|QuarkCultureISO
	 */
	public function Culture (IQuarkCulture $culture = null) {
		if (func_num_args() != 0)
			$this->_culture = $culture;

		return $this->_culture;
	}

	/**
	 * @param string $value
	 *
	 * @return \DateTime
	 */
	public function Value ($value = '') {
		if (func_num_args() != 0) {
			if (is_numeric($value)) {
				$this->_date = new \DateTime();
				$this->_date->setTimestamp((int)$value);
				$this->_fromTimestamp = true;
			}
			elseif (is_string($value)) $this->_date = new \DateTime($value);
			else $this->_isNull = true;
		}

		return $this->_date;
	}

	/**
	 * @return string
	 */
	public function Timezone () {
		return $this->_timezone;
	}

	/**
	 * @return string
	 */
	public function DateTime () {
		return $this->_date->format($this->_culture->DateTimeFormat());
	}

	/**
	 * @return string
	 */
	public function Date () {
		return $this->_date->format($this->_culture->DateFormat());
	}

	/**
	 * @return string
	 */
	public function Time () {
		return $this->_date->format($this->_culture->TimeFormat());
	}

	/**
	 * @return int
	 */
	public function Timestamp () {
		return $this->_date->getTimestamp();
	}

	/**
	 * @param QuarkDate $with = null
	 * @param bool $interval = false
	 *
	 * @return int|QuarkDateInterval
	 */
	public function Interval (QuarkDate $with = null, $interval = false) {
		if ($with == null) return 0;

		$start = $this->_date->getTimestamp();
		$end = $with->Value()->getTimestamp();
		
		$out = $end - $start;
		
		return $interval ? QuarkDateInterval::FromSeconds($out) : $out;
	}

	/**
	 * @param string|QuarkDateInterval $offset
	 * @param bool $copy = false
	 *
	 * @return QuarkDate
	 */
	public function Offset ($offset, $copy = false) {
		if ($this->_date == null) return null;

		$out = $copy ? clone $this : $this;

		if (!@$out->_date->modify($offset instanceof QuarkDateInterval ? $offset->Modifier() : $offset))
			Quark::Log('[QuarkDate] Invalid value for $offset argument. Error: ' . QuarkException::LastError(), Quark::LOG_WARN);

		return $out;
	}

	/**
	 * @param QuarkDate|null $then
	 * @param int $offset
	 *
	 * @return bool
	 */
	public function Earlier (QuarkDate $then = null, $offset = 0) {
		if ($then == null)
			$then = self::Now();

		return $this->Interval($then) > $offset;
	}

	/**
	 * @param QuarkDate|null $then
	 * @param int $offset
	 *
	 * @return bool
	 */
	public function Later (QuarkDate $then = null, $offset = 0) {
		if ($then == null)
			$then = self::Now();

		return $this->Interval($then) < $offset;
	}

	/**
	 * @param string $format
	 *
	 * @return string
	 */
	public function Format ($format = '') {
		return $this->_date->format($format);
	}

	/**
	 * @param string $timezone = self::CURRENT
	 * @param bool $copy = false
	 *
	 * @return QuarkDate
	 */
	public function InTimezone ($timezone = self::CURRENT, $copy = false) {
		$this->_timezone = $timezone;
		return $this->Offset('+' . self::TimezoneOffset($timezone) . ' seconds', $copy);
	}

	/**
	 * @param bool $store = false
	 *
	 * @return QuarkDate
	 */
	public function AsTimestamp ($store = true) {
		$this->_fromTimestamp = $store;
		
		return $this;
	}

	/**
	 * @param bool $is = false
	 *
	 * @return bool
	 */
	public function IsNull ($is = false) {
		if (func_num_args() != 0)
			$this->_isNull = $is;

		return $this->_isNull;
	}

	/**
	 * @param bool $is = false
	 *
	 * @return $this
	 */
	public function Nullable ($is = false) {
		if (func_num_args() != 0)
			$this->_nullable = $is;

		return $this;
	}

	/**
	 * @return string
	 */
	public static function Microtime () {
		return str_pad(explode(' ', microtime())[0] * 1000000, 6, '0');
	}

	/**
	 * @return string
	 */
	public static function NowUSec () {
		return date('Y-m-d H:i:s') . '.' . self::Microtime();
	}

	/**
	 * @return string
	 */
	public static function NowUSecGMT () {
		return gmdate('Y-m-d H:i:s') . '.' . self::Microtime();
	}

	/**
	 * @param string $format
	 *
	 * @return QuarkDate
	 */
	public static function Now ($format = '') {
		$date = self::FromFormat($format, self::NowUSec());
		$date->_timezone = self::CURRENT;

		return $date;
	}

	/**
	 * @param string $format
	 *
	 * @return QuarkDate
	 */
	public static function GMTNow ($format = '') {
		$date = self::FromFormat($format, self::NowUSecGMT());
		$date->_timezone = self::GMT;

		return $date;
	}

	/**
	 * @param string $date
	 * @param string $timezone = self::CURRENT
	 *
	 * @return QuarkDate
	 */
	public static function Of ($date, $timezone = self::CURRENT) {
		return (new self(null, $date, $timezone))->InTimezone($timezone);
	}

	/**
	 * @param string $date
	 *
	 * @return QuarkDate
	 */
	public static function GMTOf ($date) {
		return self::Of($date, self::GMT);
	}

	/**
	 * @param QuarkDate|string $date
	 * @param string $timezone = self::GMT
	 *
	 * @return QuarkDate
	 */
	public static function From ($date, $timezone = self::GMT) {
		return $date instanceof QuarkDate ? $date : self::Of($date, $timezone);
	}

	/**
	 * @param string $format
	 * @param string $value = self::NOW
	 *
	 * @return QuarkDate
	 */
	public static function FromFormat ($format, $value = self::NOW) {
		return new self(QuarkCultureCustom::Format($format), $value);
	}

	/**
	 * @param int $time = 0
	 *
	 * @return QuarkDate
	 */
	public static function FromTimestamp ($time = 0) {
		$date = new self();
		$date->_date->setTimestamp($time);
		$date->_fromTimestamp = true;

		return $date;
	}

	/**
	 * @param string $timezone
	 *
	 * @return int
	 */
	public static function TimezoneOffset ($timezone = self::CURRENT) {
		if ($timezone == self::CURRENT) {
			$timezone = date_default_timezone_get();

			if (!$timezone) {
				date_default_timezone_set(self::GMT);
				$timezone = self::GMT;
			}
		}

		return (new \DateTimeZone($timezone))->getOffset(self::GMTNow()->Value());
	}

	/**
	 * @param string $date = ''
	 * @param string $in = ''
	 * @param string $out = ''
	 *
	 * @return mixed
	 */
	public static function Convert ($date = '', $in = '', $out = '') {
		$replace = array();
		
		$in = preg_replace_callback('#[a-zA-Z]#Uis', function ($item) use(&$replace) {
			if (!isset(self::$_components[$item[0]])) return $item[0];
			
			$replace[$item[0]] = '$' . (sizeof($replace) + 1);
			
			return self::$_components[$item[0]];
		}, $in);
		
		$out = preg_replace_callback('#[a-zA-Z]#Uis', function ($item) use($replace) {
			return isset($replace[$item[0]]) ? $replace[$item[0]] : $item[0];
		}, $out);
		
		return preg_replace('#' . $in . '#Uis', $out, $date);
	}

	/**
	 * @return void
	 */
	public function Fields () { }

	/**
	 * @return void
	 */
	public function Rules () { }

	/**
	 * @param $raw
	 *
	 * @return mixed
	 */
	public function Link ($raw) {
		return new QuarkModel($this, $raw);
	}

	/**
	 * @return mixed
	 */
	public function Unlink () {
		return $this->_nullable && $this->_isNull
			? null
			: ($this->_fromTimestamp ? $this->Timestamp() : $this->DateTime());
	}

	/**
	 * @param $raw
	 *
	 * @return void
	 */
	public function AfterPopulate ($raw) {
		$this->Value($raw);
	}

	/**
	 * @param array $fields
	 * @param bool $weak
	 *
	 * @return mixed
	 */
	public function BeforeExtract ($fields, $weak) {
		return $this->DateTime();
	}
}

/**
 * Class QuarkDateInterval
 *
 * @package Quark
 */
class QuarkDateInterval {
	const ROUND_CEIL = 'ceil';
	const ROUND_FLOOR = 'floor';
	
	const UNIT_YEAR = 'years';
	const UNIT_MONTH = 'months';
	const UNIT_DAY = 'days';
	const UNIT_HOUR = 'hours';
	const UNIT_MINUTE = 'minutes';
	const UNIT_SECOND = 'seconds';
	
	const SECONDS_IN_YEAR = 31536000;
	const SECONDS_IN_MONTH = 2678400;
	const SECONDS_IN_DAY = 86400;
	const SECONDS_IN_HOUR = 3600;
	const SECONDS_IN_MINUTE = 60;
	const SECONDS_IN_SECOND = 1;
	
	const MINUTES_IN_YEAR = 525600;
	const MINUTES_IN_MONTH = 44640;
	const MINUTES_IN_DAY = 1440;
	const MINUTES_IN_HOUR = 60;
	const MINUTES_IN_MINUTE = 1;
	
	const HOURS_IN_YEAR = 8760;
	const HOURS_IN_MONTH = 744;
	const HOURS_IN_DAY = 24;
	const HOURS_IN_HOUR = 1;
	
	const DAYS_IN_YEAR = 365;
	const DAYS_IN_MONTH = 31;
	const DAYS_IN_DAY = 1;
	
	const MONTHS_IN_YEAR = 12;
	const MONTHS_IN_MONTH = 1;
	
	const YEARS_IN_YEAR = 1;
	
	/**
	 * @var array $_dividers
	 */
	private static $_dividers = array(
		self::UNIT_SECOND => array(
			self::UNIT_YEAR => self::SECONDS_IN_YEAR,
			self::UNIT_MONTH => self::SECONDS_IN_MONTH,
			self::UNIT_DAY => self::SECONDS_IN_DAY,
			self::UNIT_HOUR => self::SECONDS_IN_HOUR,
			self::UNIT_MINUTE => self::SECONDS_IN_MINUTE,
			self::UNIT_SECOND => self::SECONDS_IN_SECOND
		),
		self::UNIT_MINUTE => array(
			self::UNIT_YEAR => self::MINUTES_IN_YEAR,
			self::UNIT_MONTH => self::MINUTES_IN_MONTH,
			self::UNIT_DAY => self::MINUTES_IN_DAY,
			self::UNIT_HOUR => self::MINUTES_IN_HOUR,
			self::UNIT_MINUTE => self::MINUTES_IN_MINUTE
		),
		self::UNIT_HOUR => array(
			self::UNIT_YEAR => self::HOURS_IN_YEAR,
			self::UNIT_MONTH => self::HOURS_IN_MONTH,
			self::UNIT_DAY => self::HOURS_IN_DAY,
			self::UNIT_HOUR => self::HOURS_IN_HOUR
		),
		self::UNIT_DAY => array(
			self::UNIT_YEAR => self::DAYS_IN_YEAR,
			self::UNIT_MONTH => self::DAYS_IN_MONTH,
			self::UNIT_DAY => self::DAYS_IN_DAY
		),
		self::UNIT_MONTH => array(
			self::UNIT_YEAR => self::MONTHS_IN_YEAR,
			self::UNIT_MONTH => self::MONTHS_IN_MONTH
		),
		self::UNIT_YEAR => array(
			self::UNIT_YEAR => self::YEARS_IN_YEAR
		)
	);
	
	/**
	 * @var array $_order
	 */
	private static $_order = array(
		self::UNIT_YEAR => 0,
		self::UNIT_MONTH => 1,
		self::UNIT_DAY => 2,
		self::UNIT_HOUR => 3,
		self::UNIT_MINUTE => 4,
		self::UNIT_SECOND => 5,
	);
	
	/**
	 * @var int $years = 0
	 */
	public $years = 0;
	
	/**
	 * @var int $months = 0
	 */
	public $months = 0;
	
	/**
	 * @var int $days = 0
	 */
	public $days = 0;
	
	/**
	 * @var int $hours = 0
	 */
	public $hours = 0;
	
	/**
	 * @var int $minutes = 0
	 */
	public $minutes = 0;
	
	/**
	 * @var int $seconds = 0
	 */
	public $seconds = 0;

	/**
	 * @var bool $_positive = true
	 */
	private $_positive = true;
	
	/**
	 * @param int $years = 0
	 * @param int $months = 0
	 * @param int $days = 0
	 * @param int $hours = 0
	 * @param int $minutes = 0
	 * @param int $seconds = 0
	 * @param bool $positive = true
	 */
	public function __construct ($years = 0, $months = 0, $days = 0, $hours = 0, $minutes = 0, $seconds = 0, $positive = true) {
		$this->years = $years;
		$this->months = $months;
		$this->days = $days;
		
		$this->hours = $hours;
		$this->minutes = $minutes;
		$this->seconds = $seconds;
		
		$this->_positive = $positive;
	}
	
	/**
	 * @param bool $ceil = true
	 * 
	 * @return int
	 */
	public function Years ($ceil = true) {
		$full = $this->months + $this->days + $this->hours + $this->minutes + $this->seconds;
		
		return $this->years + (int)($ceil && $full != 0);
	}
	
	/**
	 * @param bool $ceil = true
	 * 
	 * @return int
	 */
	public function Months ($ceil = true) {
		$full = $this->days + $this->hours + $this->minutes + $this->seconds;
		$months = $this->years * self::MONTHS_IN_YEAR;
		
		return $months + $this->months + (int)($ceil && $full != 0);
	}
	
	/**
	 * @param bool $ceil = true
	 * 
	 * @return int
	 */
	public function Days ($ceil = true) {
		$full = $this->hours + $this->minutes + $this->seconds;
		$days = $this->years * self::DAYS_IN_YEAR
			  + $this->months * self::DAYS_IN_MONTH;
		
		return $days + $this->days + (int)($ceil && $full != 0);
	}
	
	/**
	 * @param bool $ceil = true
	 * 
	 * @return int
	 */
	public function Hours ($ceil = true) {
		$full = $this->minutes + $this->seconds;
		$hours = $this->years * self::HOURS_IN_YEAR
			   + $this->months * self::HOURS_IN_MONTH
			   + $this->days * self::HOURS_IN_DAY;
		
		return $hours + $this->hours + (int)($ceil && $full != 0);
	}
	
	/**
	 * @param bool $ceil = true
	 * 
	 * @return int
	 */
	public function Minutes ($ceil = true) {
		$full = $this->seconds;
		$minutes = $this->years * self::MINUTES_IN_YEAR
				 + $this->months * self::MINUTES_IN_MONTH
				 + $this->days * self::MINUTES_IN_DAY
				 + $this->hours * self::MINUTES_IN_HOUR;
		
		return $minutes + $this->minutes + (int)($ceil && $full != 0);
	}
	
	/**
	 * @return int
	 */
	public function Seconds () {
		$seconds = $this->years * self::SECONDS_IN_YEAR
				 + $this->months * self::SECONDS_IN_MONTH
				 + $this->days * self::SECONDS_IN_DAY
				 + $this->hours * self::SECONDS_IN_HOUR
				 + $this->minutes * self::SECONDS_IN_MINUTE;
		
		return $seconds + $this->seconds;
	}

	/**
	 * @return bool
	 */
	public function Positive () {
		return $this->_positive;
	}
	
	/**
	 * @param string $format = ''
	 * @param bool $sign = false
	 *
	 * @return string|null
	 */
	public function Format ($format = '', $sign = false) {
		return
			($sign ? ($this->_positive ? '+' : '-') : '') .
			QuarkDate::Convert(
				$f = str_pad(abs($this->years), 4, '0', STR_PAD_LEFT) . '-' .
				str_pad(abs($this->months), 2, '0', STR_PAD_LEFT) . '-' .
				str_pad(abs($this->days), 2, '0', STR_PAD_LEFT) . ' ' .
				str_pad(abs($this->hours), 2, '0', STR_PAD_LEFT) . ':' .
				str_pad(abs($this->minutes), 2, '0', STR_PAD_LEFT) . ':' .
				str_pad(abs($this->seconds), 2, '0', STR_PAD_LEFT),
				'Y-m-d H:i:s',
				$format
			);
	}
	
	/**
	 * @return string
	 */
	public function Modifier () {
		return ''
			. $this->years . ' years '
			. $this->months . ' months '
			. $this->days . ' days '
			. $this->hours . ' hours '
			. $this->minutes . ' minutes '
			. $this->seconds . ' seconds ';
	}
	
	/**
	 * @param string $interval = ''
	 *
	 * @return QuarkDateInterval
	 */
	public static function FromDate ($interval = '') {
		$date = new \DateTime($interval);
		
		return new self(
			(int)$date->format('Y'),
			(int)$date->format('m'),
			(int)$date->format('d'),
			(int)$date->format('H'),
			(int)$date->format('i'),
			(int)$date->format('s')
		);
	}
	
	/**
	 * @param int $interval = 0
	 *
	 * @return QuarkDateInterval
	 */
	public static function FromYears ($interval = 0) {
		return self::FromUnit(self::UNIT_YEAR, $interval);
	}
	
	/**
	 * @param int $interval = 0
	 *
	 * @return QuarkDateInterval
	 */
	public static function FromMonths ($interval = 0) {
		return self::FromUnit(self::UNIT_MONTH, $interval);
	}
	
	/**
	 * @param int $interval = 0
	 *
	 * @return QuarkDateInterval
	 */
	public static function FromDays ($interval = 0) {
		return self::FromUnit(self::UNIT_DAY, $interval);
	}
	
	/**
	 * @param int $interval = 0
	 *
	 * @return QuarkDateInterval
	 */
	public static function FromHours ($interval = 0) {
		return self::FromUnit(self::UNIT_HOUR, $interval);
	}
	
	/**
	 * @param int $interval = 0
	 *
	 * @return QuarkDateInterval
	 */
	public static function FromMinutes ($interval = 0) {
		return self::FromUnit(self::UNIT_MINUTE, $interval);
	}
	
	/**
	 * @param int $interval = 0
	 *
	 * @return QuarkDateInterval
	 */
	public static function FromSeconds ($interval = 0) {
		return self::FromUnit(self::UNIT_SECOND, $interval);
	}
	
	/**
	 * @param string $unit = self::UNIT_SECOND
	 * @param int $interval = 0
	 *
	 * @return QuarkDateInterval
	 */
	public static function FromUnit ($unit = self::UNIT_SECOND, $interval = 0) {
		$round = $interval < 0 ? self::ROUND_CEIL : self::ROUND_FLOOR;
		$order = isset(self::$_order[$unit]) ? self::$_order[$unit] : -1;
		
		$years   = $order >= 0 ? self::Calculate(self::UNIT_YEAR,   $unit, $round, $interval) : 0;
		$months  = $order >= 1 ? self::Calculate(self::UNIT_MONTH,  $unit, $round, $interval, $years) : 0;
		$days    = $order >= 2 ? self::Calculate(self::UNIT_DAY,    $unit, $round, $interval, $years, $months): 0;
		$hours   = $order >= 3 ? self::Calculate(self::UNIT_HOUR,   $unit, $round, $interval, $years, $months, $days): 0;
		$minutes = $order >= 4 ? self::Calculate(self::UNIT_MINUTE, $unit, $round, $interval, $years, $months, $days, $hours): 0;
		$seconds = $order >= 5 ? self::Calculate(self::UNIT_SECOND, $unit, $round, $interval, $years, $months, $days, $hours, $minutes) : 0;
		
		return new self($years, $months, $days, $hours, $minutes, $seconds, $interval >= 0);
	}
	
	/**
	 * @param string $target = self::UNIT_SECOND
	 * @param string $unit = self::UNIT_SECOND
	 * @param string $round = self::ROUND_CEIL
	 * @param int $interval = 0
	 * @param int $years = 0
	 * @param int $months = 0
	 * @param int $days = 0
	 * @param int $hours = 0
	 * @param int $minutes = 0
	 *
	 * @return int
	 */
	public static function Calculate ($target = self::UNIT_SECOND, $unit = self::UNIT_SECOND, $round = self::ROUND_CEIL, $interval = 0, $years = 0, $months = 0, $days = 0, $hours = 0, $minutes = 0) {
		$args = func_num_args();
		
		return (isset(self::$_dividers[$unit][$target]) ? $round($interval / self::$_dividers[$unit][$target]) : $interval)
			- (
				($args > 4 && isset(self::$_dividers[$target][self::UNIT_YEAR]) ? $years * self::$_dividers[$target][self::UNIT_YEAR] : 0) +
				($args > 5 && isset(self::$_dividers[$target][self::UNIT_MONTH]) ? $months * self::$_dividers[$target][self::UNIT_MONTH] : 0) +
				($args > 6 && isset(self::$_dividers[$target][self::UNIT_DAY]) ? $days * self::$_dividers[$target][self::UNIT_DAY] : 0) +
				($args > 7 && isset(self::$_dividers[$target][self::UNIT_HOUR]) ? $hours * self::$_dividers[$target][self::UNIT_HOUR] : 0) +
				($args > 8 && isset(self::$_dividers[$target][self::UNIT_MINUTE]) ? $minutes * self::$_dividers[$target][self::UNIT_MINUTE] : 0) +
			0);
	}
}

/**
 * Class QuarkGenericModel
 *
 * @package Quark
 */
class QuarkGenericModel implements IQuarkModel, IQuarkModelWithManageableDataProvider, IQuarkModelWithCustomCollectionName, IQuarkPolymorphicModel {
	use QuarkModelBehavior;

	/**
	 * @var array $_fields = []
	 */
	private $_fields = array();

	/**
	 * @var array $_rules = []
	 */
	private $_rules = array();

	/**
	 * @var callable $_polyMorph = null
	 */
	private $_polyMorph = null;

	/**
	 * @var string $_provider = null
	 */
	private $_provider = null;

	/**
	 * @var string $_collection = ''
	 */
	private $_collection = '';

	/**
	 * @param array $fields = []
	 * @param array $rules = []
	 * @param callable $polyMorph = null
	 */
	public function __construct ($fields = [], $rules = [], callable $polyMorph = null) {
		$this->_fields = $fields;
		$this->_rules = $rules;
		$this->_polyMorph = $polyMorph;
	}
	
	/**
	 * @return mixed
	 */
	public function Fields () {
		return $this->_fields;
	}

	/**
	 * @return mixed
	 */
	public function Rules () {
		return $this->_rules;
	}

	/**
	 * @return string
	 */
	public function DataProvider () {
		return $this->_provider;
	}

	/**
	 * @param $source
	 *
	 * @return bool
	 */
	public function DataProviderForSubModel ($source) {
		return $this->_provider !== null;
	}

	/**
	 * @return mixed
	 */
	public function PolymorphicExtract () {
		$morph = $this->_polyMorph;
		
		return $morph ? $morph($this) : null;
	}

	/**
	 * @return string
	 */
	public function CollectionName () {
		return $this->_collection;
	}

	/**
	 * @param IQuarkModel $model
	 *
	 * @return QuarkModel
	 */
	public function To (IQuarkModel $model) {
		return new QuarkModel($model, $this);
	}

	/**
	 * @param IQuarkModel $model
	 *
	 * @return bool|IQuarkModel|QuarkModelBehavior
	 */
	public function ExportGeneric (IQuarkModel $model) {
		return $this->PopulateWith((new QuarkModel($model))->Export(true))->Export();
	}

	/**
	 * @param IQuarkModelWithDataProvider $model = null
	 * @param array $fields = []
	 * @param array $rules = []
	 * @param callable $polyMorph = null
	 *
	 * @return QuarkGenericModel
	 */
	public static function WithDataProvider (IQuarkModelWithDataProvider $model = null, $fields = [], $rules = [], callable $polyMorph = null) {
		if ($model == null) return null;
		
		$out = new self($fields, $rules, $polyMorph);
		$out->_provider = $model->DataProvider();
		$out->_collection = $model instanceof IQuarkModelWithCustomCollectionName
			? $model->CollectionName()
			: QuarkObject::ClassOf($model);
		
		return $out;
	}
}

/**
 * Class QuarkLazyLink
 *
 * @package Quark
 */
class QuarkLazyLink implements IQuarkModel, IQuarkLinkedModel, IQuarkModelWithBeforeExtract {
	/**
	 * @var IQuarkLinkedModel $_model
	 */
	private $_model;

	/**
	 * @var $value
	 */
	public $value;

	/**
	 * @var bool $_linked = false
	 */
	private $_linked = false;

	/**
	 * @param IQuarkLinkedModel $model = null
	 * @param $value = null
	 * @param bool $linked = false
	 */
	public function __construct (IQuarkLinkedModel $model, $value = null, $linked = false) {
		$this->_model = $model;
		$this->_linked = $linked;
		
		$this->value = func_num_args() > 1 ? $value : '';
	}

	/**
	 * @param IQuarkLinkedModel $model = null
	 *
	 * @return IQuarkLinkedModel
	 */
	public function Model (IQuarkLinkedModel $model = null) {
		if ($model != null)
			$this->_model = $model;
		
		return $this->_model;
	}

	/**
	 * @return bool
	 */
	public function Linked () {
		return $this->_linked;
	}

	/**
	 * @return QuarkModel|IQuarkLinkedModel
	 */
	public function Retrieve () {
		$this->_linked = true;

		return $this->_model->Link($this->value);
	}
	
	/**
	 * @return void
	 */
	public function Fields () { }

	/**
	 * @return void
	 */
	public function Rules () { }

	/**
	 * @param $raw
	 *
	 * @return mixed
	 */
	public function Link ($raw) {
		$this->value = $raw;

		return new QuarkModel($this);
	}

	/**
	 * @return mixed
	 */
	public function Unlink () {
		if ($this->_linked)
			$this->value = $this->_model->Unlink();

		return $this->value;
	}

	/**
	 * @param array $fields
	 * @param bool $weak
	 *
	 * @return mixed
	 */
	public function BeforeExtract ($fields, $weak) {
		return $this->value;
	}
}

/**
 * Class QuarkNullable
 *
 * @property $value = ''
 * @property $default = ''
 *
 * @package Quark
 */
class QuarkNullable implements IQuarkModel, IQuarkLinkedModel, IQuarkPolymorphicModel {
	/**
	 * @var bool $_changed = false
	 */
	private $_changed = false;
	
	/**
	 * @param $value
	 */
	public function __construct ($value) {
		$this->value = $value;
		$this->default = $value;
	}
	
	/**
	 * @return mixed
	 */
	public function Fields () {
		return array(
			'value' => $this->value,
			'default' => $this->value
		);
	}
	
	/**
	 * @return void
	 */
	public function Rules () { }
	
	/**
	 * @param $raw
	 *
	 * @return mixed
	 */
	public function Link ($raw) {
		$this->_changed = true;
		
		return $this->value = $raw;
	}
	
	/**
	 * @return mixed
	 */
	public function Unlink () {
		return $this->_changed ? $this->value : null;
	}
	
	/**
	 * @return mixed
	 */
	public function PolymorphicExtract () {
		return $this->_changed ? $this->value : null;
	}
}

/**
 * Trait QuarkSessionBehavior
 *
 * @package Quark
 */
trait QuarkSessionBehavior {
	/**
	 * @var object $_rights
	 */
	private $_rights;
	
	/**
	 * @param string $right = ''
	 * @param bool|mixed $value = false
	 *
	 * @return bool|mixed
	 */
	public function Able ($right = '', $value = false) {
		if ($this->_rights == null)
			$this->_rights = new \stdClass();
		
		if (func_num_args() == 2)
			$this->_rights->$right = $value;
		
		return isset($this->_rights->$right) ? $this->_rights->$right : false;
	}
	
	/**
	 * @param string $right = ''
	 * @param $criteria = ''
	 *
	 * @return bool
	 * 
	 * @throws QuarkArchException
	 */
	public function AbleTo ($right = '', $criteria = '') {
		if (!($this instanceof IQuarkAuthorizableModelWithAbilityControl))
			throw new QuarkArchException('[QuarkSessionBehavior::AbleTo] Model ' . get_class($this) . ' is not an IQuarkAuthorizableModelWithAbilityControl');
		
		/**
		 * @var IQuarkAuthorizableModelWithAbilityControl $this
		 */
		
		return $this->AbilityControl($right, $criteria);
	}
	
	/**
	 * @param array|object $rights = []
	 *
	 * @return object
	 */
	public function Rights ($rights = []) {
		if (func_num_args() != 0 && QuarkObject::isTraversable($rights))
			$this->_rights = (object)$rights;
		
		return $this->_rights;
	}
}

/**
 * Class QuarkSessionSource
 *
 * @package Quark
 */
class QuarkSessionSource implements IQuarkStackable {
	/**
	 * @var string $_name
	 */
	private $_name = '';

	/**
	 * @var IQuarkAuthorizationProvider $_provider
	 */
	private $_provider;

	/**
	 * @var IQuarkAuthorizableModel $_user
	 */
	private $_user;

	/**
	 * @param string $name
	 * @param IQuarkAuthorizationProvider $provider
	 * @param IQuarkAuthorizableModel $user
	 */
	public function __construct ($name = '', IQuarkAuthorizationProvider $provider = null, IQuarkAuthorizableModel $user = null) {
		$this->_name = $name;
		$this->_provider = $provider;
		$this->_user = $user;
	}

	/**
	 * @param string $name
	 */
	public function Stacked ($name) { }

	/**
	 * @return string
	 */
	public function &Name () {
		return $this->_name;
	}

	/**
	 * @return IQuarkAuthorizationProvider
	 */
	public function &Provider () {
		return $this->_provider;
	}

	/**
	 * @return IQuarkAuthorizableModel
	 */
	public function &User () {
		return $this->_user;
	}

	/**
	 * @param object $ini
	 *
	 * @return void
	 */
	public function Options ($ini) {
		$this->_provider->SessionOptions($ini);
	}
}

/**
 * Class QuarkSession
 *
 * @package Quark
 */
class QuarkSession {
	/**
	 * @var QuarkSession $_current
	 */
	private static $_current;

	/**
	 * @var QuarkModel|QuarkSessionBehavior|IQuarkAuthorizableModel $user
	 */
	private $_user;

	/**
	 * @var QuarkSessionSource $_source
	 */
	private $_source;

	/**
	 * @var QuarkDTO $_output
	 */
	private $_output;

	/**
	 * @var QuarkClient $_connection = null
	 */
	private $_connection = null;

	/**
	 * @var null $_null
	 */
	private $_null = null;

	/**
	 * @param $key
	 *
	 * @return mixed
	 */
	public function __get ($key) {
		if (!isset($this->_user->$key))
			return $this->_null;

		$field = &$this->_user->$key;

		return $field;
	}

	/**
	 * @param $key
	 * @param $value
	 */
	public function __set ($key, $value) {
		$this->_user->$key = $value;
	}

	/**
	 * @param $key
	 *
	 * @return bool
	 */
	public function __isset ($key) {
		return isset($this->_user->$key);
	}

	/**
	 * @param $name
	 */
	public function __unset ($name) {
		unset($this->_user->$name);
	}

	/**
	 * @param QuarkSessionSource $source = null
	 */
	public function __construct (QuarkSessionSource $source = null) {
		if (func_num_args() == 0) return;

		$this->_source = clone $source;
		self::$_current = &$this;
	}

	/**
	 * @return QuarkModel|QuarkSessionBehavior|IQuarkAuthorizableModel
	 */
	public function &User () {
		return $this->_user;
	}

	/**
	 * @return QuarkKeyValuePair
	 */
	public function ID () {
		return $this->_output ? $this->_output->AuthorizationProvider() : null;
	}

	/**
	 * @return string
	 */
	public function Signature () {
		return $this->_output ? $this->_output->Signature() : '';
	}

	/**
	 * @param bool $extract = false
	 *
	 * @return QuarkSessionBehavior|IQuarkAuthorizableModel|QuarkModelBehavior|\stdClass
	 */
	private function _session ($extract = false) {
		return $this->_user instanceof QuarkModel
			? ($extract ? $this->_user->Extract() : $this->_user->Model())
			: $this->_user;
	}

	/**
	 * @param QuarkDTO $input
	 *
	 * @return bool
	 */
	public function Input (QuarkDTO $input) {
		$data = $this->_source->Provider()->Session($this->_source->Name(), $this->_source->User(), $input);
		$this->_output = $data;

		if ($data == null || $data->AuthorizationPrompt()) return false;

		$this->_user = $this->_source->User()->Session($this->_source->Name(), $data->Data());

		if (!($this->_source->Provider() instanceof IQuarkAuthorizationProviderWithFullOutputControl))
			$this->_output->Data(null);

		return $this->_user != null;
	}

	/**
	 * @param QuarkModel $user = null
	 * @param $criteria = []
	 * @param int $lifetime = 0
	 *
	 * @return bool
	 * @throws QuarkArchException
	 */
	public function ForUser (QuarkModel $user = null, $criteria = [], $lifetime = 0) {
		if ($user == null)
			throw new QuarkArchException('[QuarkSession::ForUser] Given model is null');

		$model = $user->Model();

		if (!($model instanceof IQuarkAuthorizableModel))
			throw new QuarkArchException('[QuarkSession::ForUser] Model ' . get_class($model) . ' is not an IQuarkAuthorizableModel');

		if ($this->_source == null)
			throw new QuarkArchException('[QuarkSession::ForUser] Called session does not have a connected session source. Please check that called service is a IQuarkAuthorizableService or its inheritor.');

		$data = $this->_source->Provider()->Login($this->_source->Name(), $model, $criteria, $lifetime);
		if ($data == null) return false;
		
		$this->_user = $criteria !== null
			? $this->_source->User()->Login($this->_source->Name(), $criteria, $lifetime)
			: $user;
		
		if ($this->_user == null) return false;

		$this->_output = $data;

		return $this->_user != null;
	}

	/**
	 * @param $criteria
	 * @param $lifetime = 0
	 *
	 * @return bool
	 */
	public function Login ($criteria, $lifetime = 0) {
		$this->_user = $this->_source->User()->Login($this->_source->Name(), $criteria, $lifetime);
		if ($this->_user == null) return false;

		$data = $this->_source->Provider()->Login($this->_source->Name(), $this->_session(), $criteria, $lifetime);
		if ($data == null) return false;

		$this->_output = $data;

		return $this->_user != null;
	}

	/**
	 * @return bool
	 */
	public function Logout () {
		if ($this->ID() == null) return false;

		$logout = $this->_source->User()->Logout($this->_source->Name(), $this->ID());
		if ($logout === false) return false;

		$data = $this->_source->Provider()->Logout($this->_source->Name(), $this->_session(), $this->ID());
		if ($data == null) return false;

		$this->_output = $data;
		$this->_user = null;

		return true;
	}

	/**
	 * @return QuarkDTO
	 */
	public function Output () {
		return $this->_output;
	}

	/**
	 * @return QuarkClient
	 */
	public function &Connection () {
		return $this->_connection;
	}

	/**
	 * @return bool
	 */
	public function Commit () {
		return $this->_source->Provider()->SessionCommit($this->_source->Name(), $this->_session(), $this->ID());
	}
	
	/**
	 * @return bool
	 * @throws QuarkArchException
	 */
	private function _able () {
		if ($this->_user == null) return false;
		
		if (!QuarkObject::Uses($this->_user->Model(), 'Quark\\QuarkSessionBehavior'))
			throw new QuarkArchException('[QuarkSession::Able] Model ' . get_class($this->_user->Model()) . ' does not uses QuarkSessionBehavior');
		
		return true;
	}
	
	/**
	 * @param string $right = ''
	 *
	 * @return bool|mixed
	 * 
	 * @throws QuarkArchException
	 */
	public function Able ($right = '') {
		return $this->_able() && $this->_user->Able($right);
	}
	
	/**
	 * @param string $right = ''
	 * @param $criteria = ''
	 *
	 * @return bool
	 * 
	 * @throws QuarkArchException
	 */
	public function AbleTo ($right = '', $criteria = '') {
		return $this->_able() && $this->_user->AbleTo($right, $criteria);
	}

	/**
	 * @param string $name
	 *
	 * @return IQuarkAuthorizationProvider
	 * @throws QuarkArchException
	 */
	public static function Provider ($name) {
		$stack = Quark::Stack($name);

		return $stack instanceof QuarkSessionSource ? $stack->Provider() : null;
	}

	/**
	 * @param string $provider
	 * @param QuarkDTO $input
	 * @param QuarkClient $connection = null
	 *
	 * @return QuarkSession
	 *
	 * @throws QuarkArchException
	 */
	public static function Init ($provider, QuarkDTO $input, QuarkClient &$connection = null) {
		/**
		 * @var QuarkSessionSource $source
		 */
		$source = Quark::Stack($provider);

		if ($source == null) return null;

		$session = new self($source);
		$session->Input($input);
		$session->_connection = $connection;

		return $session;
	}

	/**
	 * @param QuarkKeyValuePair $id
	 *
	 * @return QuarkSession
	 */
	public static function Get (QuarkKeyValuePair $id = null) {
		if ($id == null || $id->Key() == null) return null;

		/**
		 * @var QuarkSessionSource $source
		 */
		$source = Quark::Stack($id->Key());

		if ($source == null) return null;

		$input = new QuarkDTO();
		$input->AuthorizationProvider($id);

		$session = new self($source);
		$session->Input($input);

		return $session;
	}

	/**
	 * @return QuarkSession
	 */
	public static function &Current () {
		return self::$_current;
	}

	/**
	 * Destructor
	 */
	public function __destruct () {
		unset($this->_user);
		unset($this->_source);
		unset($this->_output);
		unset($this->_connection);
	}
}

/**
 * Interface IQuarkAuthorizationProvider
 *
 * @package Quark
 */
interface IQuarkAuthorizationProvider {
	/**
	 * @param string $name
	 * @param IQuarkAuthorizableModel $model
	 * @param QuarkDTO $input
	 *
	 * @return QuarkDTO
	 */
	public function Session($name, IQuarkAuthorizableModel $model, QuarkDTO $input);

	/**
	 * @param string $name
	 * @param IQuarkAuthorizableModel $model
	 * @param $criteria
	 * @param $lifetime
	 *
	 * @return QuarkDTO
	 */
	public function Login($name, IQuarkAuthorizableModel $model, $criteria, $lifetime);

	/**
	 * @param string $name
	 * @param IQuarkAuthorizableModel $model
	 * @param QuarkKeyValuePair $id
	 *
	 * @return QuarkDTO
	 */
	public function Logout($name, IQuarkAuthorizableModel $model, QuarkKeyValuePair $id);

	/**
	 * @param string $name
	 * @param IQuarkAuthorizableModel $model
	 * @param QuarkKeyValuePair $id
	 *
	 * @return bool
	 */
	public function SessionCommit($name, IQuarkAuthorizableModel $model, QuarkKeyValuePair $id);

	/**
	 * @param object $ini
	 *
	 * @return mixed
	 */
	public function SessionOptions($ini);
}

/**
 * Interface IQuarkAuthorizationProviderWithFullOutputControl
 *
 * @package Quark
 */
interface IQuarkAuthorizationProviderWithFullOutputControl extends IQuarkAuthorizationProvider { }

/**
 * Interface IQuarkAuthorizableModel
 *
 * @package Quark
 */
interface IQuarkAuthorizableModel extends IQuarkModel {
	/**
	 * @param string $name
	 * @param $session
	 *
	 * @return mixed
	 */
	public function Session($name, $session);

	/**
	 * @param string $name
	 * @param $criteria
	 * @param int $lifetime (seconds)
	 *
	 * @return QuarkModel|IQuarkAuthorizableModel
	 */
	public function Login($name, $criteria, $lifetime);

	/**
	 * @param string $name
	 * @param QuarkKeyValuePair $id
	 *
	 * @return bool
	 */
	public function Logout($name, QuarkKeyValuePair $id);

}

/**
 * Interface IQuarkAuthorizableModelWithRuntimeFields
 *
 * @package Quark
 */
interface IQuarkAuthorizableModelWithRuntimeFields extends IQuarkAuthorizableModel, IQuarkStrongModelWithRuntimeFields { }

/**
 * Interface IQuarkAuthorizableModelWithAbilityControl
 *
 * @package Quark
 */
interface IQuarkAuthorizableModelWithAbilityControl extends IQuarkAuthorizableModel {
	/**
	 * @param string $right
	 * @param $criteria
	 *
	 * @return bool
	 */
	public function AbilityControl($right, $criteria);
}

/**
 * Class QuarkKeyValuePair
 *
 * @package Quark
 */
class QuarkKeyValuePair {
	/**
	 * @var $_key
	 */
	private $_key;

	/**
	 * @var $_value
	 */
	private $_value;

	/**
	 * @param $key
	 * @param $value
	 */
	public function __construct ($key = '', $value = '') {
		$this->_key = $key;
		$this->_value = $value;
	}

	/**
	 * @param $key
	 *
	 * @return mixed
	 */
	public function Key ($key = '') {
		if (func_num_args() != 0)
			$this->_key = $key;

		return $this->_key;
	}

	/**
	 * @param $value
	 *
	 * @return mixed
	 */
	public function Value ($value = '') {
		if (func_num_args() != 0)
			$this->_value = $value;

		return $this->_value;
	}

	/**
	 * @return QuarkCookie
	 */
	public function ToCookie () {
		return new QuarkCookie($this->_key, $this->_value);
	}

	/**
	 * @return object
	 */
	public function Extract () {
		return (object)array($this->_key => $this->_value);
	}

	/**
	 * @param array $field
	 *
	 * @return QuarkKeyValuePair
	 */
	public static function FromField ($field = []) {
		if (!is_array($field) && !is_object($field)) return null;

		$field = (array)$field;
		$pair = each($field);

		return new self($pair['key'], $pair['value']);
	}

	/**
	 * @param string $delimiter = ''
	 * @param string $source = ''
	 * @param bool $strict = false
	 *
	 * @return QuarkKeyValuePair
	 */
	public static function ByDelimiter ($delimiter = '', $source = '', $strict = false) {
		$pair = explode($delimiter, $source);
		
		return new self($pair[0], sizeof($pair) == 1
			? ($strict ? '' : $pair[0])
			: $pair[1]
		);
	}
}

/**
 * Trait QuarkNetwork
 *
 * @package Quark
 */
trait QuarkNetwork {
	use QuarkEvent;

	/**
	 * @var QuarkURI $_uri
	 */
	private $_uri;

	/**
	 * @var IQuarkNetworkTransport $_transport
	 */
	private $_transport;

	/**
	 * @var QuarkCertificate $_certificate
	 */
	private $_certificate;

	/**
	 * @var int $_timeout = 0
	 */
	private $_timeout = 0;

	/**
	 * @var bool $_blocking = true
	 */
	private $_blocking = true;

	/**
	 * @var $_flags
	 */
	private $_flags;

	/**
	 * @var resource $_socket
	 */
	private $_socket;

	/**
	 * @var bool $_secure = false
	 */
	private $_secure = false;

	/**
	 * @var string $_secureFailureEvent = QuarkClient::EVENT_ERROR_CRYPTOGRAM
	 */
	private $_secureFailureEvent = QuarkClient::EVENT_ERROR_CRYPTOGRAM;

	/**
	 * @var int $_errorNumber
	 */
	private $_errorNumber = 0;

	/**
	 * @var string $_errorString
	 */
	private $_errorString = '';

	/**
	 * @return string
	 */
	public function __toString () {
		return $this->_uri->URI();
	}

	/**
	 * @param resource $socket
	 *
	 * http://php.net/manual/ru/function.stream-socket-shutdown.php#109982
	 * https://github.com/reactphp/socket/blob/master/src/Connection.php
	 * http://chat.stackoverflow.com/transcript/message/7727858#7727858
	 *
	 * @return bool
	 */
	public static function SocketClose ($socket) {
		if (!$socket) return false;

		stream_socket_shutdown($socket, STREAM_SHUT_RDWR);
		stream_set_blocking($socket, false);

		return fclose($socket);
	}

	/**
	 * @param QuarkURI $uri
	 *
	 * @return QuarkURI
	 */
	public function URI (QuarkURI $uri = null) {
		if (func_num_args() == 1 && $uri != null)
			$this->_uri = $uri;

		return $this->_uri;
	}

	/**
	 * @param IQuarkNetworkTransport $transport
	 *
	 * @return IQuarkNetworkTransport
	 */
	public function Transport (IQuarkNetworkTransport $transport = null) {
		if (func_num_args() == 1 && $transport != null)
			$this->_transport = $transport;

		return $this->_transport;
	}

	/**
	 * @param IQuarkNetworkProtocol &$protocol
	 */
	public function Protocol (IQuarkNetworkProtocol &$protocol) {
		$this->On(QuarkClient::EVENT_CONNECT, array(&$protocol, QuarkClient::EVENT_CONNECT));
		$this->On(QuarkClient::EVENT_DATA, array(&$protocol, QuarkClient::EVENT_DATA));
		$this->On(QuarkClient::EVENT_CLOSE, array(&$protocol, QuarkClient::EVENT_CLOSE));
	}

	/**
	 * @param bool $remote = false
	 * @param bool|string $face = false
	 *
	 * @return QuarkURI|null
	 */
	public function ConnectionURI ($remote = false, $face = false) {
		if (!$this->_socket) return null;

		$uri = QuarkURI::FromURI(stream_socket_get_name($this->_socket, $remote));
		if ($uri == null) return null;

		$uri->scheme = $this->_uri->scheme;

		if ($face && $uri->host == QuarkServer::ALL_INTERFACES)
			$uri->host = Quark::IP(is_bool($face) ? $uri->host : $face);

		return $uri;
	}

	/**
	 * @param QuarkCertificate $certificate
	 *
	 * @return QuarkCertificate
	 */
	public function Certificate (QuarkCertificate $certificate = null) {
		if (func_num_args() == 1 && $certificate != null)
			$this->_certificate = $certificate;

		return $this->_certificate;
	}

	/**
	 * @param int $timeout = 0
	 *
	 * @return int
	 */
	public function Timeout ($timeout = 0) {
		if (func_num_args() == 1 && is_int($timeout)) {
			$this->_timeout = $timeout;

			if ($this->_socket)
				stream_set_timeout($this->_socket, $this->_timeout, QuarkThreadSet::TICK);
		}

		return $this->_timeout;
	}

	/**
	 * @param $flags = null
	 *
	 * @return mixed
	 */
	public function Flags ($flags = null) {
		if (func_num_args() != 0)
			$this->_flags = $flags;

		return $this->_flags;
	}

	/**
	 * @param bool|int $block = true
	 *
	 * @return bool
	 */
	public function Blocking ($block = true) {
		if (func_num_args() != 0) {
			$this->_blocking = (bool)$block;

			if ($this->_socket)
				stream_set_blocking($this->_socket, (int)$block);
		}

		return $this->_blocking;
	}
	
	/**
	 * http://php.net/manual/ru/function.stream-socket-server.php#118419
	 * http://php.net/manual/ru/function.stream-socket-enable-crypto.php#119122
	 *
	 * @param int $method = -1
	 * @param bool $flag = false
	 * @param int $timeout = 30
	 *
	 * @return bool
	 */
	public function Secure ($method = -1, $flag = false, $timeout = 30) {
		if (func_num_args() != 0) {
			if (!$this->_socket) return false;
		
			if (!$this->_blocking) stream_set_blocking($this->_socket, 1);
			stream_set_timeout($this->_socket, $timeout, QuarkThreadSet::TICK);
			
			$secure = @stream_socket_enable_crypto($this->_socket, $flag, $method);
			
			stream_set_timeout($this->_socket, $this->_timeout, QuarkThreadSet::TICK);
			if (!$this->_blocking) stream_set_blocking($this->_socket, 0);
			
			if (!$secure)
				$this->TriggerArgs($this->_secureFailureEvent, array('QuarkNetwork cannot enable secure transport for ' . $this->_uri->URI() . ' (' . $this->_uri->Socket() . '). Error: ' . QuarkException::LastError()));
			
			$this->_secure = $flag;
		}
		
		return $this->_secure;
	}

	/**
	 * @param string $event = QuarkClient::EVENT_ERROR_CRYPTOGRAM
	 *
	 * @return string
	 */
	private function _secureFailure ($event = QuarkClient::EVENT_ERROR_CRYPTOGRAM) {
		if (func_num_args() != 0)
			$this->_secureFailureEvent = $event;

		return $this->_secureFailureEvent;
	}

	/**
	 * @param $socket
	 *
	 * @return mixed
	 */
	public function Socket ($socket = null) {
		if (func_num_args() == 1)
			$this->_socket = $socket;

		return $this->_socket;
	}

	/**
	 * @param bool $text
	 *
	 * @return string|object
	 */
	public function Error ($text = false) {
		return $text
			? $this->_errorNumber . ': ' . $this->_errorString
			: (object)array(
				'num' => $this->_errorNumber,
				'msg' => $this->_errorString
			);
	}
}

/**
 * Class QuarkClient
 *
 * @package Quark
 */
class QuarkClient implements IQuarkEventable {
	const EVENT_ERROR_CONNECT = 'ErrorConnect';
	const EVENT_ERROR_CRYPTOGRAM = 'ErrorCryptogram';

	const EVENT_CONNECT = 'OnConnect';
	const EVENT_DATA = 'OnData';
	const EVENT_CLOSE = 'OnClose';

	use QuarkNetwork {
		Secure as private _secure;
	}

	/**
	 * @var int $_timeoutConnect = 0
	 */
	private $_timeoutConnect = 0;

	/**
	 * @var bool $_connected = false
	 */
	private $_connected = false;

	/**
	 * @var QuarkURI $_remote
	 */
	private $_remote;
	
	/**
	 * @var bool $_fromServer = false
	 */
	private $_fromServer = false;

	/**
	 * @var bool $_autoSecure = true
	 */
	private $_autoSecure = true;

	/**
	 * @var QuarkKeyValuePair $_session
	 */
	private $_session;

	/**
	 * @var int $_rps = 0
	 */
	private $_rps = 0;

	/**
	 * @var int $_rpsCount = 0
	 */
	private $_rpsCount = 0;

	/**
	 * @var QuarkTimer $_rpsTimer
	 */
	private $_rpsTimer;

	/**
	 * @var string $_id = ''
	 */
	private $_id = '';

	/**
	 * @var string[] $_channels = []
	 */
	private $_channels = array();

	/**
	 * @param QuarkURI|string $uri
	 * @param IQuarkNetworkTransport $transport
	 * @param QuarkCertificate $certificate
	 * @param int $timeout = 0
	 * @param bool $block = true
	 */
	public function __construct ($uri = '', IQuarkNetworkTransport $transport = null, QuarkCertificate $certificate = null, $timeout = 0, $block = true) {
		$this->URI(QuarkURI::FromURI($uri));
		$this->Transport($transport);
		$this->Certificate($certificate);
		$this->Timeout($timeout);
		$this->Blocking($block);
		$this->Flags(STREAM_CLIENT_CONNECT);
		$this->_secureFailure(self::EVENT_ERROR_CRYPTOGRAM);

		$this->_timeoutConnect = $this->_timeout;

		$this->_rpsTimer = new QuarkTimer(QuarkTimer::ONE_SECOND, function () {
			$this->_rps = $this->_rpsCount;
			$this->_rpsCount = 0;
		});

		$this->_id = Quark::GuID();
	}

	/**
	 * @return bool
	 */
	public function Connect () {
		if ($this->_uri == null || $this->_uri->IsNull())
			return $this->TriggerArgs(self::EVENT_ERROR_CONNECT, array('QuarkClient URI is null'));

		$stream = stream_context_create();

		if ($this->_certificate == null) {
			stream_context_set_option($stream, 'ssl', 'verify_host', false);
			stream_context_set_option($stream, 'ssl', 'verify_peer', false);
			stream_context_set_option($stream, 'ssl', 'verify_peer_name', false);
		}
		else {
			stream_context_set_option($stream, 'ssl', 'local_cert', $this->_certificate->Location());
			stream_context_set_option($stream, 'ssl', 'passphrase', $this->_certificate->Passphrase());
		}
		
		$socket = $this->_uri->SocketURI();
		
		if ($socket->Secure())
			$socket->scheme = QuarkURI::WRAPPER_TCP;
		
		$this->_socket = @stream_socket_client(
			$socket->Socket(),
			$this->_errorNumber,
			$this->_errorString,
			$this->_timeoutConnect,
			$this->_flags,
			$stream
		);

		// TODO: Possible to implement Connection URI comparison (QuarkURI comparison)
		/** @noinspection PhpNonStrictObjectEqualityInspection */
		if (!$this->_socket || $this->_errorNumber != 0 || $this->ConnectionURI() == $this->ConnectionURI(true)) {
			$this->Close(false);
			$this->TriggerArgs(self::EVENT_ERROR_CONNECT, array('QuarkClient cannot connect to ' . $this->_uri->URI() . ' (' . $this->_uri->Socket() . '). Error: ' . QuarkException::LastError()));

			return false;
		}

		if ($socket->Secure() && $this->_autoSecure)
			$this->Secure(true);

		$this->Timeout($this->_timeout);
		$this->Blocking($this->_blocking);

		$this->_connected = true;
		$this->_remote = QuarkURI::FromURI($this->ConnectionURI(true));

		if ($this->_transport instanceof IQuarkNetworkTransport)
			$this->_transport->EventConnect($this);

		return true;
	}
	
	/**
	 * @param bool $flag = false
	 * @param int $timeout = 30
	 *
	 * @return bool
	 */
	public function Secure ($flag = false, $timeout = 30) {
		return func_num_args() != 0
			? $this->_secure($this->_fromServer ? STREAM_CRYPTO_METHOD_TLS_SERVER : STREAM_CRYPTO_METHOD_TLS_CLIENT, $flag, $timeout)
			: $this->_secure();
	}

	/**
	 * @param bool $auto = true
	 *
	 * @return bool
	 */
	public function AutoSecure ($auto = true) {
		if (func_num_args() != 0)
			$this->_autoSecure = $auto;

		return $this->_autoSecure;
	}

	/**
	 * @param string $data
	 *
	 * @return bool
	 */
	public function Send ($data) {
		$out = $this->_socket && $this->_transport instanceof IQuarkNetworkTransport
			? @fwrite($this->_socket, $this->_transport->Send($data))
			: false;

		return $out;
	}

	/**
	 * @param int $max = -1
	 *
	 * @return bool|string
	 */
	public function Receive ($max = -1) {
		if ($this->Closed())
			return $this->Close();

		if (!$this->_socket)
			return false;

		$data = @stream_get_contents($this->_socket, $max);

		return strlen($data) != 0 ? $data : false;
	}

	/**
	 * @param int $max = -1
	 *
	 * @return bool
	 */
	public function Pipe ($max = -1) {
		$data = $this->Receive($max);

		return is_string($data) && $this->_transport instanceof IQuarkNetworkTransport
			? $this->_transport->EventData($this, $data)
			: false;
	}

	/**
	 * @param bool $event = true
	 *
	 * @return bool
	 */
	public function Close ($event = true) {
		$this->_connected = false;

		if ($event && $this->_transport instanceof IQuarkNetworkTransport)
			$this->_transport->EventClose($this);

		$this->_remote = null;
		$this->_transport = null;
		$this->_rps = 0;
		$this->_rpsTimer = null;

		return self::SocketClose($this->_socket);
	}

	/**
	 * @param int $timeout = 0 (seconds)
	 *
	 * @return int
	 */
	public function TimeoutConnect ($timeout = 0) {
		if (func_num_args() != 0)
			$this->_timeoutConnect = $timeout;

		return $this->_timeoutConnect;
	}

	/**
	 * Trigger `Connect` event
	 */
	public function TriggerConnect () {
		$this->TriggerArgs(QuarkClient::EVENT_CONNECT, array(&$this));
	}

	/**
	 * Trigger `Data` event
	 *
	 * @param $data
	 */
	public function TriggerData ($data) {
		$this->_rpsCount++;
		$this->_rpsTimer->Invoke();

		$this->TriggerArgs(QuarkClient::EVENT_DATA, array(&$this, $data));
	}

	/**
	 * Trigger `Close` event
	 */
	public function TriggerClose () {
		$this->TriggerArgs(QuarkClient::EVENT_CLOSE, array(&$this));
	}

	/**
	 * @param IQuarkNetworkTransport $transport
	 * @param resource $socket
	 * @param string $address
	 * @param string $scheme
	 *
	 * @return QuarkClient
	 */
	public static function ForServer (IQuarkNetworkTransport $transport, $socket, $address, $scheme) {
		$uri = QuarkURI::FromURI($address);
		$uri->scheme = $scheme;

		$client = new self($uri, clone $transport);
		$client->_fromServer = true;

		$client->Socket($socket);

		$client->Blocking(false);
		$client->Timeout(0);
		$client->Connected(true);

		return $client;
	}

	/**
	 * @param bool $connected = true
	 *
	 * @return bool
	 */
	public function Connected ($connected = true) {
		if (func_num_args() != 0)
			$this->_connected = $connected;

		return $this->_connected;
	}

	/**
	 * @return bool
	 */
	public function Closed () {
		return !$this->_socket || (@feof($this->_socket) === true && $this->_connected);
	}

	/**
	 * @param QuarkURI|string $uri
	 *
	 * @return QuarkURI
	 */
	public function Remote ($uri = '') {
		if (func_num_args() != 0)
			$this->_remote = $uri instanceof QuarkURI ? $uri : QuarkURI::FromURI($uri);

		return $this->_remote;
	}
	
	/**
	 * @return bool
	 */
	public function FromServer () {
		return $this->_fromServer;
	}

	/**
	 * @param QuarkKeyValuePair $session
	 *
	 * @return QuarkKeyValuePair
	 */
	public function &Session (QuarkKeyValuePair $session = null) {
		if (func_num_args() != 0)
			$this->_session = $session;

		return $this->_session;
	}

	/**
	 * @return int
	 */
	public function RPS () {
		return $this->_rps;
	}

	/**
	 * @return string
	 */
	public function ID () {
		return $this->_id;
	}

	/**
	 * @param string|string[] $channel = ''
	 *
	 * @return QuarkClient
	 */
	public function Subscribe ($channel = '') {
		if (is_array($channel)) $this->_channels = array_merge($this->_channels, $channel);
		else $this->_channels[] = $channel;

		return $this;
	}

	/**
	 * @param string $channel = ''
	 *
	 * @return QuarkClient
	 */
	public function Unsubscribe ($channel = '') {
		foreach ($this->_channels as $i => &$c)
			if ($channel == $c)
				unset($this->_channels[$i]);
		
		return $this;
	}

	/**
	 * @param string $channel = ''
	 * @param bool $strict = false
	 *
	 * @return bool
	 */
	public function Subscribed ($channel = '', $strict = false) {
		return in_array($channel, $this->_channels, $strict);
	}

	/**
	 * @return string[]
	 */
	public function &Channels () {
		return $this->_channels;
	}
}

/**
 * Class QuarkServer
 *
 * @package Quark
 */
class QuarkServer implements IQuarkEventable {
	const ALL_INTERFACES = '0.0.0.0';
	const TCP_ALL_INTERFACES_RANDOM_PORT = 'tcp://0.0.0.0:0';

	const EVENT_ERROR_LISTEN = 'ErrorListen';
	const EVENT_ERROR_CRYPTOGRAM = 'ErrorCryptogram';

	use QuarkNetwork {
		Secure as private _secure;
	}

	/**
	 * @var bool $_run = false
	 */
	private $_run = false;

	/**
	 * @var array $_read = []
	 */
	private $_read = array();

	/**
	 * @var array $_write = []
	 */
	private $_write = array();

	/**
	 * @var array $_except = []
	 */
	private $_except = array();

	/**
	 * @var QuarkClient[] $_clients = []
	 */
	private $_clients = array();

	/**
	 * @param QuarkURI|string $uri
	 * @param IQuarkNetworkTransport $transport
	 * @param QuarkCertificate $certificate
	 * @param int $timeout = 0
	 */
	public function __construct ($uri = '', IQuarkNetworkTransport $transport = null, QuarkCertificate $certificate = null, $timeout = 0) {
		$this->URI(QuarkURI::FromURI($uri));
		$this->Transport($transport);
		$this->Certificate($certificate);
		$this->Timeout($timeout);
		$this->Flags(STREAM_SERVER_LISTEN);
		$this->_secureFailure(self::EVENT_ERROR_CRYPTOGRAM);
	}

	/**
	 * @return bool
	 */
	public function Bind () {
		if ($this->_uri == null || $this->_uri->IsNull())
			return $this->TriggerArgs(self::EVENT_ERROR_LISTEN, array('QuarkServer URI is null'));

		$stream = stream_context_create();

		if ($this->_certificate == null) {
			stream_context_set_option($stream, 'ssl', 'verify_host', false);
			stream_context_set_option($stream, 'ssl', 'verify_peer', false);
			stream_context_set_option($stream, 'ssl', 'verify_peer_name', false);
		}
		else {
			stream_context_set_option($stream, 'ssl', 'local_cert', $this->_certificate->Location());
			stream_context_set_option($stream, 'ssl', 'verify_peer', false);
			stream_context_set_option($stream, 'ssl', 'allow_self_signed', true);
			stream_context_set_option($stream, 'ssl', 'passphrase', $this->_certificate->Passphrase());
		}

		$socket = $this->_uri->SocketURI();

		if ($socket->Secure())
			$socket->scheme = QuarkURI::WRAPPER_TCP;

		$this->_socket = @stream_socket_server(
			$socket->Socket(),
			$this->_errorNumber,
			$this->_errorString,
			$socket->scheme == QuarkURI::WRAPPER_UDP && $this->_flags == STREAM_SERVER_LISTEN
				? STREAM_SERVER_BIND
				: STREAM_SERVER_BIND|$this->_flags,
			$stream
		);

		if (!$this->_socket) {
			$this->TriggerArgs(self::EVENT_ERROR_LISTEN, array('QuarkServer cannot listen to ' . $this->_uri->URI() . ' (' . $this->_uri->Socket() . '). Error: ' . QuarkException::LastError()));

			return false;
		}

		$this->Timeout(0);
		$this->Blocking(0);
		
		if (!function_exists('\socket_import_stream')) Quark::Log('[QuarkServer] Function \socket_import_stream does not exists. Cannot set TCP_NO_DELAY to main server socket', Quark::LOG_WARN);
		else {
			$sock = \socket_import_stream($this->_socket);
			\socket_set_option($sock, SOL_TCP, TCP_NODELAY, true);
		}

		$this->_read = array($this->_socket);
		$this->_run = true;

		return true;
	}
	
	/**
	 * @param bool $flag = false
	 * @param int $timeout = 30
	 *
	 * @return bool
	 */
	public function Secure ($flag = false, $timeout = 30) {
		return func_num_args() != 0
			? $this->_secure(STREAM_CRYPTO_METHOD_TLS_SERVER, $flag, $timeout)
			: $this->_secure();
	}

	/**
	 * @return bool
	 */
	public function Pipe () {
		if ($this->_socket == null) return false;

		if (sizeof($this->_read) == 0)
			$this->_read = array($this->_socket);

		if (stream_select($this->_read, $this->_write, $this->_except, 0, 0) === false) return true;

		if (in_array($this->_socket, $this->_read, true)) {
			$socket = stream_socket_accept($this->_socket, $this->_timeout, $address);
			
			$client = QuarkClient::ForServer($this->_transport, $socket, $address, $this->URI()->scheme);
			$client->Remote(QuarkURI::FromURI($this->ConnectionURI()));

			if ($this->_uri->SocketURI()->Secure())
				$client->Secure(true);

			$client->Delegate(QuarkClient::EVENT_CONNECT, $this);
			$client->Delegate(QuarkClient::EVENT_DATA, $this);
			$client->Delegate(QuarkClient::EVENT_CLOSE, $this);

			$client->Transport()->EventConnect($client);

			$this->_clients[] = $client;

			unset($socket, $address, $client);
		}

		$this->_read = array();
		$this->_write = array();
		$this->_except = array();

		foreach ($this->_clients as $key => &$client) {
			if ($client->Closed()) {
				unset($this->_clients[$key]);
				$client->Close();
				continue;
			}

			$client->Pipe();
		}

		unset($key, $client);

		return true;
	}

	/**
	 * @return bool
	 */
	public function Running () {
		return $this->_run;
	}

	/**
	 * @return QuarkServer
	 */
	public function Stop () {
		$this->_run = false;
		self::SocketClose($this->_socket);

		return $this;
	}

	/**
	 * @return QuarkClient[]
	 */
	public function &Clients () {
		return $this->_clients;
	}

	/**
	 * @param QuarkClient $client
	 *
	 * @return bool
	 */
	public function Has (QuarkClient $client) {
		foreach ($this->_clients as $item)
			if ($item->ConnectionURI()->URI() == $client->ConnectionURI()->URI()) return true;

		return false;
	}

	/**
	 * @param string $data
	 * @param callable(QuarkClient $client) $filter = null
	 *
	 * @return bool
	 */
	public function Broadcast ($data, callable $filter = null) {
		$ok = true;

		foreach ($this->_clients as $i => &$client) {
			if ($filter && !$filter($client)) continue;

			$ok &= $client->Send($data);
		}

		return $ok;
	}
}

/**
 * Class QuarkPeer
 *
 * @package Quark
 */
class QuarkPeer {
	/**
	 * @var IQuarkPeer $_protocol
	 */
	private $_protocol;

	/**
	 * @var QuarkServer $_server
	 */
	private $_server;

	/**
	 * @var QuarkClient[] $_peers
	 */
	private $_peers = array();

	/**
	 * @var QuarkCertificate $_certificate
	 */
	private $_certificate;

	/**
	 * @param IQuarkPeer &$protocol
	 * @param QuarkURI|string $bind
	 * @param QuarkURI[]|string[] $connect
	 * @param QuarkCertificate $certificate
	 */
	public function __construct (IQuarkPeer &$protocol = null, $bind = '', $connect = [], QuarkCertificate $certificate = null) {
		$this->_protocol = $protocol;
		$this->_server = new QuarkServer($bind, $this->_protocol->NetworkTransport(), $certificate);
		$this->_server->On(QuarkClient::EVENT_CONNECT, array(&$this->_protocol, 'NetworkServerConnect'));
		$this->_server->On(QuarkClient::EVENT_DATA, array(&$this->_protocol, 'NetworkServerData'));
		$this->_server->On(QuarkClient::EVENT_CLOSE, array(&$this->_protocol, 'NetworkServerClose'));

		$this->Certificate($certificate);
		$this->Peers($connect);
	}

	/**
	 * @return string
	 */
	public function __toString () {
		return $this->_server->URI()->URI();
	}

	/**
	 * @param QuarkURI $uri
	 *
	 * @return QuarkURI
	 */
	public function URI (QuarkURI $uri = null) {
		if (func_num_args() != 0)
			$this->_server->URI($uri);

		return $this->_server->URI();
	}

	/**
	 * @param QuarkCertificate $certificate
	 *
	 * @return QuarkCertificate
	 */
	public function Certificate (QuarkCertificate $certificate = null) {
		if (func_num_args() == 1 && $certificate != null)
			$this->_certificate = $certificate;

		return $this->_certificate;
	}

	/**
	 * @return bool
	 */
	public function Bind () {
		return $this->_server->Bind();
	}

	/**
	 * @param QuarkClient|QuarkURI|string $peer
	 *
	 * @return bool
	 */
	public function Has ($peer) {
		if ($peer instanceof QuarkClient && $peer->ConnectionURI() != null)
			$peer = $peer->ConnectionURI()->URI();

		$peer = QuarkURI::FromURI($peer);

		if (!$peer) return false;

		foreach ($this->_peers as $item) {
			$uri = $item->ConnectionURI(true, $peer->host);

			if ($uri == null) continue;
			if ($uri->URI() == $peer) return true;
		}

		return false;
	}

	/**
	 * @param QuarkURI|string $uri
	 * @param bool $unique = true
	 * @param bool $loopBack = false
	 *
	 * @return bool
	 */
	public function Peer ($uri = null, $unique = true, $loopBack = false) {
		$uri = QuarkURI::FromURI($uri);

		if (!$uri) return false;

		$server = $this->_server->ConnectionURI(false, $uri->host)->URI();
		$uri = $uri->URI();

		if ($uri == ':///') return false;

		if (!$loopBack && $uri == $server) return false;
		if ($unique && $this->Has($uri)) return false;

		$peer = new QuarkClient($uri, $this->_protocol->NetworkTransport(), $this->_certificate, 0, false);
		$peer->On(QuarkClient::EVENT_CONNECT, array(&$this->_protocol, 'NetworkClientConnect'));
		$peer->On(QuarkClient::EVENT_DATA, array(&$this->_protocol, 'NetworkClientData'));
		$peer->On(QuarkClient::EVENT_CLOSE, array(&$this->_protocol, 'NetworkClientClose'));

		$ok = $peer->Connect();

		$this->_peers[] = $peer;

		return $ok;
	}

	/**
	 * @param QuarkURI[]|string[] $peers
	 * @param bool $unique = true
	 * @param bool $loopBack = false
	 *
	 * @return QuarkClient[]|bool
	 */
	public function &Peers ($peers = [], $unique = true, $loopBack = false) {
		if (func_num_args() != 0 && is_array($peers))
			foreach ($peers as $peer)
				$this->Peer($peer, $unique, $loopBack);

		return $this->_peers;
	}

	/**
	 * @param string $data
	 *
	 * @return bool
	 */
	public function Broadcast ($data = '') {
		$ok = true;

		foreach ($this->_peers as $peer)
			$ok &= $peer->Send($data);

		return $ok;
	}

	/**
	 * @return bool
	 */
	public function Pipe () {
		$ok = $this->_server->Pipe();

		foreach ($this->_peers as $peer)
			$ok &= $peer->Pipe();

		return $ok;
	}

	/**
	 * @return bool
	 */
	public function Running () {
		return $this->_server->Running();
	}

	/**
	 * @return QuarkServer
	 */
	public function Server () {
		return $this->_server;
	}
}

/**
 * Interface IQuarkPeer
 *
 * @package Quark
 */
interface IQuarkPeer {
	// NodeNetwork
	/**
	 * @return IQuarkNetworkTransport
	 */
	public function NetworkTransport();

	// NodeNetworkClient
	/**
	 * @param QuarkClient $node
	 *
	 * @return mixed
	 */
	public function NetworkClientConnect(QuarkClient $node);

	/**
	 * @param QuarkClient $node
	 * @param string $data
	 *
	 * @return mixed
	 */
	public function NetworkClientData(QuarkClient $node, $data);

	/**
	 * @param QuarkClient $node
	 *
	 * @return mixed
	 */
	public function NetworkClientClose(QuarkClient $node);

	// NodeNetworkServer
	/**
	 * @param QuarkClient $node
	 *
	 * @return mixed
	 */
	public function NetworkServerConnect(QuarkClient $node);

	/**
	 * @param QuarkClient $node
	 * @param string $data
	 *
	 * @return mixed
	 */
	public function NetworkServerData(QuarkClient $node, $data);

	/**
	 * @param QuarkClient $node
	 *
	 * @return mixed
	 */
	public function NetworkServerClose(QuarkClient $node);
}

/**
 * Class QuarkCluster
 *
 * @package Quark\NetworkTransports
 */
class QuarkCluster {
	/**
	 * @var IQuarkCluster $_cluster
	 */
	private $_cluster;

	/**
	 * @var QuarkServer $_server
	 */
	private $_server;

	/**
	 * @var QuarkPeer $_network
	 */
	private $_network;

	/**
	 * @var QuarkClient|QuarkServer $_controller
	 */
	private $_controller;

	/**
	 * @var QuarkServer $_terminal
	 */
	private $_terminal;

	/**
	 * @var bool $_startedNode = false
	 */
	private $_startedNode = false;

	/**
	 * @var bool $_startedController = false
	 */
	private $_startedController = false;

	/**
	 * @param IQuarkCluster &$cluster
	 */
	public function __construct (IQuarkCluster &$cluster = null) {
		$this->_cluster = $cluster;
	}

	/**
	 * @return QuarkServer
	 */
	public function &Server () {
		return $this->_server;
	}

	/**
	 * @return QuarkPeer
	 */
	public function &Network () {
		return $this->_network;
	}

	/**
	 * @return QuarkClient|QuarkServer
	 */
	public function &Controller () {
		return $this->_controller;
	}

	/**
	 * @return QuarkServer
	 */
	public function &Terminal () {
		return $this->_terminal;
	}

	/**
	 * @param string $data
	 *
	 * @return bool
	 */
	public function Broadcast ($data) {
		if ($this->_controller instanceof QuarkServer)
			return $this->_controller->Broadcast($data);

		$this->_cluster->NetworkServerData(null, $data);
		return $this->_network->Broadcast($data);
	}

	/**
	 * @param string $data
	 *
	 * @return bool
	 */
	public function Control ($data) {
		return $this->_controller instanceof QuarkServer
			? $this->_cluster->ControllerServerData(new QuarkClient(), $data)
			: $this->_controller->Send($data);
	}

	/**
	 * @return QuarkClient[]
	 */
	public function Nodes () {
		return $this->_controller instanceof QuarkServer
			? $this->_controller->Clients()
			: $this->_network->Server()->Clients();
	}

	/**
	 * @param IQuarkCluster &$cluster
	 * @param QuarkURI|string $external
	 * @param QuarkURI|string $internal
	 * @param QuarkURI|string $controller
	 *
	 * @return QuarkCluster
	 */
	public static function NodeInstance (IQuarkCluster &$cluster, $external, $internal, $controller = '') {
		$node = new self($cluster);

		$node->_server = new QuarkServer($external, $cluster->ClientTransport());
		$node->_server->On(QuarkClient::EVENT_CONNECT, array(&$cluster, 'ClientConnect'));
		$node->_server->On(QuarkClient::EVENT_DATA, array(&$cluster, 'ClientData'));
		$node->_server->On(QuarkClient::EVENT_CLOSE, array(&$cluster, 'ClientClose'));

		$node->_network = new QuarkPeer($cluster, $internal);

		$node->_controller = new QuarkClient($controller, $cluster->ControllerTransport());
		$node->_controller->On(QuarkClient::EVENT_CONNECT, array(&$cluster, 'ControllerClientConnect'));
		$node->_controller->On(QuarkClient::EVENT_DATA, array(&$cluster, 'ControllerClientData'));
		$node->_controller->On(QuarkClient::EVENT_CLOSE, array(&$cluster, 'ControllerClientClose'));

		return $node;
	}

	/**
	 * @return bool
	 */
	public function NodeBind () {
		$run = true;

		if (!$this->_startedNode) {
			$start = $this->_cluster->NodeStart($this->_server, $this->_network, $this->_controller);

			if ($start === false) return false;
			$this->_startedNode = true;
		}

		if (!$this->_server->Running())
			$run = $this->_server->Bind();

		if (!$this->_network->Running())
			$this->_network->Bind();

		if (!$this->_controller->Connected())
			$this->_controller->Connect();

		return $run;
	}

	/**
	 * @return bool
	 * @throws QuarkArchException
	 */
	public function NodePipe () {
		$run = $this->NodeBind() &&
			$this->_server->Pipe();
			$this->_network->Pipe();
			$this->_controller->Pipe();

		if (!$this->_server->Running())
			throw new QuarkArchException('Cluster server not started. Expected address ' . $this->_server);

		if (!$this->_network->Running())
			throw new QuarkArchException('Cluster peering not started. Expected address ' . $this->_network);

		return $run;
	}

	/**
	 * @param IQuarkCluster &$cluster
	 * @param QuarkURI|string $external
	 * @param QuarkURI|string $internal
	 *
	 * @return QuarkCluster
	 */
	public static function ControllerInstance (IQuarkCluster &$cluster, $external, $internal) {
		$controller = new self($cluster);

		$controller->_controller = new QuarkServer($internal, $cluster->ControllerTransport());
		$controller->_controller->On(QuarkClient::EVENT_CONNECT, array(&$cluster, 'ControllerServerConnect'));
		$controller->_controller->On(QuarkClient::EVENT_DATA, array(&$cluster, 'ControllerServerData'));
		$controller->_controller->On(QuarkClient::EVENT_CLOSE, array(&$cluster, 'ControllerServerClose'));

		$controller->_terminal = new QuarkServer($external, $cluster->TerminalTransport());
		$controller->_terminal->On(QuarkClient::EVENT_CONNECT, array(&$cluster, 'TerminalConnect'));
		$controller->_terminal->On(QuarkClient::EVENT_DATA, array(&$cluster, 'TerminalData'));
		$controller->_terminal->On(QuarkClient::EVENT_CLOSE, array(&$cluster, 'TerminalClose'));

		return $controller;
	}

	/**
	 * @return bool
	 * @throws QuarkArchException
	 */
	public function ControllerBind () {
		if ($this->_controller instanceof QuarkClient)
			throw new QuarkArchException('Cluster controller not started. Controller in client mode.');

		if (!$this->_startedController) {
			$start = $this->_cluster->ControllerStart($this->_controller, $this->_terminal);

			if ($start === false) return false;
			$this->_startedController = true;
		}

		$run = true;

		if (!$this->_controller->Running())
			$run = $this->_controller->Bind();

		if (!$this->_terminal->Running())
			$run = $this->_terminal->Bind();

		return $run;
	}

	/**
	 * @return bool
	 * @throws QuarkArchException
	 */
	public function ControllerPipe () {
		if ($this->_controller instanceof QuarkClient)
			throw new QuarkArchException('Cluster controller not started. Controller in client mode.');

		$run = $this->ControllerBind() &&
			$this->_controller->Pipe() &&
			$this->_terminal->Pipe();

		if (!$this->_controller->Running())
			throw new QuarkArchException('Cluster controller not started. Expected address ' . $this->_controller);

		if (!$this->_terminal->Running())
			throw new QuarkArchException('Cluster terminal not started. Expected address ' . $this->_terminal);

		return $run;
	}
}

/**
 * Interface IQuarkCluster
 *
 * @package Quark\NetworkTransports
 */
interface IQuarkCluster extends IQuarkPeer {
	// NodeServer
	/**
	 * @param QuarkServer $server
	 * @param QuarkPeer $network
	 * @param QuarkClient $controller
	 *
	 * @return mixed
	 */
	public function NodeStart(QuarkServer $server, QuarkPeer $network, QuarkClient $controller);

	/**
	 * @return IQuarkNetworkTransport
	 */
	public function ClientTransport();

	/**
	 * @param QuarkClient $client
	 *
	 * @return mixed
	 */
	public function ClientConnect(QuarkClient $client);

	/**
	 * @param QuarkClient $client
	 * @param string $data
	 *
	 * @return mixed
	 */
	public function ClientData(QuarkClient $client, $data);

	/**
	 * @param QuarkClient $client
	 *
	 * @return mixed
	 */
	public function ClientClose(QuarkClient $client);

	// ControllerNetwork
	/**
	 * @param QuarkServer $controller
	 * @param QuarkServer $terminal
	 *
	 * @return mixed
	 */
	public function ControllerStart(QuarkServer $controller, QuarkServer $terminal);

	/**
	 * @return IQuarkNetworkTransport
	 */
	public function ControllerTransport();

	// ControllerNetworkClient
	/**
	 * @param QuarkClient $controller
	 *
	 * @return mixed
	 */
	public function ControllerClientConnect(QuarkClient $controller);

	/**
	 * @param QuarkClient $controller
	 * @param string $data
	 *
	 * @return mixed
	 */
	public function ControllerClientData(QuarkClient $controller, $data);

	/**
	 * @param QuarkClient $controller
	 *
	 * @return mixed
	 */
	public function ControllerClientClose(QuarkClient $controller);

	// ControllerNetworkServer
	/**
	 * @param QuarkClient $node
	 *
	 * @return mixed
	 */
	public function ControllerServerConnect(QuarkClient $node);

	/**
	 * @param QuarkClient $node
	 * @param string $data
	 *
	 * @return mixed
	 */
	public function ControllerServerData(QuarkClient $node, $data);

	/**
	 * @param QuarkClient $node
	 *
	 * @return mixed
	 */
	public function ControllerServerClose(QuarkClient $node);

	// ControllerTerminal
	/**
	 * @return IQuarkNetworkTransport
	 */
	public function TerminalTransport();

	/**
	 * @param QuarkClient $terminal
	 *
	 * @return mixed
	 */
	public function TerminalConnect(QuarkClient $terminal);

	/**
	 * @param QuarkClient $terminal
	 * @param string $data
	 *
	 * @return mixed
	 */
	public function TerminalData(QuarkClient $terminal, $data);

	/**
	 * @param QuarkClient $terminal
	 *
	 * @return mixed
	 */
	public function TerminalClose(QuarkClient $terminal);
}

/**
 * Class QuarkStreamEnvironment
 *
 * @package Quark\NetworkTransports
 */
class QuarkStreamEnvironment implements IQuarkEnvironment, IQuarkCluster {
	const URI_NODE_INTERNAL = QuarkServer::TCP_ALL_INTERFACES_RANDOM_PORT;
	const URI_NODE_EXTERNAL = 'ws://0.0.0.0:25000';
	const URI_CONTROLLER_INTERNAL = 'tcp://0.0..0:25800';
	const URI_CONTROLLER_EXTERNAL = 'ws://0.0.0.0:25900';

	const PACKAGE_REQUEST = 'url';
	const PACKAGE_RESPONSE = 'response';
	const PACKAGE_EVENT = 'event';
	const PACKAGE_COMMAND = 'cmd';

	const COMMAND_STATE = 'state';
	const COMMAND_BROADCAST = 'broadcast';
	const COMMAND_ANNOUNCE = 'announce';
	const COMMAND_AUTHORIZE = 'authorize';
	const COMMAND_INFRASTRUCTURE = 'infrastructure';
	const COMMAND_ENDPOINT = 'endpoint';

	use QuarkEvent;

	/**
	 * @var QuarkCluster $_cluster
	 */
	private $_cluster;

	/**
	 * @var IQuarkNetworkTransport $_transportClient
	 */
	private $_transportClient;

	/**
	 * @var IQuarkNetworkTransport $_transportTerminal
	 */
	private $_transportTerminal;

	/**
	 * @var string $_connect
	 */
	private $_connect;

	/**
	 * @var string $_close
	 */
	private $_close;

	/**
	 * @var string $_unknown
	 */
	private $_unknown;

	/**
	 * @var bool $_controllerFromConfig = false
	 */
	private $_controllerFromConfig = false;

	/**
	 * @var string $_name = ''
	 */
	private $_name = '';

	/**
	 * @var QuarkJSONIOProcessor $_json
	 */
	private static $_json;

	/**
	 * Private constructor
	 */
	private function __construct () {
		if (self::$_json == null)
			self::$_json = new QuarkJSONIOProcessor();
	}

	/**
	 * @param string $name
	 * @param array|object $data
	 *
	 * @return bool
	 */
	public static function ControllerCommand ($name = '', $data = []) {
		$client = new QuarkClient(Quark::Config()->ClusterControllerConnect(), self::TCPProtocol());

		$client->On(QuarkClient::EVENT_CONNECT, function (QuarkClient $client) use (&$name, &$data) {
			$client->Send(self::Package(self::PACKAGE_COMMAND, $name, $data, null, true));
			$client->Close();
		});

		$ok = $client->Connect();

		unset($client);

		return $ok;
	}

	/**
	 * @param string $name = ''
	 *
	 * @return QuarkURI
	 */
	public static function ConnectionURI ($name = '') {
		$environment = Quark::Environment();
		$host = Quark::Config()->StreamHost();
		
		foreach ($environment as $env)
			if ($env instanceof QuarkStreamEnvironment && $env->EnvironmentName() == $name)
				return $host == ''
					? $env->ServerURI()->ConnectionURI()
					: $env->ServerURI()->ConnectionURI($host);

		return null;
	}

	/**
	 * @return array
	 */
	private function _node () {
		$internal = $this->_cluster->Network()->Server()->ConnectionURI();
		$internal->host = Quark::HostIP();

		$external = $this->_cluster->Server()->URI();
		$external->host = Quark::HostIP();

		$clients = $this->_cluster->Server()->Clients();
		$frontend = array();
		$rps = 0;
		$num = sizeof($clients);

		foreach ($clients as $i => &$client) {
			$rps += $client->RPS();
			$frontend[] = array(
				'uri' => $client->URI()->URI(),
				'rps' => $client->RPS()
			);
		}

		unset($i, $client, $clients);

		$peers = $this->_cluster->Network()->Server()->Clients();
		$backend = array();

		foreach ($peers as $i => &$peer)
			$backend[] = $peer->URI()->URI();

		unset($i, $peer, $peers);

		return array(
			'uri' => array(
				'internal' => $internal->URI(),
				'external' => $external->URI()
			),
			'clients' => $frontend,
			'peers' => $backend,
			'rps' => $num == 0 ? 0 : $rps / $num
		);
	}

	/**
	 * @return bool
	 */
	private function _announce () {
		return $this->_cluster->Control(self::Package(
			self::PACKAGE_COMMAND,
			self::COMMAND_ANNOUNCE,
			$this->_node(), null, true
		));
	}

	/**
	 * @return array
	 */
	private function _infrastructure () {
		$data = array();
		$nodes = $this->_cluster->Controller()->Clients();

		foreach ($nodes as $i => &$node) {
			if (!isset($node->state) || !isset($node->signature)) continue;

			$data[] = $node->state;
		}

		unset($i, $node, $nodes);

		return $data;
	}

	/**
	 * @return bool
	 */
	private function _monitor () {
		return $this->_cluster->Terminal()->Broadcast(self::Package(
			self::PACKAGE_COMMAND,
			self::COMMAND_INFRASTRUCTURE,
			$this->_infrastructure(), null, true
		), function (QuarkClient $terminal) {
			return isset($terminal->signature) && $terminal->signature == Quark::Config()->ClusterKey();
		});
	}

	/**
	 * @param string $source
	 * @param string $cmd
	 * @param callable $callback = null
	 * @param bool $signature = true
	 *
	 * @return bool
	 */
	private function _cmd ($source, $cmd, callable $callback = null, $signature = true) {
		if ($callback == null) return false;

		$json = self::$_json->Decode($source);

		if (!isset($json->cmd) || $json->cmd != $cmd) return false;
		if (!isset($json->data)) return false;
		if ($signature && (!isset($json->signature) || $json->signature != Quark::Config()->ClusterKey())) return false;

		$callback($json->data, isset($json->signature) ? $json->signature : null);
		unset($json);

		return true;
	}

	/**
	 * @param string $url
	 * @param string $method
	 * @param QuarkClient $client = null
	 * @param array|object $input = null
	 * @param array|object $session = null
	 */
	private function _pipe ($url, $method, QuarkClient &$client = null, $input = null, $session = null) {
		$service = null;
		$connected = $client instanceof QuarkClient;

		try {
			$service = new QuarkService($url, new QuarkJSONIOProcessor(), new QuarkJSONIOProcessor());
		}
		catch (QuarkHTTPException $e) {
			if ($this->_unknown)
				$service = new QuarkService($this->_unknown, new QuarkJSONIOProcessor(), new QuarkJSONIOProcessor());
		}

		if ($service != null) {
			$service->Input()->Data($service->Input()->URI()->Params());

			if ($input !== null)
				$service->Input()->MergeData($input);

			if ($session != null) {
				$service->Input()->AuthorizationProvider(QuarkKeyValuePair::FromField($session));

				if ($connected)
					$client->Session($service->Input()->AuthorizationProvider());
			}

			if ($connected)
				$service->Input()->Remote($client->URI());

			if (!$connected || $service->Authorize(false, $client))
				$service->Invoke($method, $input !== null ? array($service->Input()) : array(), $connected);

			$session = $service->Session();

			if ($connected) {
				$client->Session($session->ID());
				$client->Send(self::Package(self::PACKAGE_RESPONSE, $service->URL(), $service->Output()->Data(), $session));
			}
		}

		unset($session, $service, $connected, $input, $client, $method, $url);
	}

	/**
	 * @param string $method
	 * @param string $data
	 * @param bool $signature = false
	 * @param QuarkClient $client = null
	 */
	private function _pipeData ($method, $data, $signature = false, QuarkClient &$client = null) {
		$json = self::$_json->Decode($data);

		if ($json && isset($json->url) && ($signature ? (isset($json->signature) && $json->signature == Quark::Config()->ClusterKey()) : true)) {
			if (isset($json->language))
				Quark::CurrentLanguage($json->language);

			$this->_pipe($json->url, $method, $client, isset($json->data) ? $json->data : new \stdClass(), isset($json->session) ? $json->session : null);
		}

		unset($json, $client, $data, $method);
	}

	/**
	 * @param string $name
	 * @param IQuarkNetworkTransport $transport
	 * @param QuarkURI|string $external = self::URI_NODE_EXTERNAL
	 * @param QuarkURI|string $internal = self::URI_NODE_INTERNAL
	 * @param QuarkURI|string $controller = ''
	 *
	 * @return QuarkStreamEnvironment
	 */
	public static function ClusterNode ($name, IQuarkNetworkTransport $transport, $external = self::URI_NODE_EXTERNAL, $internal = self::URI_NODE_INTERNAL, $controller = '') {
		$stream = new self();

		$stream->_name = $name;
		$stream->_transportClient = $transport;
		$stream->_cluster = QuarkCluster::NodeInstance($stream, $external, $internal, !$controller ? Quark::Config()->ClusterControllerConnect() : $controller);

		if (!$controller)
			$stream->_controllerFromConfig = true;

		return $stream;
	}

	/**
	 * @param IQuarkNetworkTransport $transport
	 * @param QuarkURI|string $external = self::URI_CONTROLLER_EXTERNAL
	 * @param QuarkURI|string $internal = self::URI_CONTROLLER_INTERNAL
	 *
	 * @return QuarkStreamEnvironment
	 */
	public static function ClusterController (IQuarkNetworkTransport $transport, $external = self::URI_CONTROLLER_EXTERNAL, $internal = self::URI_CONTROLLER_INTERNAL) {
		$stream = new self();

		$stream->_transportTerminal = $transport;
		$stream->_cluster = QuarkCluster::ControllerInstance($stream, $external, $internal);

		return $stream;
	}

	/**
	 * @return QuarkTCPNetworkTransport
	 */
	public static function TCPProtocol () {
		if (self::$_json == null)
			self::$_json = new QuarkJSONIOProcessor();

		return new QuarkTCPNetworkTransport(array(&self::$_json, 'Batch'));
	}

	/**
	 * @param string $type = self::PACKAGE_REQUEST
	 * @param string $url = ''
	 * @param QuarkDTO|object|array $data = []
	 * @param QuarkSession $session = null
	 * @param bool $signature = false
	 *
	 * @return array
	 */
	public static function Payload ($type = self::PACKAGE_REQUEST, $url = '', $data = [], QuarkSession $session = null, $signature = false) {
		$payload = array(
			$type => $url,
			'data' => $data instanceof QuarkDTO ? $data->Data() : $data
		);

		if ($session && $session->ID())
			$payload['session'] = $session->ID()->Extract();

		if ($signature)
			$payload['signature'] = Quark::Config()->ClusterKey();

		return $payload;
	}

	/**
	 * @param string $type = PACKAGE_SERVICE
	 * @param string $url
	 * @param QuarkDTO|object|array $data
	 * @param QuarkSession $session = null
	 * @param bool $signature = false
	 *
	 * @return string
	 */
	public static function Package ($type, $url, $data, QuarkSession $session = null, $signature = false) {
		return self::$_json->Encode(self::Payload($type, $url, $data, $session, $signature));
	}

	/**
	 * @param string $uri
	 *
	 * @return string
	 */
	public function StreamConnect ($uri = '') {
		if (func_num_args() != 0)
			$this->_connect = $uri;

		return $this->_connect;
	}

	/**
	 * @param string $uri
	 *
	 * @return string
	 */
	public function StreamClose ($uri = '') {
		if (func_num_args() != 0)
			$this->_close = $uri;

		return $this->_close;
	}

	/**
	 * @param string $uri
	 *
	 * @return string
	 */
	public function StreamUnknown ($uri = '') {
		if (func_num_args() != 0)
			$this->_unknown = $uri;

		return $this->_unknown;
	}

	/**
	 * @param QuarkURI|string $uri = ''
	 *
	 * @return QuarkURI
	 */
	public function ServerURI ($uri = '') {
		if (func_num_args() != 0)
			$this->_cluster->Server()->URI(QuarkURI::FromURI($uri));
		
		return $this->_cluster->Server()->URI();
	}

	/**
	 * @param QuarkURI|string $uri = ''
	 *
	 * @return QuarkURI
	 */
	public function NetworkURI ($uri = '') {
		if (func_num_args() != 0)
			$this->_cluster->Network()->URI(QuarkURI::FromURI($uri));

		return $this->_cluster->Network()->URI();
	}

	/**
	 * @param QuarkURI|string $uri = ''
	 *
	 * @return QuarkURI
	 */
	public function ControllerURI ($uri = '') {
		if (func_num_args() != 0)
			$this->_cluster->Controller()->URI(QuarkURI::FromURI($uri));

		return $this->_cluster->Controller()->URI();
	}
	
	/**
	 * @param QuarkCertificate $certificate = null
	 *
	 * @return QuarkCertificate
	 */
	public function ServerCertificate (QuarkCertificate $certificate = null) {
		if (func_num_args() != 0)
			$this->_cluster->Server()->Certificate($certificate);
		
		return $this->_cluster->Server()->Certificate();
	}
	
	/**
	 * @param QuarkCertificate $certificate = null
	 *
	 * @return QuarkCertificate
	 */
	public function NetworkCertificate (QuarkCertificate $certificate = null) {
		if (func_num_args() != 0)
			$this->_cluster->Network()->Certificate($certificate);
		
		return $this->_cluster->Network()->Certificate();
	}
	
	/**
	 * @param QuarkCertificate $certificate = null
	 *
	 * @return QuarkCertificate
	 */
	public function ControllerCertificate (QuarkCertificate $certificate = null) {
		if (func_num_args() != 0)
			$this->_cluster->Controller()->Certificate($certificate);
		
		return $this->_cluster->Controller()->Certificate();
	}

	/**
	 * @return bool
	 */
	public function EnvironmentMultiple () { return true; }

	/**
	 * @return string
	 */
	public function EnvironmentName () {
		return $this->_name;
	}

	/**
	 * @param object $ini
	 *
	 * @return void
	 */
	public function EnvironmentOptions ($ini) {
		if (isset($ini->External))
			$this->ServerURI($ini->External);

		if (isset($ini->Internal))
			$this->NetworkURI($ini->Internal);

		if (isset($ini->Controller))
			$this->ControllerURI($ini->Controller);
		
		if (isset($ini->Certificate)) {
			$certificate = new QuarkCertificate($ini->Certificate, isset($ini->CertificatePassphrase) ? $ini->CertificatePassphrase : '');
			
			$this->ServerCertificate($certificate);
			$this->NetworkCertificate($certificate);
			$this->ControllerCertificate($certificate);
		}
		
		if (isset($ini->CertificateExternal))
			$this->ServerCertificate(new QuarkCertificate($ini->CertificateExternal, isset($ini->CertificateExternalPassphrase) ? $ini->CertificateExternalPassphrase : ''));
		
		if (isset($ini->CertificateInternal))
			$this->ServerCertificate(new QuarkCertificate($ini->CertificateInternal, isset($ini->CertificateInternalPassphrase) ? $ini->CertificateInternalPassphrase : ''));
		
		if (isset($ini->CertificateController))
			$this->ServerCertificate(new QuarkCertificate($ini->CertificateController, isset($ini->CertificateControllerPassphrase) ? $ini->CertificateControllerPassphrase : ''));
		
		if (isset($ini->StreamConnect))
			$this->StreamConnect($ini->StreamConnect);
		
		if (isset($ini->StreamClose))
			$this->StreamClose($ini->StreamClose);
		
		if (isset($ini->StreamUnknown))
			$this->StreamUnknown($ini->StreamUnknown);
	}

	/**
	 * @return bool
	 */
	public function UsageCriteria () {
		return Quark::CLI() && $_SERVER['argc'] == 1;
	}

	/**
	 * @return mixed
	 */
	public function Thread () {
		if (!$this->_cluster) return true;

		Quark::CurrentEnvironment($this);

		return $this->_cluster->NodePipe();
	}

	/**
	 * @param \Exception $exception
	 *
	 * @return mixed
	 */
	public function ExceptionHandler (\Exception $exception) {
		return QuarkException::ExceptionHandler($exception);
	}

	/**
	 * @param string $url
	 * @param QuarkDTO|object|array $payload
	 *
	 * @return bool
	 */
	public function BroadcastNetwork ($url, $payload) {
		return $this->_cluster->Broadcast(self::Package(self::PACKAGE_REQUEST, $url, $payload, null, true));
	}

	/**
	 * @param string $url
	 * @param callable(QuarkSession $client) $sender = null
	 * @param bool $auth = true
	 * @param string|callable(QuarkClient $client) $filter = null
	 *
	 * @return bool
	 */
	public function BroadcastLocal ($url, callable &$sender = null, $auth = true, &$filter = null) {
		$ok = true;
		$clients = $this->_cluster->Server()->Clients();
		$filtered = func_num_args() == 4;

		foreach ($clients as $i => &$client) {
			if ($filtered) {
				if (is_string($filter) && !$client->Subscribed($filter)) continue;
				if (is_callable($filter) && !call_user_func_array($filter, array(&$client))) continue;
			}

			$session = QuarkSession::Get($client->Session());
			if ($auth && ($session == null || $session->User() == null)) continue;
			
			$data = $sender ? call_user_func_array($sender, array(&$session)) : null;

			if ($data !== null)
				$ok &= $client->Send(self::Package(self::PACKAGE_EVENT, $url, $data, $session));

			unset($data, $session);
		}

		unset($out, $session, $i, $client, $clients, $sender, $filter, $filtered);

		return $ok;
	}

	/**
	 * @return QuarkCluster
	 */
	public function &Cluster () {
		return $this->_cluster;
	}

	/**
	 * @param QuarkServer $server
	 * @param QuarkPeer $network
	 * @param QuarkClient $controller
	 *
	 * @return void
	 */
	public function NodeStart (QuarkServer $server, QuarkPeer $network, QuarkClient $controller) {
		$this->ControllerURI(Quark::Config()->ClusterControllerConnect());
	}

	/**
	 * @return IQuarkNetworkTransport
	 */
	public function &ClientTransport () {
		return $this->_transportClient;
	}

	/**
	 * @param QuarkClient $client
	 *
	 * @return void
	 */
	public function ClientConnect (QuarkClient $client) {
		echo '[cluster.node.client.connect] ', $client, ' -> ', $this->_cluster->Server(), "\r\n";

		$this->_announce();

		if ($this->_connect)
			$this->_pipe($this->_connect, 'StreamConnect', $client);
	}

	/**
	 * @param QuarkClient $client
	 * @param string $data
	 *
	 * @return void
	 *
	 * @throws QuarkArchException
	 */
	public function ClientData (QuarkClient $client, $data) {
		$this->_pipeData('Stream', $data, false, $client);
	}

	/**
	 * @param QuarkClient $client
	 *
	 * @return void
	 */
	public function ClientClose (QuarkClient $client) {
		echo '[cluster.node.client.close] ', $client, ' -> ', $this->_cluster->Server(), "\r\n";

		$this->_announce();

		if ($this->_close)
			$this->_pipe($this->_close, 'StreamClose', $client, null, $client->Session() ? $client->Session()->Extract() : null);
	}

	/**
	 * @return IQuarkNetworkTransport
	 */
	public function NetworkTransport () {
		return self::TCPProtocol();
	}

	/**
	 * @param QuarkClient $node
	 *
	 * @return void
	 */
	public function NetworkClientConnect (QuarkClient $node) {
		echo '[cluster.node.node.client.connect] ', $this->_cluster->Network()->Server(), ' <- ', $node, "\r\n";
	}

	/**
	 * @param QuarkClient $node
	 * @param string $data
	 *
	 * @return void
	 */
	public function NetworkClientData (QuarkClient $node, $data) {
		// TODO: Implement NetworkClientData() method.
	}

	/**
	 * @param QuarkClient $node
	 *
	 * @return void
	 */
	public function NetworkClientClose (QuarkClient $node) {
		echo '[cluster.node.node.client.close] ', $this->_cluster->Network()->Server(), ' <- ', $node, "\r\n";
	}

	/**
	 * @param QuarkClient $node
	 *
	 * @return void
	 */
	public function NetworkServerConnect (QuarkClient $node) {
		echo '[cluster.node.node.server.connect] ', $node, ' -> ', $this->_cluster->Network()->Server(), "\r\n";

		$this->_announce();
	}

	/**
	 * @param QuarkClient $node
	 * @param string $data
	 *
	 * @return void
	 *
	 * @throws QuarkArchException
	 */
	public function NetworkServerData (QuarkClient $node = null, $data) {
		$this->_pipeData('StreamNetwork', $data, $node !== null);
	}

	/**
	 * @param QuarkClient $node
	 *
	 * @return void
	 */
	public function NetworkServerClose (QuarkClient $node) {
		echo '[cluster.node.node.server.close] ', $node, ' -> ', $this->_cluster->Network()->Server(), "\r\n";

		$this->_announce();
	}

	/**
	 * @param QuarkServer $controller
	 * @param QuarkServer $terminal
	 *
	 * @return void
	 */
	public function ControllerStart (QuarkServer $controller, QuarkServer $terminal) {
		// TODO: Implement ControllerStart() method.
	}

	/**
	 * @return IQuarkNetworkTransport
	 */
	public function ControllerTransport () {
		return self::TCPProtocol();
	}

	/**
	 * @param QuarkClient $controller
	 *
	 * @return void
	 */
	public function ControllerClientConnect (QuarkClient $controller) {
		echo '[cluster.node.controller.connect] ', $this->_cluster->Controller(), ' <- ', $controller, "\r\n";

		$this->_announce();
	}

	/**
	 * @param QuarkClient $controller
	 * @param string $data
	 *
	 * @return void
	 */
	public function ControllerClientData (QuarkClient $controller, $data) {
		$this->_cmd($data, self::COMMAND_ANNOUNCE, function ($node) {
			if (!isset($node->internal) || !isset($node->external)) return;

			$this->_cluster->Network()->Peer($node->internal);
		});

		$this->_cmd($data, self::COMMAND_BROADCAST, function ($payload) {
			if (!isset($payload->url) || !isset($payload->data)) return;

			$this->_cluster->Broadcast(self::Package(self::PACKAGE_REQUEST, $payload->url, $payload->data, null, true));
		});
	}

	/**
	 * @param QuarkClient $controller
	 *
	 * @return void
	 */
	public function ControllerClientClose (QuarkClient $controller) {
		echo '[cluster.node.controller.close] ', $this->_cluster->Controller(), ' <- ', $controller, "\r\n";
	}

	/**
	 * @param QuarkClient $node
	 *
	 * @return void
	 */
	public function ControllerServerConnect (QuarkClient $node) {
		echo '[cluster.controller.node.connect] ', $node, ' -> ', $this->_cluster->Controller(), "\r\n";

		$this->_monitor();
	}

	/**
	 * @param QuarkClient $node
	 * @param string $data
	 *
	 * @return void
	 */
	public function ControllerServerData (QuarkClient $node, $data) {
		$this->_cmd($data, self::COMMAND_BROADCAST, function ($payload) {
			if (!isset($payload->url) || !isset($payload->data)) return;

			$this->_cluster->Broadcast(self::Package(self::PACKAGE_COMMAND, self::COMMAND_BROADCAST, $payload, null, true));
		});

		$this->_cmd($data, self::COMMAND_ANNOUNCE, function ($state, $signature) use (&$node) {
			if (!isset($state->uri->internal) || !isset($state->uri->external)) return;
			if (!isset($state->clients) || !is_array($state->clients)) return;
			if (!isset($state->peers) || !is_array($state->peers)) return;

			/**
			 * @var \stdClass $node
			 */
			$node->state = $state;
			$node->signature = $signature;

			$this->_monitor();
		});
	}

	/**
	 * @param QuarkClient $node
	 *
	 * @return void
	 */
	public function ControllerServerClose (QuarkClient $node) {
		echo '[cluster.controller.node.close] ', $node, ' -> ', $this->_cluster->Controller(), "\r\n";

		$this->_monitor();
	}

	/**
	 * @return IQuarkNetworkTransport
	 */
	public function &TerminalTransport () {
		return $this->_transportTerminal;
	}

	/**
	 * @param QuarkClient $terminal
	 *
	 * @return void
	 */
	public function TerminalConnect (QuarkClient $terminal) {
		echo '[cluster.controller.terminal.connect] ', $terminal, ' -> ', $this->_cluster->Terminal(), "\r\n";
	}

	/**
	 * @param QuarkClient $terminal
	 * @param string $data
	 *
	 * @return void
	 */
	public function TerminalData (QuarkClient $terminal, $data) {
		/** @noinspection PhpUnusedParameterInspection */
		$this->_cmd($data, self::COMMAND_AUTHORIZE, function ($client, $signature) use (&$terminal) {
			/**
			 * @var \stdClass|QuarkClient $terminal
			 */
			$terminal->signature = $signature;
			$terminal->Send(self::Package(
				self::PACKAGE_COMMAND,
				self::COMMAND_INFRASTRUCTURE,
				$this->_infrastructure(), null, true
			));
		});

		$this->_cmd($data, self::COMMAND_ENDPOINT, function () use (&$terminal) {
			$nodes = $this->_infrastructure();

			/**
			 * @var \stdClass $endpoint
			 */
			$endpoint = sizeof($nodes) != 0 ? $nodes[0] : null;

			$terminal->Send(self::Package(
				self::PACKAGE_COMMAND,
				self::COMMAND_ENDPOINT,
				$endpoint == null ? null : $endpoint->external, null, true
			));

			$terminal->Close();
		}, false);
	}

	/**
	 * @param QuarkClient $terminal
	 *
	 * @return void
	 */
	public function TerminalClose (QuarkClient $terminal) {
		echo '[cluster.controller.terminal.close] ', $terminal, ' -> ', $this->_cluster->Terminal(), "\r\n";
	}
}

/**
 * Class QuarkURI
 *
 * @package Quark
 */
class QuarkURI {
	const WRAPPER_TCP = 'tcp';
	const WRAPPER_UDP = 'udp';
	const WRAPPER_SSL = 'tls';
	const WRAPPER_TLS = 'tls';

	const SCHEME_HTTP = 'http';
	const SCHEME_HTTPS = 'https';

	const HOST_LOCALHOST = '127.0.0.1';
	const HOST_ALL_INTERFACES = '0.0.0.0';

	/**
	 * @var string $scheme
	 */
	public $scheme;

	/**
	 * @var string $user
	 */
	public $user;

	/**
	 * @var string $pass
	 */
	public $pass;

	/**
	 * @var string $host
	 */
	public $host;

	/**
	 * @var string|int $port
	 */
	public $port;

	/**
	 * @var string $query
	 */
	public $query;

	/**
	 * @var string $path
	 */
	public $path;

	/**
	 * @var string $fragment
	 */
	public $fragment;

	/**
	 * @var array $_route;
	 */
	private $_route = array();

	/**
	 * @var array $_transports
	 */
	private static $_transports = array(
		'tcp' => self::WRAPPER_TCP,
		'udp' => self::WRAPPER_UDP,
		'ssl' => self::WRAPPER_SSL,
		'tls' => self::WRAPPER_TLS,
		'ftp' => self::WRAPPER_TCP,
		'ftps' => self::WRAPPER_SSL,
		'ssh' => self::WRAPPER_SSL,
		'scp' => self::WRAPPER_SSL,
		'http' => self::WRAPPER_TCP,
		'https' => self::WRAPPER_SSL,
		'ws' => self::WRAPPER_TCP,
		'wss' => self::WRAPPER_SSL,
	);

	/**
	 * @var array $_ports
	 */
	private static $_ports = array(
		'ftp' => '21',
		'ftps' => '22',
		'ssh' => '22',
		'scp' => '22',
		'http' => '80',
		'https' => '443'
	);

	/**
	 * @return string
	 */
	public function __toString () {
		return $this->URI();
	}

	/**
	 * @param QuarkURI|string|null $uri = ''
	 * @param bool $local
	 *
	 * @return QuarkURI|null
	 */
	public static function FromURI ($uri = '', $local = true) {
		if ($uri == null) $uri = '';
		if ($uri instanceof QuarkURI) return $uri;
		if (!is_string($uri)) return null;

		$rand = false;

		$pass = Quark::GuID();
		$uri = str_replace(':0@', $pass, $uri);

		if (strstr($uri, ':0')) {
			$rand = true;
			$uri = str_replace(':0', '', $uri);
		}

		$uri = str_replace($pass, ':0@', $uri);

		$url = parse_url($uri);

		if ($url === false) return null;

		$out = new self();

		foreach ($url as $key => $value)
			$out->$key = $value;

		if ($rand)
			$out->port = 0;

		if ($local) {
			if (!isset($url['scheme'])) $out->scheme = isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : '';
			if (!isset($url['host'])) $out->host = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';
		}

		return $out;
	}

	/**
	 * @param string $host
	 * @param int $port
	 *
	 * @return QuarkURI
	 */
	public static function FromEndpoint ($host, $port = null) {
		$uri = new self();
		$uri->Endpoint($host, $port);
		return $uri;
	}

	/**
	 * @param string $location
	 * @param bool $endSlash = false
	 *
	 * @return QuarkURI
	 */
	public static function FromFile ($location = '', $endSlash = false) {
		$uri = new self();
		$uri->path = Quark::NormalizePath($location, $endSlash);
		return $uri;
	}

	/**
	 * @param string $scheme
	 */
	public function __construct ($scheme = '') {
		if (func_num_args() == 1)
			$this->scheme = (string)$scheme;
	}

	/**
	 * @param bool $full
	 *
	 * @return string
	 */
	public function URI ($full = false) {
		return $this->Hostname()
			. ($this->path !== null ? Quark::NormalizePath('/' . $this->path, false) : '')
			. ($full ? '/?' . $this->query : '');
	}

	/**
	 * @param bool $user = true
	 * 
	 * @return string
	 */
	public function Hostname ($user = true) {
		if (strpos(strtolower($this->scheme), strtolower('HTTP/')) !== false)
			$this->scheme = 'http';

		return
			($this->scheme !== null ? $this->scheme : 'http')
			. '://'
			. ($user && $this->user !== null ? $this->user . ($this->pass !== null ? ':' . $this->pass : '') . '@' : '')
			. $this->host
			. ($this->port !== null && $this->port != 80 ? ':' . $this->port : '');
	}

	/**
	 * @return string|bool
	 */
	public function Socket () {
		return (isset(self::$_transports[$this->scheme]) ? self::$_transports[$this->scheme] : 'tcp')
		. '://'
		. $this->host
		. ':'
		. (is_int($this->port) ? $this->port : (isset(self::$_ports[$this->scheme]) ? self::$_ports[$this->scheme] : 80));
	}
	
	/**
	 * @return QuarkURI|null
	 */
	public function SocketURI () {
		return self::FromURI($this->Socket());
	}

	/**
	 * @param string $host
	 * @param integer|null $port
	 *
	 * @return QuarkURI
	 */
	public function Endpoint ($host, $port = null) {
		$this->host = $host;

		if (func_num_args() == 2 || $port !== null)
			$this->port = $port;

		return $this;
	}

	/**
	 * @param string $username
	 * @param string|null $password
	 *
	 * @return QuarkURI
	 */
	public function User ($username, $password = null) {
		$this->user = $username;

		if (func_num_args() == 2)
			$this->pass = $password;

		return $this;
	}

	/**
	 * @param string $resource
	 *
	 * @return string
	 */
	public function Resource ($resource = '') {
		if (func_num_args() == 1)
			$this->path= $resource;

		return $this->path;
	}

	/**
	 * @return string
	 */
	public function Query () {
		return Quark::NormalizePath($this->path . (strlen(trim($this->query)) == 0 ? '' : '?' . $this->query) . $this->fragment, false);
	}

	/**
	 * @param array $query = []
	 *
	 * @return QuarkURI
	 */
	public function AppendQuery ($query = []) {
		$this->query .=
			(strlen($this->query) == 0 ? '' : '&') .
			(is_scalar($query) ? $query : http_build_query($query));

		return $this;
	}

	/**
	 * @param int $id
	 *
	 * @return array|string
	 */
	public function Route ($id = 0) {
		if (sizeof($this->_route) == 0)
			$this->_route = self::ParseRoute($this->path);

		if (func_num_args() == 1)
			return isset($this->_route[$id]) ? $this->_route[$id] : '';

		return $this->_route;
	}

	/**
	 * @param string $source
	 *
	 * @return array
	 */
	public static function ParseRoute ($source = '') {
		if (!is_string($source)) return array();

		$query = preg_replace('#(((\/)*)((\?|\&)(.*)))*#', '', $source);
		$route = explode('/', trim(Quark::NormalizePath(preg_replace('#\.php$#Uis', '', $query), false)));
		$buffer = array();

		foreach ($route as $component)
			if (strlen(trim($component)) != 0) $buffer[] = trim($component);

		$route = $buffer;
		unset($buffer);

		return $route;
	}

	/**
	 * @param string $uri = ''
	 * @param array $query = []
	 * @param bool $weak = false
	 *
	 * @return string
	 */
	public static function BuildQuery ($uri = '', $query = [], $weak = false) {
		$params = http_build_query($query);

		return $weak && strlen($params) == 0
			? ''
			: (strpos($uri, '?') !== false ? '&' : '?') . $params;
	}

	/**
	 * @param string $uri = ''
	 * @param array $query = []
	 * @param bool $weak = true
	 *
	 * @return string
	 */
	public static function Build ($uri = '', $query = [], $weak = true) {
		return $uri . self::BuildQuery($uri, $query, $weak);
	}

	/**
	 * @param $query = []
	 * 
	 * @return object
	 */
	public function Params ($query = []) {
		if (func_num_args() != 0)
			$this->query = http_build_query((array)$query);
		
		return QuarkObject::Merge($this->Options());
	}

	/**
	 * @param string $key = ''
	 *
	 * @return array|string|null
	 */
	public function Options ($key = '') {
		parse_str($this->query, $params);

		$params = is_array($params) ? $params : array();
		
		return func_num_args() == 0
			? $params
			: (isset($params[$key]) ? $params[$key] : null);
	}

	/**
	 * @param QuarkURI $uri
	 *
	 * @return bool
	 */
	public function Equal (QuarkURI $uri) {
		foreach ($this as $key => $value)
			if ($uri->$key != $value) return false;

		return true;
	}

	/**
	 * @return bool
	 */
	public function IsNull () {
		return !$this->host && $this->port === null;
	}

	/**
	 * @param string $host
	 *
	 * @return bool
	 */
	public function IsHost ($host = '') {
		return $this->host == $host;
	}

	/**
	 * @return bool
	 */
	public function IsHostLocal () {
		return $this->host == self::HOST_LOCALHOST || $this->host == Quark::HostIP();
	}

	/**
	 * Formats of `$network`:
	 *  - CIDR  192.168.0.0/24
	 *  - CIDR  192.168.0.0/255.255.255.0
	 *  - Range 192.168.1.0-192.168.1.254
	 *
	 * https://pgregg.com/blog/2009/04/php-algorithms-determining-if-an-ip-is-within-a-specific-range/
	 * http://mycrimea.su/partners/web/access/ipsearch.php
	 *
	 * @param string $ip
	 * @param string $network
	 *
	 * @return bool
	 */
	public static function IsHostFromNetwork ($ip = '', $network = '') {
		$ip = ip2long($ip);

		if ($ip === false) return false;

		if (strstr($network, '/')) {
			$net = explode('/', $network);

			if (sizeof($net) < 2) return false;

			$network = ip2long($net[0]);
			$mask = ip2long(strpos($net[1], '.') !== false
				? Quark::IP(str_replace('*', '0', $net[1]))
				: Quark::CIDR($net[1])
			);

			return (($ip & $mask) == $network);
		}

		if (strstr($network, '-')) {
			$net = explode('-', $network);

			if (sizeof($net) < 2) return false;

			$min = ip2long(Quark::IP(str_replace('*', '0', $net[0])));
			$max = ip2long(Quark::IP(str_replace('*', '0', $net[1])));

			return $ip >= $min && $ip <= $max;
		}

		return false;
	}

	/**
	 * Info provided by http://ipinfo.io Free plan limit 1000 daily requests
	 *
	 * @param string $state
	 * @param bool $allowLocalhost = true
	 *
	 * @return bool
	 */
	public function IsHostState ($state = '', $allowLocalhost = true) {
		$ip = Quark::IPInfo($this->host);

		if (!isset($ip->country)) {
			if ($allowLocalhost && $this->IsHostLocal()) return true;
			else return false;
		}

		return $ip->country == $state;
	}

	/**
	 * @param string $host = ''
	 *
	 * @return QuarkURI
	 */
	public function ConnectionURI ($host = '') {
		$uri = clone $this;
			$uri->host = func_num_args() != 0
				? $host
				: ($uri->host == self::HOST_ALL_INTERFACES
					? Quark::HostIP()
					: $uri->host
				);

		return $uri;
	}
	
	/**
	 * @return bool
	 */
	public function Secure () {
		return isset(self::$_transports[$this->scheme])
			&& (
				self::$_transports[$this->scheme] == self::WRAPPER_SSL ||
				self::$_transports[$this->scheme] == self::WRAPPER_TLS
			);
	}
}

/**
 * Class QuarkDTO
 *
 * @package Quark
 */
class QuarkDTO {
	const HTTP_VERSION_1_0 = 'HTTP/1.0';
	const HTTP_VERSION_1_1 = 'HTTP/1.1';
	const HTTP_PROTOCOL_REQUEST = '#^(.*) (.*) (.*)\n(.*)\n\s\n(.*)$#Uis';
	const HTTP_PROTOCOL_RESPONSE = '#^(.*) (.*)\n(.*)\n\s\n(.*)$#Uis';

	const METHOD_GET = 'GET';
	const METHOD_POST = 'POST';
	const METHOD_PUT = 'PUT';
	const METHOD_PATCH = 'PATCH';
	const METHOD_DELETE = 'DELETE';

	const HEADER_HOST = 'Host';
	const HEADER_ACCEPT = 'Accept';
	const HEADER_ACCEPT_LANGUAGE = 'Accept-Language';
	const HEADER_ACCEPT_ENCODING = 'Accept-Encoding';
	const HEADER_ACCEPT_RANGES = 'Accept-Ranges';
	const HEADER_CACHE_CONTROL = 'Cache-Control';
	const HEADER_CONTENT_LENGTH = 'Content-Length';
	const HEADER_CONTENT_TYPE = 'Content-Type';
	const HEADER_CONTENT_TRANSFER_ENCODING = 'Content-Transfer-Encoding';
	const HEADER_CONTENT_DISPOSITION = 'Content-Disposition';
	const HEADER_CONTENT_DESCRIPTION = 'Content-Description';
	const HEADER_CONTENT_LANGUAGE = 'Content-Language';
	const HEADER_COOKIE = 'Cookie';
	const HEADER_CONNECTION = 'Connection';
	const HEADER_ETAG = 'ETag';
	const HEADER_SET_COOKIE = 'Set-Cookie';
	const HEADER_ALLOW_ORIGIN = 'Access-Control-Allow-Origin';
	const HEADER_AUTHORIZATION = 'Authorization';
	const HEADER_EXPIRES = 'Expires';
	const HEADER_PRAGMA = 'Pragma';
	const HEADER_UPGRADE = 'Upgrade';
	const HEADER_SEC_WEBSOCKET_KEY = 'Sec-WebSocket-Key';
	const HEADER_SEC_WEBSOCKET_EXTENSIONS = 'Sec-WebSocket-Extensions';
	const HEADER_SEC_WEBSOCKET_ACCEPT = 'Sec-WebSocket-Accept';
	const HEADER_SEC_WEBSOCKET_PROTOCOL = 'Sec-WebSocket-Protocol';
	const HEADER_LOCATION = 'Location';
	const HEADER_USER_AGENT = 'User-Agent';
	const HEADER_KEEP_ALIVE = 'Keep-Alive';
	const HEADER_LAST_MODIFIED = 'Last-Modified';
	const HEADER_SERVER = 'Server';
	const HEADER_DATE = 'Date';
	const HEADER_WWW_AUTHENTICATE = 'WWW-Authenticate';

	const STATUS_200_OK = '200 OK';
	const STATUS_302_FOUND = '302 Found';
	const STATUS_401_UNAUTHORIZED = '401 Unauthorized';
	const STATUS_403_FORBIDDEN = '403 Forbidden';
	const STATUS_404_NOT_FOUND = '404 Not Found';
	const STATUS_500_SERVER_ERROR = '500 Server Error';

	const CONNECTION_KEEP_ALIVE = 'keep-alive';
	const CONNECTION_UPGRADE = 'Upgrade';

	const UPGRADE_WEBSOCKET = 'websocket';

	const DISPOSITION_INLINE = 'inline';
	const DISPOSITION_FORM_DATA = 'form-data';
	const DISPOSITION_ATTACHMENT = 'attachment';

	const MULTIPART_FORM_DATA = 'multipart/form-data';
	const MULTIPART_MIXED = 'multipart/mixed';
	const MULTIPART_ALTERNATIVE = 'multipart/alternative';
	const MULTIPART_RELATED = 'multipart/related';

	const TRANSFER_ENCODING_BINARY = 'binary';
	const TRANSFER_ENCODING_BASE64 = 'base64';

	const CHARSET_UTF8 = 'utf-8';

	const RANGES_BYTES = 'bytes';

	const AUTHORIZATION_BASIC = 'Basic';
	const AUTHORIZATION_DIGEST = 'Digest';
	const AUTHORIZATION_BEARER = 'Bearer';

	const KEY_AUTHORIZATION = '_a';
	const KEY_SIGNATURE = '_s';

	const RESPONSE_BUFFER = 4096;

	/**
	 * @var string $_raw = ''
	 */
	private $_raw = '';

	/**
	 * @var string $_rawData = ''
	 */
	private $_rawData = '';

	/**
	 * @var IQuarkIOProcessor $_processor = null
	 */
	private $_processor = null;

	/**
	 * @var string $_protocol = self::HTTP_VERSION_1_0
	 */
	private $_protocol = self::HTTP_VERSION_1_0;

	/**
	 * @var QuarkURI $_uri = null
	 */
	private $_uri = null;

	/**
	 * @var QuarkURI $_remote = null
	 */
	private $_remote = null;

	/**
	 * @var string $_status = self::STATUS_200_OK
	 */
	private $_status = self::STATUS_200_OK;

	/**
	 * @var string $_method = ''
	 */
	private $_method = '';

	/**
	 * @var array $_headers = []
	 */
	private $_headers = array();

	/**
	 * @var QuarkCookie[] $_cookies = []
	 */
	private $_cookies = array();

	/**
	 * @var QuarkLanguage[] $_languages = []
	 */
	private $_languages = array();

	/**
	 * @var QuarkMIMEType[] $_types = []
	 */
	private $_types = array();

	/**
	 * @var string $_agent = ''
	 */
	private $_agent = '';

	/**
	 * @var string $_boundary = ''
	 */
	private $_boundary = '';

	/**
	 * @var string $_encoding
	 */
	private $_encoding = self::TRANSFER_ENCODING_BINARY;

	/**
	 * @var bool $_multipart = false
	 */
	private $_multipart = false;

	/**
	 * @var int|string $_length = 0
	 */
	private $_length = 0;

	/**
	 * @var string $_charset = self:: CHARSET_UTF8
	 */
	private $_charset = self:: CHARSET_UTF8;

	/**
	 * @var mixed $_data = ''
	 */
	private $_data = '';

	/**
	 * @var QuarkFile[] $_files = []
	 */
	private $_files = array();

	/**
	 * @var QuarkKeyValuePair $_authorization = null
	 */
	private $_authorization = null;

	/**
	 * @var QuarkKeyValuePair $_session = null
	 */
	private $_session = null;

	/**
	 * @var string $_signature = ''
	 */
	private $_signature = '';

	/**
	 * @var bool $_fullControl = false
	 */
	private $_fullControl = false;

	/**
	 * @var bool $_authorizationPrompt = false
	 */
	private $_authorizationPrompt = false;

	/**
	 * @var null $_null = null
	 */
	private $_null = null;

	/**
	 * @param $key
	 *
	 * @return mixed
	 */
	public function &__get ($key) {
		if (is_scalar($this->_data) || !$this->_data)
			return $this->_null;

		if (is_array($this->_data))
			$this->_data = (object)$this->_data;

		return $this->_data->$key;
	}

	/**
	 * @param $key
	 * @param $value
	 */
	public function __set ($key, $value) {
		if (!$this->_data)
			$this->_data = new \stdClass();

		$this->_data->$key = $value;
	}

	/**
	 * @param $key
	 *
	 * @return bool
	 */
	public function __isset ($key) {
		return isset($this->_data->$key);
	}

	/**
	 * @param $name
	 */
	public function __unset ($name) {
		unset($this->_data->$name);
	}

	/**
	 * @param IQuarkIOProcessor $processor
	 * @param QuarkURI  $uri
	 * @param string $method
	 * @param string $boundary
	 */
	public function __construct (IQuarkIOProcessor $processor = null, QuarkURI $uri = null, $method = '', $boundary = '') {
		$this->Processor($processor == null ? new QuarkHTMLIOProcessor() : $processor);
		$this->URI($uri);
		$this->Method($method);
		$this->Boundary(func_num_args() == 4 ? $boundary : 'QuarkBoundary' . Quark::GuID());
	}

	/**
	 * @param IQuarkIOProcessor $processor
	 * @param QuarkURI          $uri
	 *
	 * @return QuarkDTO
	 */
	public static function ForGET (IQuarkIOProcessor $processor = null, QuarkURI $uri = null) {
		return new self($processor, $uri, self::METHOD_GET);
	}

	/**
	 * @param IQuarkIOProcessor $processor
	 * @param QuarkURI          $uri
	 *
	 * @return QuarkDTO
	 */
	public static function ForPOST (IQuarkIOProcessor $processor = null, QuarkURI $uri = null) {
		return new self($processor, $uri, self::METHOD_POST);
	}

	/**
	 * @param string $method = self::METHOD_GET
	 * @param IQuarkIOProcessor $processor = null
	 * @param mixed $data = []
	 *
	 * @return QuarkDTO
	 */
	public static function ForRequest ($method = self::METHOD_GET, IQuarkIOProcessor $processor = null, $data = []) {
		$dto = new self($processor, null, $method);
		$dto->Data($data);

		return $dto;
	}

	/**
	 * @param IQuarkIOProcessor $processor = null
	 *
	 * @return QuarkDTO
	 */
	public static function ForResponse (IQuarkIOProcessor $processor = null) {
		return new self($processor);
	}

	/**
	 * @param $url
	 *
	 * @return QuarkDTO
	 */
	public static function ForRedirect ($url) {
		$response = new self();
		$response->Status(self::STATUS_302_FOUND);
		$response->Header(self::HEADER_LOCATION, $url);
		return $response;
	}

	/**
	 * @param string $status
	 *
	 * @return QuarkDTO
	 */
	public static function ForStatus ($status) {
		$response = new self();
		$response->Status($status);
		return $response;
	}

	/**
	 * @param string $authenticate = ''
	 *
	 * @return QuarkDTO
	 */
	public static function ForHTTPAuthorizationPrompt ($authenticate = '') {
		$response = self::ForStatus(self::STATUS_401_UNAUTHORIZED);
		$response->AuthorizationPrompt(true);

		if (func_num_args() != 0)
			$response->Header(self::HEADER_WWW_AUTHENTICATE, $authenticate);

		return $response;
	}

	/**
	 * @param string $username
	 * @param string $password
	 *
	 * @return string
	 */
	public static function HTTPBasicAuthorization ($username = '', $password = '') {
		return base64_encode($username . ':' . $password);
	}

	/**
	 * @param mixed $data
	 * @param bool $processor = true
	 * @param bool $status = true
	 *
	 * @return QuarkDTO
	 */
	public function Merge ($data = [], $processor = true, $status = true) {
		if (!($data instanceof QuarkDTO)) $this->MergeData($data);
		else {
			$this->_method = $data->Method();
			$this->_boundary = $data->Boundary();
			$this->_headers += $data->Headers();
			$this->_cookies += $data->Cookies();
			$this->_languages += $data->Languages();
			$this->_types += $data->Types();
			$this->_uri = $data->URI() == null ? $this->_uri : $data->URI();
			$this->_remote = $data->Remote() == null ? $this->_remote : $data->Remote();
			$this->_charset = $data->Charset();

			if ($status)
				$this->_status = $data->Status();

			if ($processor)
				$this->_processor = $data->Processor();

			$this->MergeData($data->Data());
		}

		$auth = self::KEY_AUTHORIZATION;
		$sign = self::KEY_SIGNATURE;

		if (isset($this->_data->$auth))
			$this->AuthorizationProvider(QuarkKeyValuePair::FromField($this->_data->$auth));

		if (isset($this->_data->$sign))
			$this->Signature($this->_data->$sign);

		return $this;
	}

	/**
	 * @param $data
	 *
	 * @return mixed
	 */
	public function MergeData ($data) {
		if ($this->_data instanceof QuarkView || $data === null) return $this->_data;

		if (is_string($data) && is_string($this->_data)) $this->_data .= $data;
		elseif($data instanceof QuarkView) $this->_data = $data;
		else $this->_data = QuarkObject::Merge($this->_data, $data);

		return $this->_data;
	}

	/**
	 * @param string $protocol
	 *
	 * @return string
	 */
	public function Protocol ($protocol = '') {
		if (func_num_args() != 0)
			$this->_protocol = $protocol;

		return $this->_protocol;
	}

	/**
	 * @param IQuarkIOProcessor $processor
	 *
	 * @return IQuarkIOProcessor
	 */
	public function Processor (IQuarkIOProcessor $processor = null) {
		if (func_num_args() == 1 && $processor != null)
			$this->_processor = $processor;

		return $this->_processor;
	}

	/**
	 * @param QuarkURI $uri
	 *
	 * @return QuarkURI
	 */
	public function URI (QuarkURI $uri = null) {
		if (func_num_args() == 1 && $uri != null)
			$this->_uri = $uri;

		return $this->_uri;
	}

	/**
	 * @param QuarkURI $uri
	 *
	 * @return QuarkURI
	 */
	public function Remote (QuarkURI $uri = null) {
		if (func_num_args() == 1 && $uri != null)
			$this->_remote = $uri;

		return $this->_remote;
	}

	/**
	 * @param string $method = ''
	 *
	 * @return string
	 */
	public function Method ($method = '') {
		if (func_num_args() == 1 && is_string($method))
			$this->_method = strtoupper(trim($method));

		return $this->_method;
	}

	/**
	 * @param int|string $code = 0
	 * @param string $text = 'OK'
	 *
	 * @return string
	 */
	public function Status ($code = 0, $text = 'OK') {
		if (func_num_args() != 0 && is_scalar($code))
			$this->_status = trim($code . (func_num_args() == 2 && is_scalar($text) ? ' ' . $text : ''));

		return $this->_status;
	}

	/**
	 * @param array $headers = []
	 *
	 * @return array
	 */
	public function Headers ($headers = []) {
		if (func_num_args() == 1 && is_array($headers)) {
			$assoc = QuarkObject::isAssociative($headers);

			foreach ($headers as $key => $value) {
				if (!$assoc) {
					$header = explode(': ', $value);
					$key = $header[0];
					$value = isset($header[1]) ? $header[1] : '';
				}

				$this->Header($key, $value);
			}
		}

		return $this->_headers;
	}

	/**
	 * @param $key
	 * @param $value = ''
	 *
	 * @return mixed
	 */
	public function Header ($key, $value = '') {
		$value = trim($value);

		if (func_num_args() == 2)
			$this->_headers[$key] = $value;

		switch ($key) {
			case self::HEADER_AUTHORIZATION:
				if (preg_match('#^(.*) (.*)$#Uis', $value, $auth))
					$this->_authorization = new QuarkKeyValuePair($auth[1], $auth[2]);
				break;

			case self::HEADER_COOKIE:
				$this->_cookies = QuarkCookie::FromCookie($value);
				break;

			case self::HEADER_SET_COOKIE:
				$this->_cookies[] = QuarkCookie::FromSetCookie($value);
				break;

			case self::HEADER_ACCEPT_LANGUAGE:
				$this->_languages = QuarkLanguage::FromAcceptLanguage($value);
				break;

			case self::HEADER_CONTENT_LANGUAGE:
				$this->_languages = QuarkLanguage::FromContentLanguage($value);
				break;

			case self::HEADER_CONTENT_LENGTH:
				$this->_length = $value;
				break;

			case self::HEADER_CONTENT_TYPE:
				$type = explode('; charset=', $value);
				$boundary = explode('; boundary=', $value);

				if (sizeof($type) == 2)
					$this->_charset = $type[1];

				if (sizeof($boundary) == 2)
					$this->_boundary = $boundary[1];

				$this->_multipart = strpos($type[0], 'multipart/') !== false;

				if (sizeof($this->_types) == 0)
					$this->_types = QuarkMIMEType::FromHeader($value);
				break;

			case self::HEADER_ACCEPT:
				$this->_types = QuarkMIMEType::FromHeader($value);
				break;

			default: break;
		}

		return isset($this->_headers[$key]) ? $this->_headers[$key] : null;
	}

	/**
	 * @param QuarkCookie[] $cookies = []
	 *
	 * @return QuarkCookie[]
	 */
	public function Cookies ($cookies = []) {
		if (func_num_args() == 1 && is_array($cookies))
			$this->_cookies = $cookies;

		return $this->_cookies;
	}

	/**
	 * @param QuarkCookie $cookie
	 *
	 * @return QuarkDTO
	 */
	public function Cookie (QuarkCookie $cookie) {
		$this->_cookies[] = $cookie;

		return $this;
	}

	/**
	 * @param string $name = ''
	 *
	 * @return QuarkCookie
	 */
	public function GetCookieByName ($name = '') {
		foreach ($this->_cookies as $cookie)
			if ($cookie->name == $name) return $cookie;

		return null;
	}

	/**
	 * @param QuarkLanguage[] $languages = []
	 *
	 * @return QuarkLanguage[]
	 */
	public function Languages ($languages = []) {
		if (func_num_args() != 0)
			$this->_languages = $languages;

		return $this->_languages;
	}

	/**
	 * @param QuarkLanguage $language
	 *
	 * @return QuarkDTO
	 */
	public function Language (QuarkLanguage $language) {
		$this->_languages[] = $language;

		return $this;
	}

	/**
	 * @param string $name = QuarkLanguage::ANY
	 * @param bool $strict = false
	 *
	 * @return QuarkLanguage
	 */
	public function GetLanguageByName ($name = QuarkLanguage::ANY, $strict = false) {
		foreach ($this->_languages as $language)
			if ($name == QuarkLanguage::ANY || $language->Is($name, $strict)) return $language;

		return null;
	}

	/**
	 * @param int $quantity = 1
	 *
	 * @return QuarkLanguage
	 */
	public function GetLanguageByQuantity ($quantity = 1) {
		foreach ($this->_languages as $language)
			if ($language->Quantity() == $quantity) return $language;
		
		return null;
	}

	/**
	 * @param int $quantity = 1
	 * @param bool $strict = false
	 *
	 * @return string
	 */
	public function ExpectedLanguage ($quantity = 1, $strict = false) {
		$language = $this->GetLanguageByQuantity($quantity);
		$out = $language == null ? QuarkLanguage::ANY : $language->Name();

		if (!$strict && $out != QuarkLanguage::ANY && !strpos($out, '-'))
			$out .= '-' . strtoupper($out);

		return $out;
	}

	/**
	 * @param string $name = QuarkLanguage::ANY
	 * @param bool $strict = false
	 *
	 * @return bool
	 */
	public function AcceptLanguage ($name = QuarkLanguage::ANY, $strict = false) {
		return $this->GetLanguageByName($name, $strict) != null;
	}

	/**
	 * @param QuarkMIMEType[] $types = []
	 *
	 * @return QuarkMIMEType[]
	 */
	public function Types ($types = []) {
		if (func_num_args() != 0)
			$this->_types = $types;
		
		return $this->_types;
	}

	/**
	 * @param QuarkMIMEType $type
	 *
	 * @return QuarkDTO
	 */
	public function Type (QuarkMIMEType $type) {
		$this->_types[] = $type;

		return $this;
	}

	/**
	 * @param string $name = QuarkMIMEType::ANY
	 * @param bool $strict = false
	 *
	 * @return QuarkMIMEType
	 */
	public function GetTypeByName ($name = QuarkMIMEType::ANY, $strict = false) {
		foreach ($this->_types as $type)
			if ($name == QuarkMIMEType::ANY || $type->Is($name, $strict)) return $type;

		return null;
	}

	/**
	 * @param int $quantity = 1
	 *
	 * @return QuarkMIMEType
	 */
	public function GetTypeByQuantity ($quantity = 1) {
		foreach ($this->_types as $type)
			if ($type->Quantity() == $quantity) return $type;

		return null;
	}

	/**
	 * @param int $quantity = 1
	 *
	 * @return string
	 */
	public function ExpectedType ($quantity = 1) {
		$type = $this->GetTypeByQuantity($quantity);

		return $type == null ? QuarkMIMEType::ANY : $type->Name();
	}

	/**
	 * @param string $type = QuarkMIMEType::ANY
	 * @param bool $strict = false
	 *
	 * @return bool
	 */
	public function AcceptType ($type = QuarkMIMEType::ANY, $strict = false) {
		return $this->GetTypeByName($type, $strict) != null;
	}

	/**
	 * @param string $agent
	 *
	 * @return string
	 */
	public function UserAgent ($agent = '') {
		if (func_num_args() != 0)
			$this->_agent = $agent;

		return $this->_agent;
	}

	/**
	 * @param string $boundary
	 *
	 * @return string
	 */
	public function Boundary ($boundary = '') {
		if (func_num_args() == 1 && is_scalar($boundary))
			$this->_boundary = (string)$boundary;

		return $this->_boundary;
	}

	/**
	 * @param mixed $data
	 *
	 * @return mixed
	 */
	public function Data ($data = []) {
		if (func_num_args() != 0) {
			$this->_data = $data;

			$sign = self::KEY_SIGNATURE;

			if (isset($this->_data->$sign))
				$this->Signature($this->_data->$sign);
		}

		return $this->_data;
	}

	/**
	 * @return QuarkFile[]
	 */
	public function Files () {
		return $this->_files;
	}

	/**
	 * @param string $raw
	 *
	 * @return string
	 */
	public function Raw ($raw = '') {
		if (func_num_args() != 0)
			$this->_raw = $raw;

		return $this->_raw;
	}

	/**
	 * @param string $raw
	 *
	 * @return string
	 */
	public function RawData ($raw = '') {
		if (func_num_args() != 0)
			$this->_rawData = $raw;

		return $this->_rawData;
	}

	/**
	 * @param QuarkKeyValuePair $auth
	 *
	 * @return QuarkKeyValuePair
	 */
	public function Authorization (QuarkKeyValuePair $auth = null) {
		if (func_num_args() != 0)
			$this->_authorization = $auth;

		return $this->_authorization;
	}

	/**
	 * @param QuarkKeyValuePair $session
	 *
	 * @return QuarkKeyValuePair
	 */
	public function AuthorizationProvider (QuarkKeyValuePair $session = null) {
		if (func_num_args() != 0)
			$this->_session = $session;

		return $this->_session;
	}

	/**
	 * @param string $signature
	 *
	 * @return string
	 */
	public function Signature ($signature = '') {
		if (func_num_args() != 0)
			$this->_signature = $signature;

		return $this->_signature;
	}

	/**
	 * @param string $encoding = self::TRANSFER_ENCODING_BINARY
	 *
	 * @return string
	 */
	public function Encoding ($encoding = self::TRANSFER_ENCODING_BINARY) {
		if (func_num_args() != 0)
			$this->_encoding = $encoding;

		return $this->_encoding;
	}

	/**
	 * @param string $charset = self::CHARSET_UTF8
	 *
	 * @return string
	 */
	public function Charset ($charset = self::CHARSET_UTF8) {
		if (func_num_args() != 0)
			$this->_charset = $charset;

		return $this->_charset;
	}

	/**
	 * @param bool $prompt = false
	 *
	 * @return bool
	 */
	public function AuthorizationPrompt ($prompt = false) {
		if (func_num_args() != 0)
			$this->_authorizationPrompt = $prompt;

		return $this->_authorizationPrompt;
	}

	/**
	 * @param bool $fullControl = false
	 *
	 * @return bool
	 */
	public function FullControl ($fullControl = false) {
		if (func_num_args() != 0)
			$this->_fullControl = $fullControl;

		return $this->_fullControl;
	}

	/**
	 * @return string
	 */
	public function SerializeRequest () {
		return $this->_serializeHeaders(true, true) . "\r\n\r\n" . $this->_serializeBody(true);
	}

	/**
	 * @return string
	 */
	public function SerializeRequestBody () {
		return $this->_serializeBody(true);
	}

	/**
	 * @return string
	 */
	public function SerializeRequestHeaders () {
		return $this->_serializeHeaders(true, true);
	}

	/**
	 * @return array
	 */
	public function SerializeRequestHeadersToArray () {
		return $this->_serializeHeaders(true, false);
	}

	/**
	 * @return string
	 */
	public function SerializeResponse () {
		return $this->_serializeHeaders(false, true) . "\r\n\r\n" . $this->_serializeBody(false);
	}

	/**
	 * @return string
	 */
	public function SerializeResponseBody () {
		return $this->_serializeBody(false);
	}

	/**
	 * @return string
	 */
	public function SerializeResponseHeaders () {
		return $this->_serializeHeaders(false, true);
	}

	/**
	 * @return array
	 */
	public function SerializeResponseHeadersToArray () {
		return $this->_serializeHeaders(false, false);
	}

	/**
	 * @param string $raw
	 *
	 * @return QuarkDTO
	 */
	public function UnserializeRequest ($raw = '') {
		$this->_raw = $raw;

		if (preg_match(self::HTTP_PROTOCOL_REQUEST, $raw, $found)) {
			$this->Method($found[1]);
			$this->URI(QuarkURI::FromURI($found[2]));
			$this->Protocol($found[3]);

			parse_str($this->URI()->query, $this->_data);

			$this->_data = (object)$this->_data;

			$auth = self::KEY_AUTHORIZATION;
			$sign = self::KEY_SIGNATURE;

			// get keys from GET params
			$this->AuthorizationProvider(isset($this->_data->$auth) ? QuarkKeyValuePair::FromField($this->_data->$auth) : null);
			$this->Signature(isset($this->_data->$sign) ? $this->_data->$sign : '');

			if ($this->_processor == null)
				$this->_processor = new QuarkFormIOProcessor();

			$this->_unserializeHeaders($found[4]);
			$this->_unserializeBody($found[5]);

			// re-fill keys, if they are transported in body
			$this->AuthorizationProvider(isset($this->_data->$auth) ? QuarkKeyValuePair::FromField($this->_data->$auth) : $this->AuthorizationProvider());
			$this->Signature(isset($this->_data->$sign) ? $this->_data->$sign : $this->Signature());

			$this->_rawData = $found[5];
		}

		return $this;
	}

	/**
	 * @param string $raw
	 *
	 * @return QuarkDTO
	 */
	public function UnserializeRequestBody ($raw = '') {
		return $this->_unserializeBody($raw);
	}

	/**
	 * @param string $raw
	 *
	 * @return QuarkDTO
	 */
	public function UnserializeRequestHeaders ($raw = '') {
		return $this->_unserializeHeaders($raw);
	}

	/**
	 * @param string $raw
	 *
	 * @return QuarkDTO
	 */
	public function UnserializeResponse ($raw = '') {
		$this->_raw = $raw;

		if (preg_match(self::HTTP_PROTOCOL_RESPONSE, substr($raw, 0, self::RESPONSE_BUFFER), $found)) {
			$this->_rawData = $found[4] != '' ? substr($raw, strpos($raw, $found[4])) : '';

			$this->Protocol($found[1]);
			$this->Status($found[2]);

			if ($this->_processor == null)
				$this->_processor = new QuarkHTMLIOProcessor();

			$this->_unserializeHeaders($found[3]);
			$this->_unserializeBody($this->_rawData);
		}

		return $this;
	}

	/**
	 * @param string $raw
	 *
	 * @return QuarkDTO
	 */
	public function UnserializeResponseBody ($raw = '') {
		return $this->_unserializeBody($raw);
	}

	/**
	 * @param string $raw
	 *
	 * @return string
	 */
	public function UnserializeResponseHeaders ($raw = '') {
		return $this->_unserializeHeaders($raw);
	}

	/**
	 * @param bool $client
	 * @param bool $str
	 *
	 * @return string|array
	 */
	private function _serializeHeaders ($client, $str) {
		if ($client && $this->_uri == null) return $str ? '' : array();

		$this->_serializeBody($client);

		$headers = array($client
			? $this->_method . ' ' . $this->_uri->Query() . ' ' . $this->_protocol
			: $this->_protocol . ' ' . $this->_status
		);

		$typeSet = isset($this->_headers[self::HEADER_CONTENT_TYPE]);
		$typeValue = $typeSet ? $this->_headers[self::HEADER_CONTENT_TYPE] : '';

		if (!isset($this->_headers[self::HEADER_AUTHORIZATION]) && $this->_authorization != null)
			$this->_headers[self::HEADER_AUTHORIZATION] = $this->_authorization->Key() . ' ' . $this->_authorization->Value();

		if (!$this->_fullControl) {
			if (!isset($this->_headers[self::HEADER_CONTENT_LENGTH]))
				$this->_headers[self::HEADER_CONTENT_LENGTH] = $this->_length;

			$this->_headers[self::HEADER_CONTENT_TYPE] = $typeSet
				? $typeValue
				: ($this->_multipart
					? ($client ? self::MULTIPART_FORM_DATA : self::MULTIPART_MIXED) . '; boundary=' . $this->_boundary
					: $this->_processor->MimeType() . '; charset=' . $this->_charset
				);
		}

		if ($client) {
			$this->_headers[self::HEADER_HOST] = $this->_uri->host;

			if (sizeof($this->_cookies) != 0)
				$this->_headers[self::HEADER_COOKIE] = QuarkCookie::SerializeCookies($this->_cookies);

			if (sizeof($this->_languages) != 0)
				$this->_headers[self::HEADER_ACCEPT_LANGUAGE] = QuarkLanguage::SerializeAcceptLanguage($this->_languages);
		}
		else {
			foreach ($this->_cookies as $cookie)
				$headers[] = self::HEADER_SET_COOKIE . ': ' . $cookie->Serialize(true);

			if (sizeof($this->_languages) != 0)
				$this->_headers[self::HEADER_CONTENT_LANGUAGE] = QuarkLanguage::SerializeContentLanguage($this->_languages);
		}

		foreach ($this->_headers as $key => $value)
			$headers[] = $key . ': ' . $value;

		return $str ? implode("\r\n", $headers) : $headers;
	}

	/**
	 * @param bool $client
	 *
	 * @return string
	 */
	private function _serializeBody ($client) {
		if ($this->_raw == '') {
			if ($this->_data instanceof QuarkView) {
				$this->_processor = new QuarkHTMLIOProcessor();
				$out = $this->_data->Compile();
			}
			elseif ($this->_data instanceof QuarkFile) {
				$this->Header(QuarkDTO::HEADER_CONTENT_TYPE, $this->_data->type);
				$out = $this->_data->Content();
			}
			else {
				$out = '';

				QuarkObject::Walk($this->_data, function ($key, $value) use (&$out, $client) {
					$this->_multipart |= $value instanceof QuarkFile;

					if ($this->_processor instanceof QuarkFormIOProcessor || ($value instanceof QuarkFile))
						$out .= $this->_serializePart($key, $value, $client
							? self::DISPOSITION_FORM_DATA
							: ($this->_multipart
								? self::DISPOSITION_ATTACHMENT
								: self::DISPOSITION_INLINE
							)
						);
				});

				if (!$this->_multipart) $out = $this->_processor->Encode($this->_data);
				else {
					if (!($this->_processor instanceof QuarkFormIOProcessor))
						$out = $this->_serializePart(
								$this->_processor->MimeType(),
								$this->_processor->Encode($this->_data),
								$client ? self::DISPOSITION_FORM_DATA : self::DISPOSITION_INLINE
							) . $out;

					$out = $out . '--' . $this->_boundary . '--';
				}
			}

			if (!$this->_multipart && $this->_encoding == self::TRANSFER_ENCODING_BASE64)
				$out = base64_encode($out);

			$this->_length = strlen($out);
			$this->_raw = $out;
			$this->_rawData = $out;
		}

		return $this->_raw;
	}

	/**
	 * @param $key
	 * @param mixed $value
	 * @param string $disposition
	 *
	 * @return string
	 */
	private function _serializePart ($key, $value, $disposition) {
		$file = $value instanceof QuarkFile;
		$contents = $file ? $value->Load()->Content() : $value;

		if ($file)
			$this->_files[] = new QuarkModel($value);

		return
			'--' . $this->_boundary . "\r\n"
			. (!$file && $this->_processor instanceof QuarkFormIOProcessor ? '' : (self::HEADER_CONTENT_TYPE . ': ' . ($file ? $value->type : $this->_processor->MimeType()) . "\r\n"))
			. (self::HEADER_CONTENT_DISPOSITION . ': ' . $disposition
				. ($disposition == self::DISPOSITION_FORM_DATA ? '; name="' . $key . '"' : '')
				. ($file ? '; filename="' . $value->name . '"' : '')
				. "\r\n"
			)
			. ($file ? self::HEADER_CONTENT_TRANSFER_ENCODING . ': ' . $this->_encoding . "\r\n" : '')
			. "\r\n"
			. ($file && $this->_encoding == self::TRANSFER_ENCODING_BASE64 ? base64_encode($contents) : $contents)
			. "\r\n";
	}

	/**
	 * @param string $raw
	 *
	 * @return QuarkDTO
	 */
	private function _unserializeHeaders ($raw) {
		if (preg_match_all('#(.*)\: (.*)\n#Uis', $raw . "\r\n", $headers, PREG_SET_ORDER))
			foreach ($headers as $header)
				$this->Header($header[1], $header[2]);

		return $this;
	}

	/**
	 * @param string $raw
	 *
	 * @return QuarkDTO
	 */
	private function _unserializeBody ($raw) {
		if (!$this->_multipart || strpos($raw, '--' . $this->_boundary) === false) {
			$this->_data = QuarkObject::Merge($this->_data, $this->_processor->Decode($raw));
		}
		else {
			$parts = explode('--' . $this->_boundary, $raw);

			foreach ($parts as $part)
				$this->_unserializePart($part);
		}

		return $this;
	}

	/**
	 * @param $raw
	 *
	 * @return QuarkDTO
	 */
	private function _unserializePart ($raw) {
		if (preg_match('#^(.*)\n\s\n(.*)$#Uis', $raw, $found)) {
			$head = array();

			if (preg_match_all('#(.*)\: (.*)\n#Uis', trim($raw) . "\r\n", $headers, PREG_SET_ORDER))
				foreach ($headers as $header)
					$head[$header[1]] = trim($header[2]);

			if (isset($head[self::HEADER_CONTENT_DISPOSITION])) {
				$value = $head[self::HEADER_CONTENT_DISPOSITION];
				$position = explode(';', $value)[0];

				preg_match('#name\=(.*)\;#Uis', $value . ';', $name);
				preg_match('#filename\=(.*)\;#Uis', $value . ';', $file);

				$name = isset($name[1]) ? $name[1] : '';
				$file = isset($file[1]) ? $file[1] : '';

				if ($name == $this->_processor->MimeType())
					$this->MergeData($this->_processor->Decode($found[2]));

				$fs = null;

				if ($file != '') {
					if (isset($head[self::HEADER_CONTENT_TRANSFER_ENCODING]) && $head[self::HEADER_CONTENT_TRANSFER_ENCODING] == self::TRANSFER_ENCODING_BASE64)
						$found[2] = base64_decode($found[2]);
					
					$fs = new QuarkModel(QuarkFile::ForDownload(trim($file, '"'), $found[2]));
					$this->_files[] = $fs;
				}

				if ($position == 'form-data') {
					parse_str(trim($name, '"'), $storage);

					array_walk_recursive($storage, function (&$item) use ($found, $fs) {
						$item = $fs ? $fs : $found[2];
					});

					$this->MergeData($storage);
				}
			}
		}

		return $this;
	}
}

/**
 * Class QuarkTCPNetworkTransport
 *
 * @package Quark
 */
class QuarkTCPNetworkTransport implements IQuarkNetworkTransport {
	/**
	 * @var string $buffer
	 */
	private $_buffer;

	/**
	 * @var callable $_divider
	 */
	private $_divider;

	/**
	 * @param callable $divider
	 */
	public function __construct (callable $divider = null) {
		$this->Divider($divider);
	}

	/**
	 * @param callable $divider
	 *
	 * @return callable
	 */
	public function Divider (callable $divider = null) {
		if (func_num_args() != 0)
			$this->_divider = $divider;

		return $this->_divider;
	}

	/**
	 * @param QuarkClient &$client
	 *
	 * @return void
	 */
	public function EventConnect (QuarkClient &$client) {
		$client->TriggerConnect();
	}

	/**
	 * @param QuarkClient &$client
	 * @param string $data
	 *
	 * @return void
	 */
	public function EventData (QuarkClient &$client, $data) {
		if ($this->_divider == null) {
			$client->TriggerData($data);
			return;
		}

		$this->_buffer .= $data;

		$parts = call_user_func_array($this->_divider, array(&$this->_buffer));
		$size = sizeof($parts);

		$this->_buffer = '';

		if ($size > 1) {
			$this->_buffer = $parts[$size - 1];
			unset($parts[$size - 1]);
		}

		unset($size);

		foreach ($parts as $i => &$part)
			$client->TriggerData($part);

		unset($i, $part, $parts);
	}

	/**
	 * @param QuarkClient &$client
	 *
	 * @return void
	 */
	public function EventClose (QuarkClient &$client) {
		$client->TriggerClose();
	}

	/**
	 * @param string $data
	 *
	 * @return string
	 */
	public function Send ($data) {
		return $data;
	}
}

/**
 * Class QuarkHTTPClient
 *
 * @package Quark
 */
class QuarkHTTPClient {
	/**
	 * @var QuarkDTO $_request
	 */
	private $_request;

	/**
	 * @var QuarkDTO $_response
	 */
	private $_response;

	/**
	 * @param QuarkDTO $request
	 * @param QuarkDTO $response
	 */
	public function __construct (QuarkDTO $request, QuarkDTO $response = null) {
		$this->_request = $request;
		$this->_response = $response;

		if ($this->_response != null)
			$this->_request->Header(QuarkDTO::HEADER_ACCEPT, $this->_response->Processor()->MimeType());
	}

	/**
	 * @param QuarkDTO $request
	 *
	 * @return QuarkDTO
	 */
	public function Request (QuarkDTO $request = null) {
		if (func_num_args() != 0)
			$this->_request = $request;

		return $this->_request;
	}

	/**
	 * @param QuarkDTO $response
	 *
	 * @return QuarkDTO
	 */
	public function Response (QuarkDTO $response = null) {
		if (func_num_args() != 0)
			$this->_response = $response;

		return $this->_response;
	}

	/**
	 * @param QuarkURI|string $uri
	 * @param QuarkDTO $request
	 * @param QuarkDTO $response
	 * @param QuarkCertificate $certificate
	 * @param int $timeout = 10
	 * @param bool $sync = true
	 *
	 * @return QuarkDTO|bool
	 */
	public static function To ($uri, QuarkDTO $request, QuarkDTO $response = null, QuarkCertificate $certificate = null, $timeout = 10, $sync = true) {
		$http = new self($request, $response);
		$client = new QuarkClient($uri, new QuarkTCPNetworkTransport(), $certificate, $timeout, $sync);

		$client->On(QuarkClient::EVENT_CONNECT, function (QuarkClient $client) use (&$http) {
			if ($http->_request == null) return false;

			if ($http->_response == null)
				$http->_response = new QuarkDTO();

			$http->_request->URI($client->URI());
			$http->_response->URI($client->URI());
			
			$http->_request->Remote($client->ConnectionURI(true));
			$http->_response->Remote($client->ConnectionURI(true));

			$http->_response->Method($http->_request->Method());

			$request = $http->_request->SerializeRequest();

			return $client->Send($request);
		});

		$client->On(QuarkClient::EVENT_DATA, function (QuarkClient $client, $data) use (&$http) {
			$http->_response->UnserializeResponse($data);

			return $client->Close();
		});

		$client->On(QuarkClient::EVENT_ERROR_CONNECT, function ($error) {
			Quark::Log($error . '. Error: ' . QuarkException::LastError(), Quark::LOG_WARN);
		});

		if (!$client->Connect()) return false;

		$client->Pipe();

		return $http->Response();
	}

	/**
	 * @param QuarkURI|string $uri
	 * @param QuarkDTO $request
	 * @param QuarkDTO $response
	 * @param QuarkCertificate $certificate
	 * @param int $timeout = 10
	 *
	 * @return QuarkFile
	 */
	public static function Download ($uri, QuarkDTO $request = null, QuarkDTO $response = null, QuarkCertificate $certificate = null, $timeout = 10) {
		if ($request == null)
			$request = QuarkDTO::ForGET();

		$out = self::To($uri, $request, $response, $certificate, $timeout);

		if (!$out || $out->Status() != QuarkDTO::STATUS_200_OK) return null;

		$file = new QuarkFile();

		$uri = ($uri instanceof QuarkURI ? $uri : QuarkURI::FromURI($uri));

		$name = array_reverse($uri->Route())[0];

		$file->Content($out->RawData());
		$file->type = QuarkFile::MimeOf($file->Content());
		$file->extension = QuarkFile::ExtensionByMime($file->type);
		$file->name = $name . (strpos($name, '.') === false ? $file->extension : '');

		return $file;
	}
}

/**
 * Class QuarkHTTPServer
 *
 * @package Quark
 */
class QuarkHTTPServer {
	const DEFAULT_ADDR = 'http://127.0.0.1:80';

	/**
	 * @var QuarkServer $_server
	 */
	private $_server;

	/**
	 * @var callable $_request
	 */
	private $_request;

	/**
	 * @param QuarkURI|string $uri = self::DEFAULT_ADDR
	 * @param callable(QuarkDTO $request):string $request = null
	 * @param QuarkCertificate $certificate = null
	 * @param int $timeout = 0
	 */
	public function __construct ($uri = self::DEFAULT_ADDR, callable $request = null, QuarkCertificate $certificate = null, $timeout = 0) {
		$this->_server = new QuarkServer($uri, new QuarkTCPNetworkTransport(), $certificate, $timeout);

		$this->_server->On(QuarkClient::EVENT_DATA, function (QuarkClient $client, $data) {
			$request = new QuarkDTO();
			$request->UnserializeRequest($data);

			$client->Send(call_user_func_array($this->_request, array(&$request)));
		});

		$this->Request($request);
	}

	/**
	 * @return bool
	 */
	public function Bind () {
		return $this->_server->Bind();
	}

	/**
	 * @return bool
	 */
	public function Pipe () {
		return $this->_server->Pipe();
	}

	/**
	 * @param callable $request = null
	 *
	 * @return callable
	 */
	public function &Request (callable $request = null) {
		if (func_num_args() != 0 && $request != null)
			$this->_request = $request;

		return $this->_request;
	}

	/**
	 * @param QuarkService $service
	 * @param array $input
	 *
	 * @return string
	 * @throws QuarkArchException
	 */
	public static function ServicePipeline (QuarkService &$service, &$input = []) {
		$method = ucfirst(strtolower($service->Input()->Method()));

		if (!($service->Service() instanceof IQuarkHTTPService))
			throw new QuarkArchException('Method ' . $method . ' is not allowed for service ' . get_class($service->Service()));

		if (!method_exists($service->Service(), $method) && $service->Service() instanceof IQuarkAnyService)
			$method = 'Any';

		ob_start();

		if ($service->Authorize(true))
			$service->Invoke($method, $input !== null ? array($service->Input()) : array(), true);
		
		echo $service->Output()->SerializeResponseBody();
		$length = ob_get_length();

		if ($length !== false)
			$service->Output()->Header(QuarkDTO::HEADER_CONTENT_LENGTH, $length);

		return ob_get_clean();
	}
}

/**
 * Class QuarkCookie
 *
 * @package Quark
 */
class QuarkCookie {
	const EXPIRES_FORMAT = 'D, d-M-Y H:i:s';
	const EXPIRES_SESSION = 0;

	/**
	 * @var string $name = ''
	 */
	public $name = '';

	/**
	 * @var string $value = ''
	 */
	public $value = '';

	/**
	 * @var string $expires = ''
	 */
	public $expires = '';

	/**
	 * @var int $MaxAge = 0
	 */
	public $MaxAge = 0;

	/**
	 * @var string $path = '/'
	 */
	public $path = '/';

	/**
	 * @var string $domain = ''
	 */
	public $domain = '';

	/**
	 * @var string $HttpOnly = ''
	 */
	public $HttpOnly = '';

	/**
	 * @var string $secure = ''
	 */
	public $secure = '';

	/**
	 * @param string $name
	 * @param string $value
	 * @param int $lifetime = self::EXPIRES_SESSION
	 */
	public function __construct ($name = '', $value = '', $lifetime = self::EXPIRES_SESSION) {
		$this->name = $name;
		$this->value = $value;

		$this->Lifetime($lifetime);
	}

	/**
	 * @return string
	 */
	public function __toString () {
		return $this->value;
	}

	/**
	 * @param int $seconds = 0
	 *
	 * @return int
	 */
	public function Lifetime ($seconds = 0) {
		if (func_num_args() != 0) {
			$this->MaxAge = $seconds;

			if ($seconds == 0) {
				$this->expires = '';
				return 0;
			}

			$expires = QuarkDate::GMTNow();
			$expires->Offset('+' . $seconds . ' seconds');

			$this->expires = $expires->Format(self::EXPIRES_FORMAT);
		}

		return QuarkDate::GMTNow()->Interval(QuarkDate::GMTOf($this->expires));
	}

	/**
	 * @param string $header = ''
	 *
	 * @return QuarkCookie[]
	 */
	public static function FromCookie ($header = '') {
		$out = array();
		$cookies = array_merge(explode(',', $header), explode(';', $header));

		foreach ($cookies as $raw) {
			$cookie = explode('=', trim($raw));

			if (sizeof($cookie) == 2)
				$out[] = new QuarkCookie($cookie[0], $cookie[1]);
		}

		return $out;
	}

	/**
	 * @param string $header = ''
	 *
	 * @return QuarkCookie
	 */
	public static function FromSetCookie ($header = '') {
		$cookie = explode(';', $header);

		$instance = new QuarkCookie();

		foreach ($cookie as $component) {
			$item = explode('=', $component);

			$key = trim($item[0]);
			$value = isset($item[1]) ? trim($item[1]) : '';

			if (isset($instance->$key)) $instance->$key = $value;
			else {
				$instance->name = $key;
				$instance->value = $value;
			}
		}

		return $instance;
	}

	/**
	 * @param QuarkCookie[] $cookies = []
	 *
	 * @return string
	 */
	public static function SerializeCookies ($cookies = []) {
		$out = '';

		foreach ($cookies as $cookie)
			$out .= $cookie->name . '=' . $cookie->value . '; ';

		return substr($out, 0, strlen($out) - 2);
	}

	/**
	 * @param bool $full = false
	 *
	 * @return string
	 */
	public function Serialize ($full = false) {
		$out = $this->name . '=' . $this->value;

		if (!$full) return $out;
		else {
			foreach ($this as $field => $value)
				if (strlen(trim($value)) != 0 && $field != 'name' && $field != 'value')
					$out .= '; ' . $field . '=' . $value;

			return $out;
		}
	}
}

/**
 * Class QuarkLanguage
 *
 * @package Quark
 */
class QuarkLanguage {
	const ANY = '*';
	const EN_EN = 'en-EN';
	const EN_GB = 'en-GB';
	const EN_US = 'en-US';
	const RU_RU = 'ru-RU';
	const MD_MD = 'md-MD';

	/**
	 * @var string $_name = self::ANY
	 */
	private $_name = '';

	/**
	 * @var int|float $_quantity = 1
	 */
	private $_quantity = 1;

	/**
	 * @var string $_family = ''
	 */
	private $_family = '';

	/**
	 * @var string $_location = ''
	 */
	private $_location = '';

	/**
	 * @param string $name = self::ANY
	 * @param int $quantity = 1
	 * @param string $location = ''
	 */
	public function __construct ($name = self::ANY, $quantity = 1, $location = '') {
		$this->_name = $name;
		$this->_quantity = $quantity;

		$name = explode('-', $name);
		
		$this->_family = $name[0];
		$this->_location = strtoupper(func_num_args() == 3
			? $location
			: array_reverse($name)[0]
		);
	}

	/**
	 * @param string $name = self::ANY
	 *
	 * @return string
	 */
	public function Name ($name = self::ANY) {
		if (func_num_args() != 0)
			$this->_name = $name;

		return $this->_name;
	}

	/**
	 * @param int|float $quantity = 1
	 *
	 * @return int|float
	 */
	public function Quantity ($quantity = 1) {
		if (func_num_args() != 0)
			$this->_quantity = $quantity;

		return $this->_quantity;
	}

	/**
	 * @return string
	 */
	public function Family () {
		return $this->_family;
	}

	/**
	 * @return string
	 */
	public function Location () {
		return $this->_location;
	}

	/**
	 * @param string $language
	 * @param bool $strict = false
	 *
	 * @return bool
	 */
	public function Is ($language, $strict = false) {
		if ($strict) return $this->_name == $language;
		if ($language == self::ANY) return true;
		if ($this->_name == self::ANY) return true;
		if ($this->_name == $language) return true;

		$item = QuarkKeyValuePair::ByDelimiter('-', $language);

		return $this->_family == $item->Key()
			? ($this->_location == '' || $item->Value() == '')
			: false;
	}

	/**
	 * @param string $header = ''
	 *
	 * @return QuarkLanguage[]
	 */
	public static function FromAcceptLanguage ($header = '') {
		$out = array();
		$languages = explode(',', $header);

		foreach ($languages as $raw) {
			$language = explode(';', $raw);
			$loc = explode('-', $language[0]);
			$q = explode('=', sizeof($language) == 1 ? 'q=1' : $language[1]);

			$out[] = new QuarkLanguage($language[0], array_reverse($q)[0], array_reverse($loc)[0]);
		}

		return $out;
	}

	/**
	 * @param string $header = ''
	 *
	 * @return QuarkLanguage[]
	 */
	public static function FromContentLanguage ($header = '') {
		$out = array();
		$languages = explode(',', $header);

		foreach ($languages as $raw)
			$out[] = new QuarkLanguage(trim($raw));

		return $out;
	}

	/**
	 * @param QuarkLanguage[] $languages = []
	 *
	 * @return string
	 */
	public static function SerializeAcceptLanguage ($languages = []) {
		if (!is_array($languages)) return '';

		$out = array();

		/**
		 * @var QuarkLanguage[] $languages
		 */
		foreach ($languages as $language)
			$out[] = $language->Name() . ';q=' . $language->Quantity();

		return implode(',', $out);
	}

	/**
	 * @param QuarkLanguage[] $languages = []
	 *
	 * @return string
	 */
	public static function SerializeContentLanguage ($languages = []) {
		if (!is_array($languages)) return '';

		$out = array();

		/**
		 * @var QuarkLanguage[] $languages
		 */
		foreach ($languages as $language)
			$out[] = $language->Name();

		return implode(',', $out);
	}
}
/**
 * Class QuarkMIMEType
 *
 * @package Quark
 */
class QuarkMIMEType {
	const ANY = '*/*';

	/**
	 * @var string $_name = self::ANY
	 */
	private $_name = self::ANY;

	/**
	 * @var int|float $_quantity = 1
	 */
	private $_quantity = 1;

	/**
	 * @var string $_range = '*'
	 */
	private $_range = '*';

	/**
	 * @var string $_type = '*'
	 */
	private $_type = '*';

	/**
	 * @var array $_params = []
	 */
	private $_params = array();

	/**
	 * @param string $name = self::ANY
	 * @param int $quantity = 1
	 * @param string $type = '*'
	 */
	public function __construct ($name = self::ANY, $quantity = 1, $type = '*') {
		$this->_name = $name;
		$this->_quantity = $quantity;
		$this->_params['q'] = $quantity;
		
		$type = explode('/', $name);

		$this->_range = $type[0];
		$this->_type = func_num_args() == 3
			? $type
			: array_reverse($type)[0];
	}

	/**
	 * @param string $name = self::ANY
	 *
	 * @return string
	 */
	public function Name ($name = self::ANY) {
		if (func_num_args() != 0)
			$this->_name = $name;
		
		return $this->_name;
	}

	/**
	 * @param int|float $quantity = 1
	 *
	 * @return int|float
	 */
	public function Quantity ($quantity = 1) {
		if (func_num_args() != 0)
			$this->_quantity = $quantity;

		return $this->_quantity;
	}

	/**
	 * @return string
	 */
	public function Range () {
		return $this->_range;
	}

	/**
	 * @return string
	 */
	public function Type () {
		return $this->_type;
	}

	/**
	 * @param array $params = []
	 *
	 * @return array
	 */
	public function Params ($params = []) {
		if (func_num_args() != 0)
			$this->_params = $params;
		
		return $this->_params;
	}

	/**
	 * @param string $key
	 * @param string $value
	 *
	 * @return QuarkMIMEType
	 */
	public function Param ($key, $value) {
		$this->_params[$key] = $value;

		if ($key == 'q')
			$this->_quantity = (float)$value;

		return $this;
	}

	/**
	 * @param string $type
	 * @param bool $strict = false
	 *
	 * @return bool
	 */
	public function Is ($type, $strict = false) {
		if ($strict) return $this->_name == $type;
		if ($type == self::ANY) return true;
		if ($this->_name == self::ANY) return true;
		if ($this->_name == $type) return true;

		$item = QuarkKeyValuePair::ByDelimiter('/', $type);
		
		return $this->_range == $item->Key()
			? ($this->_type == '*' || $item->Value() == '*')
			: false;
	}
	
	/**
	 * @param string $header = ''
	 *
	 * @return QuarkMIMEType[]
	 */
	public static function FromHeader ($header = '') {
		$out = array();
		$types = explode(',', $header);

		foreach ($types as $raw) {
			$type = explode(';', trim($raw));
			$item = new QuarkMIMEType($type[0]);
			
			if (sizeof($type) > 1) {
				$params = array_slice($type, 1);
				
				foreach ($params as $param) {
					$pair = explode('=', trim($param));

					if (sizeof($pair) == 2)
						$item->Param($pair[0], $pair[1]);
				}
			}

			$out[] = $item;
		}

		return $out;
	}
}

/**
 * Class QuarkFile
 *
 * @package Quark
 */
class QuarkFile implements IQuarkModel, IQuarkStrongModel, IQuarkLinkedModel {
	const LOCAL_FS = 'LocalFS';

	const TYPE_APPLICATION_OCTET_STREAM = 'application/octet-stream';

	const MODE_ANYONE = 0777;
	const MODE_GROUP = 0771;
	const MODE_USER = 0711;
	const MODE_DEFAULT = self::MODE_ANYONE;

	/**
	 * @var string $location = ''
	 */
	public $location = '';

	/**
	 * @var string $name = ''
	 */
	public $name = '';

	/**
	 * @var string $type = ''
	 */
	public $type = '';

	/**
	 * @var string $tmp_name = ''
	 */
	public $tmp_name = '';

	/**
	 * @var int $size = 0
	 */
	public $size = 0;

	/**
	 * @var string $extension = ''
	 */
	public $extension = '';

	/**
	 * @var bool $isDir = false
	 */
	public $isDir = false;

	/**
	 * @var string $parent = ''
	 */
	public $parent = '';

	/**
	 * @var string $_content = ''
	 */
	protected $_content = '';

	/**
	 * @var bool $_loaded = ''
	 */
	protected $_loaded = false;

	/**
	 * @var string $_lastCopy = ''
	 */
	protected $_lastCopy = '';
	
	/**
	 * @param bool $warn = true
	 * 
	 * @return bool
	 */
	public static function MimeExtensionExists ($warn = true) {
		if (function_exists('\finfo_open')) return true;
		
		if ($warn)
			Quark::Log('[QuarkFile] Mime extension not loaded. Check your PHP configuration. ' . self::TYPE_APPLICATION_OCTET_STREAM . ' used for response of file type', Quark::LOG_WARN);
		
		return false;
	}

	/**
	 * @param string $location
	 * @warning memory leak in native `finfo_file` realization
	 *
	 * @return mixed
	 */
	public static function Mime ($location) {
		if (!$location) return false;
		if (!self::MimeExtensionExists()) return self::TYPE_APPLICATION_OCTET_STREAM;

		$info = \finfo_open(FILEINFO_MIME_TYPE);
		$type = \finfo_file($info, $location);
		\finfo_close($info);

		return $type;
	}

	/**
	 * @param string $content
	 *
	 * @return mixed
	 */
	public static function MimeOf ($content) {
		if (!$content) return false;
		if (!self::MimeExtensionExists()) return self::TYPE_APPLICATION_OCTET_STREAM;
		
		$info = \finfo_open(FILEINFO_MIME_TYPE);
		$type = \finfo_buffer($info, $content);
		\finfo_close($info);

		return $type;
	}

	/**
	 * @param string $mime
	 *
	 * @return string
	 */
	public static function ExtensionByMime ($mime) {
		$extension = array_reverse(explode('/', $mime));

		if ($extension[0] == 'jpeg')
			$extension[0] = 'jpg';

		return sizeof($extension) == 2 && substr_count($extension[0], '-') == 0 ? $extension[0] : null;
	}

	/**
	 * @param string $location = ''
	 * @param bool $load = false
	 */
	public function __construct ($location = '', $load = false) {
		if (func_num_args() != 0 && $location)
			$this->Location($location);

		if ($load)
			$this->Load();
	}

	/**
	 * @return string
	 */
	public function __toString () {
		return $this->WebLocation();
	}

	/**
	 * @param string $location = ''
	 * @param string $name = ''
	 *
	 * @return string
	 */
	public function Location ($location = '', $name = '') {
		if (func_num_args() != 0) {
			$this->location = $location;
			$this->name = $name ? $name : array_reverse(explode('/', (string)$this->location))[0];
			$this->parent = str_replace($this->name, '', $this->location);
		}

		return $this->location;
	}

	/**
	 * @warning memory leak in native `file_exists` realization
	 *
	 * @return bool
	 */
	public function Exists () {
		return is_file($this->location);
	}

	/**
	 * @param string $location = ''
	 *
	 * @return QuarkFile
	 * @throws QuarkArchException
	 */
	public function Load ($location = '') {
		if ($this->tmp_name)
			$this->Location($this->tmp_name, $this->name);

		if (func_num_args() != 0)
			$this->Location($location);

		if (!$this->Exists())
			throw new QuarkArchException('Invalid file path "' . $this->location . '"');

		if (Quark::MemoryAvailable()) {
			$this->Content(file_get_contents($this->location));
			$this->type = self::MimeOf($this->_content);
			$this->extension = self::ExtensionByMime($this->type);
			$this->_loaded = true;
		}

		return $this;
	}
	
	/**
	 * @return bool
	 */
	public function Loaded () {
		return $this->_loaded;
	}

	/**
	 * @param int $mode = self::MODE_DEFAULT
	 *
	 * @return bool
	 */
	private function _followParent ($mode = self::MODE_DEFAULT) {
		if (is_dir($this->parent) || is_file($this->parent)) return true;

		$ok = @mkdir($this->parent, $mode, true);
		
		if (!$ok)
			Quark::Log('[QuarkFile::_followParent] Can not create dir "' . $this->parent . '". Error: ' . QuarkException::LastError());

		return $ok;
	}

	/**
	 * @param int $mode = self::MODE_DEFAULT
	 * @param bool $upload = false
	 *
	 * http://php.net/manual/ru/function.mkdir.php#114960
	 *
	 * @return bool
	 */
	public function SaveContent ($mode = self::MODE_DEFAULT, $upload = false) {
		if ($upload && $this->tmp_name)
			return $this->Upload(true, $mode);

		$this->_followParent($mode);

		return file_put_contents($this->location, $this->_content, LOCK_EX) !== false;
	}

	/**
	 * @param string $name = ''
	 *
	 * @return bool
	 */
	public function SaveCopy ($name = '') {
		$this->_lastCopy = $this->parent . (func_num_args() == 0 ? $this->name : $name) . '.' . $this->extension;

		return file_put_contents($this->_lastCopy, $this->_content, LOCK_EX) !== false;
	}

	/**
	 * @return string
	 */
	public function LastCopy () {
		return $this->_lastCopy;
	}

	/**
	 * @param string $name = ''
	 *
	 * @return string
	 */
	public function ChangeName ($name = '') {
		return $this->Location($this->parent . '/' . $name . '.' . $this->extension);
	}

	/**
	 * @return bool
	 */
	public function DeleteFromDisk () {
		if (!@unlink($this->location)) {
			Quark::Log('[QuarkFile::DeleteFromDisk] ' . QuarkException::LastError() . '. Location: "' . $this->location . '"', Quark::LOG_WARN);
			return false;
		}

		return true;
	}

	/**
	 * @param string $parent = ''
	 *
	 * @return string
	 */
	public function WebLocation ($parent = '') {
		return Quark::WebLocation(func_num_args() != 0
			? ($parent . $this->name)
			: $this->location
		);
	}

	/**
	 * @param string $content = ''
	 *
	 * @return string
	 */
	public function Content ($content = '') {
		if (func_num_args() == 1) {
			$this->_content = $content;
			$this->size = strlen($this->_content);
		}

		return $this->_content;
	}

	/**
	 * @param int $unit = Quark::UNIT_MEGABYTE
	 * @param int $precision = 2
	 *
	 * @return float
	 */
	public function Size ($unit = Quark::UNIT_MEGABYTE, $precision = 2) {
		return round($this->size / $unit, $precision);
	}

	/**
	 * @param bool $mime = true
	 * @param int $mode = self::MODE_DEFAULT
	 *
	 * @return bool
	 */
	public function Upload ($mime = true, $mode = self::MODE_DEFAULT) {
		if ($mime) {
			$ext = self::ExtensionByMime(self::Mime($this->tmp_name));
			$this->location .= $ext ? '.' . $ext : '';
			$this->extension = $ext;
		}

		$this->_followParent($mode);

		if (!is_file($this->tmp_name) || !is_dir(dirname($this->location)))  {
			Quark::Log('[QuarkFile::Upload] The [tmp_name:' . $this->tmp_name . '] or parent dir of [location:' . $this->location . ']. does not exists ' . QuarkException::LastError(), Quark::LOG_WARN);
			return false;
		}

		if (!rename($this->tmp_name, $this->location))  {
			Quark::Log('[QuarkFile::Upload] Cannot move from [tmp_name:' . $this->tmp_name . '] to [location:' . $this->location . ']. ' . QuarkException::LastError(), Quark::LOG_WARN);
			return false;
		}

		if (!chmod($this->location, $mode))  {
			Quark::Log('[QuarkFile::Upload] Cannot set mode [mode:' . sprintf('%o', $mode) . '] to [location:' . $this->location . ']. ' . QuarkException::LastError(), Quark::LOG_WARN);
			return false;
		}

		$this->Location($this->location);
		return true;
	}

	/**
	 * @param string $location = ''
	 * @param bool $mime = true
	 * @param int $mode = self::MODE_DEFAULT
	 *
	 * @return bool
	 */
	public function UploadTo ($location = '', $mime = true, $mode = self::MODE_DEFAULT) {
		$this->Location($location);
		
		return $this->Upload($mime, $mode);
	}

	/**
	 * @return QuarkDTO
	 */
	public function Download () {
		$response = new QuarkDTO(new QuarkPlainIOProcessor());

		$response->Header(QuarkDTO::HEADER_CONTENT_TYPE, $this->type);
		$response->Header(QuarkDTO::HEADER_CONTENT_DISPOSITION, 'attachment; filename="' . $this->name . '"');

		if (!$this->_loaded)
			$this->Content(file_get_contents($this->location));

		$response->Data($this->_content);

		return $response;
	}
	
	/**
	 * @param IQuarkIOProcessor $processor
	 * @param $data = []
	 *
	 * @return QuarkFile
	 */
	public function Encode (IQuarkIOProcessor $processor, $data = []) {
		$this->Content($processor->Encode($data));
		
		return $this;
	}
	
	/**
	 * @param IQuarkIOProcessor $processor
	 * @param bool $load = false
	 *
	 * @return mixed
	 */
	public function Decode (IQuarkIOProcessor $processor, $load = false) {
		if ($load && !$this->_loaded)
			$this->Load();
		
		return $this->_loaded ? $processor->Decode($this->_content) : null;
	}

	/**
	 * @return array
	 */
	public function Fields () {
		return array(
			'_location' => '',
			'location' => '',
			'name' => '',
			'extension' => '',
			'type' => '',
			'size' => 0,
			'isDir' => false,
			'tmp_name' => ''
		);
	}

	/**
	 * @return array
	 */
	public function Rules () {
		return array(
			QuarkField::is($this->name, QuarkField::TYPE_STRING),
			QuarkField::is($this->type, QuarkField::TYPE_STRING),
			QuarkField::is($this->size, QuarkField::TYPE_INT),
			QuarkField::is($this->tmp_name, QuarkField::TYPE_STRING),
			QuarkField::MinLength($this->name, 1)
		);
	}

	/**
	 * @param $raw
	 *
	 * @return mixed
	 */
	public function Link ($raw) {
		return new QuarkModel($raw ? new QuarkFile($raw) : clone $this);
	}

	/**
	 * @return mixed
	 */
	public function Unlink () {
		return $this->location;
	}
	
	/**
	 * @param string $location = ''
	 * @param bool $load = false
	 *
	 * @return QuarkFile
	 */
	public static function FromLocation ($location = '', $load = false) {
		return new self($location, $load);
	}
	
	/**
	 * @param array $files
	 * 
	 * https://github.com/zendframework/zend-http/blob/master/src/PhpEnvironment/Request.php
	 *
	 * @return array
	 */
	public static function FromFiles ($files) {
		$output = array();
		
        foreach ($files as $name => $value) {
			$output[$name] = array();
			
			foreach ($value as $param => $data) {
				if (!is_array($data)) {
					self::_file_populate($output, $name, $param, $data);
					continue;
				}
				
				foreach ($data as $k => $v)
					self::_file_buffer($output[$name], $param, $k, $v);
			}
        }

		return $output;
	}
	
	/**
	 * @param string $name = ''
	 * @param string $content = ''
	 *
	 * @return QuarkFile
	 */
	public static function ForDownload ($name = '', $content = '') {
		$file = new self();
		
		$file->_loaded = true;
		$file->name = $name;
		$file->Content($content);
		
		return $file;
	}

	/**
	 * @param array &$item
	 * @param string|int $name
	 * @param string|int $index
	 * @param string|int $value
	 */
	private static function _file_buffer (&$item, $name, $index, $value) {
		if (!is_array($value)) {
			self::_file_populate($item, $index, $name, $value);
			return;
		}

		foreach ($value as $i => $v)
			self::_file_buffer($item[$index], $name, $i, $v);
	}

	/**
	 * @param array &$source
	 * @param string|int $key
	 * @param string|int $name
	 * @param string|int $value
	 */
	private static function _file_populate (&$source, $key, $name, $value) {
		if (!isset($source[$key]))
			$source[$key] = array();

		if (!($source[$key] instanceof QuarkModel && $source[$key]->Model() instanceof QuarkFile))
			$source[$key] = new QuarkModel(new QuarkFile());

		$source[$key]->PopulateWith(array(
			$name => $value
		));
	}
}

/**
 * Interface IQuarkCulture
 *
 * @package Quark
 */
interface IQuarkCulture {
	/**
	 * @return string
	 */
	public function DateTimeFormat();

	/**
	 * @return string
	 */
	public function DateFormat();

	/**
	 * @return string
	 */
	public function TimeFormat();
}

/**
 * Class QuarkCultureISO
 *
 * @package Quark
 */
class QuarkCultureISO implements IQuarkCulture {
	const DATETIME = 'Y-m-d H:i:s';
	const DATE = 'Y-m-d';
	const TIME = 'H:i:s';
	
	/**
	 * @return string
	 */
	public function DateTimeFormat () { return self::DATETIME; }

	/**
	 * @return string
	 */
	public function DateFormat () { return self::DATE; }

	/**
	 * @return string
	 */
	public function TimeFormat () { return self::TIME; }
}

/**
 * Class QuarkCultureRU
 *
 * @package Quark
 */
class QuarkCultureRU implements IQuarkCulture {
	const DATETIME = 'd.m.Y H:i:s';
	const DATE = 'd.m.Y';
	const TIME = 'H:i:s';
	
	/**
	 * @return string
	 */
	public function DateTimeFormat () { return self::DATETIME; }

	/**
	 * @return string
	 */
	public function DateFormat () { return self::DATE; }

	/**
	 * @return string
	 */
	public function TimeFormat () { return self::TIME; }
}

/**
 * Class QuarkCultureCustom
 *
 * @package Quark
 */
class QuarkCultureCustom implements IQuarkCulture {
	/**
	 * @var string $_dateTime = '';
	 */
	private $_dateTime = '';

	/**
	 * @var string $_date = ''
	 */
	private $_date = '';

	/**
	 * @var string $_time = ''
	 */
	private $_time = '';

	/**
	 * @param string $dateTime = ''
	 * @param string $date = ''
	 * @param string $time = ''
	 */
	public function __construct ($dateTime = '', $date = '', $time = '') {
		$this->_dateTime = $dateTime;
		$this->_date = $date;
		$this->_time = $time;
	}

	/**
	 * @param $format
	 *
	 * @return QuarkCultureCustom|QuarkCultureISO
	 */
	public static function Format ($format) {
		if ($format == null)
			return new QuarkCultureISO();

		$dateTime = explode(' ', $format);

		return new self($format, $dateTime[0], array_reverse($dateTime)[0]);
	}

	/**
	 * @return string
	 */
	public function DateTimeFormat () {
		return $this->_dateTime;
	}

	/**
	 * @return string
	 */
	public function DateFormat () {
		return $this->_date;
	}

	/**
	 * @return string
	 */
	public function TimeFormat () {
		return $this->_time;
	}
}

/**
 * Class QuarkException
 *
 * @package Quark
 */
abstract class QuarkException extends \Exception {
	/**
	 * @var string
	 */
	public $lvl = Quark::LOG_WARN;

	/**
	 * @var string
	 */
	public $message = 'QuarkException';

	/**
	 * @param \Exception $exception
	 *
	 * @return bool|int
	 */
	public static function ExceptionHandler (\Exception $exception) {
		if ($exception instanceof QuarkException)
			return Quark::Log($exception->message, $exception->lvl) != Quark::LOG_FATAL && $exception->lvl;

		if ($exception instanceof \Exception)
			return Quark::Log('Common exception: ' . $exception->getMessage() . "\r\n at " . $exception->getFile() . ':' . $exception->getLine(), Quark::LOG_FATAL);

		return true;
	}

	/**
	 * @param bool $array = false
	 *
	 * @return string|array|null
	 */
	public static function LastError ($array = false) {
		$error = error_get_last();

		if (!$error || !is_array($error)) return null;

		return $array ? $error : (array_key_exists('message', $error) ? str_replace('&quot;', '"', $error['message']) : '');
	}
}

/**
 * Class QuarkArchException
 *
 * @package Quark
 */
class QuarkArchException extends QuarkException {
	/**
	 * @param string $message
	 * @param string $lvl = Quark::LOG_FATAL
	 */
	public function __construct ($message, $lvl = Quark::LOG_FATAL) {
		$this->lvl = $lvl;
		$this->message = $message;
	}
}

/**
 * Class QuarkHTTPException
 *
 * @package Quark
 */
class QuarkHTTPException extends QuarkException {
	/**
	 * @var int
	 */
	public $status = 500;

	/**
	 * @var string $_log = ''
	 */
	public $log = '';

	/**
	 * @param int $status = 500
	 * @param string $message
	 * @param string $log = ''
	 */
	public function __construct ($status = 500, $message = '', $log = '') {
		$this->lvl = Quark::LOG_FATAL;
		$this->message = $message;

		$this->status = $status;
		$this->log = func_num_args() == 3 ? $log : $message;
	}

	/**
	 * @return string
	 */
	public function Status () {
		return trim($this->status . ' ' . $this->message);
	}

	/**
	 * @param string $status
	 * @param string $log = ''
	 *
	 * @return QuarkHTTPException
	 */
	public static function ForStatus ($status, $log = '') {
		$exception = new self();
		$exception->status = $status;
		$exception->log = $log;

		return $exception;
	}
}

/**
 * Class QuarkConnectionException
 *
 * @package Quark
 */
class QuarkConnectionException extends QuarkException {
	/**
	 * @var QuarkURI
	 */
	public $uri;

	/**
	 * @param QuarkURI $uri
	 * @param string $lvl
	 * @param string $additional = ''
	 */
	public function __construct (QuarkURI $uri, $lvl = Quark::LOG_WARN, $additional = '') {
		$this->lvl = $lvl;
		$this->message = 'Unable to connect to ' . $uri->URI() . (strlen($additional) == 0 ? '' : '. ' . $additional);

		$this->uri = $uri;
	}
}

/**
 * Interface IQuarkIOProcessor
 *
 * @package Quark
 */
interface IQuarkIOProcessor {
	/**
	 * @return string
	 */
	public function MimeType();

	/**
	 * @param $data
	 *
	 * @return string
	 */
	public function Encode($data);

	/**
	 * @param $raw
	 *
	 * @return mixed
	 */
	public function Decode($raw);

	/**
	 * @param string $raw
	 *
	 * @return mixed
	 */
	public function Batch($raw);
}

/**
 * Class QuarkPlainIOProcessor
 *
 * @package Quark
 */
class QuarkPlainIOProcessor implements IQuarkIOProcessor {
	const MIME = 'plain/text';

	/**
	 * @return string
	 */
	public function MimeType () { return self::MIME; }

	/**
	 * @param $data
	 *
	 * @return string
	 */
	public function Encode ($data) { return is_scalar($data) ? (string)$data : ''; }

	/**
	 * @param $raw
	 *
	 * @return mixed
	 */
	public function Decode ($raw) { return $raw; }

	/**
	 * @param string $raw
	 *
	 * @return mixed
	 */
	public function Batch ($raw) { return $raw; }
}

/**
 * Class QuarkHTMLIOProcessor
 *
 * @package Quark
 */
class QuarkHTMLIOProcessor implements IQuarkIOProcessor {
	const MIME = 'text/html';
	const TYPE_KEY = self::MIME;

	/**
	 * @return string
	 */
	public function MimeType () { return self::MIME; }

	/**
	 * @param $data
	 *
	 * @return string
	 */
	public function Encode ($data) {
		if ($data instanceof QuarkView)
			return $data->Compile();

		if (is_string($data)) return $data;

		$data = (array)$data;

		return isset($data[self::TYPE_KEY]) ? $data[self::TYPE_KEY] : '';
	}

	/**
	 * @param $raw
	 *
	 * @return mixed
	 */
	public function Decode ($raw) { return $raw; }

	/**
	 * @param string $raw
	 *
	 * @return mixed
	 */
	public function Batch ($raw) { return $raw; }
}

/**
 * Class QuarkFormIOProcessor
 *
 * @package Quark
 */
class QuarkFormIOProcessor implements IQuarkIOProcessor {
	const MIME = 'application/x-www-form-urlencoded';

	/**
	 * @return string
	 */
	public function MimeType () { return self::MIME; }

	/**
	 * @param $data
	 *
	 * @return string
	 */
	public function Encode ($data) {
		return is_array($data) ? http_build_query($data) : '';
	}

	/**
	 * @param $raw
	 *
	 * @return mixed
	 */
	public function Decode ($raw) {
		$data = array();

		parse_str($raw, $data);

		return $data;
	}

	/**
	 * @param string $raw
	 *
	 * @return mixed
	 */
	public function Batch ($raw) { return $raw; }
}

/**
 * Class QuarkJSONIOProcessor
 *
 * @package Quark
 */
class QuarkJSONIOProcessor implements IQuarkIOProcessor {
	const MIME = 'application/json';

	/**
	 * @return string
	 */
	public function MimeType () { return self::MIME; }

	/**
	 * @param $data
	 *
	 * @return string
	 */
	public function Encode ($data) { return \json_encode($data); }

	/**
	 * @param $raw
	 *
	 * @return mixed
	 */
	public function Decode ($raw) { return \json_decode($raw); }

	/**
	 * @param string $raw
	 *
	 * @return mixed
	 */
	public function Batch ($raw) {
		$raw = substr($raw, 0, 8192);
		return explode('}-{', str_replace('}{', '}}-{{', $raw));
	}
}

/**
 * Class QuarkXMLIOProcessor
 *
 * @package Quark
 */
class QuarkXMLIOProcessor implements IQuarkIOProcessor {
	const PATTERN_ATTRIBUTE = '#([a-zA-Z0-9\:\_\.\-]+)\=\"(.*)\"#UisS';
	const PATTERN_ELEMENT = '#\<([a-zA-Z0-9\:\_\.\-]+)\s*((([a-zA-Z0-9\:\_\.\-]+)\=\"(.*)\")*)\s*(\>(.*)\<\/\1|\/)\>#UisS';
	const PATTERN_META = '#^\s*\<\?xml\s*((([a-zA-Z0-9\:\_\-\.]+?)\=\"(.*)\")*)\s*\?\>#UisS';
	const PATTERN_COMMENT = '#\<\!\-\-(.*)\-\-\>#is';

	const MIME = 'text/xml';
	const ROOT = 'root';
	const ITEM = 'item';
	const VERSION_1_0 = '1.0';
	const ENCODING_UTF_8 = 'utf-8';

	/**
	 * @var string $version = self::VERSION_1_0
	 */
	private $_version = self::VERSION_1_0;

	/**
	 * @var string $_encoding = self::ENCODING_UTF_8
	 */
	private $_encoding = self::ENCODING_UTF_8;

	/**
	 * @var QuarkXMLNode $root = null
	 */
	private $_root = self::ROOT;

	/**
	 * @var string $_item = self::ITEM
	 */
	private $_item = self::ITEM;

	/**
	 * @var bool $_forceNull = true
	 */
	private $_forceNull = true;

	/**
	 * @var bool $_init = false
	 */
	private $_init = false;
	
	/**
	 * @var int $_lists = 0;
	 */
	private $_lists = 0;

	/**
	 * @var string[] $_comments = []
	 */
	private $_comments = array();

	/**
	 * @param QuarkXMLNode $root = null
	 * @param string $item = self::ITEM
	 * @param bool $forceNull = true
	 * @param string $version = self::VERSION_1_0
	 * @param string $encoding = self::ENCODING_UTF_8
	 */
	public function __construct (QuarkXMLNode $root = null, $item = self::ITEM, $forceNull = true, $version = self::VERSION_1_0, $encoding = self::ENCODING_UTF_8) {
		\libxml_use_internal_errors(true);

		$this->Root($root == null ? new QuarkXMLNode(self::ROOT) : $root);
		$this->Item($item);
		$this->ForceNull($forceNull);
		$this->Version($version);
		$this->Encoding($encoding);
	}
	
	/**
	 * @param QuarkXMLNode $root = null
	 *
	 * @return QuarkXMLNode
	 */
	public function Root (QuarkXMLNode $root = null) {
		if (func_num_args() != 0)
			$this->_root = $root;
		
		return $this->_root;
	}
	
	/**
	 * @param string $item = self::ITEM
	 *
	 * @return string
	 */
	public function Item ($item = self::ITEM) {
		if (func_num_args() != 0)
			$this->_item = $item;
		
		return $this->_item;
	}
	
	/**
	 * @param bool $forceNull = true
	 *
	 * @return bool
	 */
	public function ForceNull ($forceNull = true) {
		if (func_num_args() != 0)
			$this->_forceNull = $forceNull;
		
		return $this->_forceNull;
	}

	/**
	 * @param string $version = self::VERSION_1_0
	 *
	 * @return string
	 */
	public function Version ($version = self::VERSION_1_0) {
		if (func_num_args() != 0)
			$this->_version = $version;

		return $this->_version;
	}

	/**
	 * @param string $encoding = self::ENCODING_UTF_8
	 *
	 * @return string
	 */
	public function Encoding ($encoding = self::ENCODING_UTF_8) {
		if (func_num_args() != 0)
			$this->_encoding = $encoding;
		
		return $this->_encoding;
	}

	/**
	 * @return string[]
	 */
	public function Comments () {
		return $this->_comments;
	}

	/**
	 * @return string
	 */
	public function MimeType () { return self::MIME; }

	/**
	 * @param $data
	 * @param bool $meta = true
	 *
	 * @return string
	 */
	public function Encode ($data, $meta = true) {
		if (!$this->_init) {
			$this->_init = true;
			$this->_root->Data($data instanceof QuarkXMLNode ? array($data) : $data);

			$out = ($meta ? ('<?xml version="' . $this->_version . '" encoding="' . $this->_encoding . '" ?>') : '')
				 . $this->_root->ToXML($this);

			$this->_init = false;

			return $out;
		}

		$out = '';
		$i = $this->_lists == 0 ? '' : $this->_lists;
		
		if (QuarkObject::isIterative($data)) {
			$this->_lists++;

			foreach ($data as $item)
				$out .= $item instanceof QuarkXMLNode
					? $item->ToXML($this, $this->_item)
					: ('<' . $this->_item . $i . '>' . $this->Encode($item) . '</' . $this->_item . $i . '>');

			$this->_lists--;
			
			return $out;
		}

		if (QuarkObject::isAssociative($data)) {
			foreach ($data as $key => $value)
				$out .= $value instanceof QuarkXMLNode
					? $value->ToXML($this, $key)
					: '<' . $key . '>' . $this->Encode($value) . '</' . $key . '>';

			return $out;
		}

		return $data;
	}

	/**
	 * @param $raw
	 * @param bool $_meta = true
	 *
	 * @return mixed
	 */
	public function Decode ($raw, $_meta = true) {
		$raw = preg_replace_callback(self::PATTERN_COMMENT, function ($item) {
			$this->_comments[] = trim($item[1]);

			return '';
		}, $raw);

		if ($_meta && preg_match(self::PATTERN_META, $raw, $info)) {
			$meta = self::DecodeAttributes($info[2]);

			if (isset($meta->version))
				$this->_version = $meta->version;
			
			if (isset($meta->encoding))
				$this->_encoding = $meta->encoding;
		}

		if (!preg_match_all(self::PATTERN_ELEMENT, $raw, $xml, PREG_SET_ORDER)) return null;

		$out = array();
		$item = '';

		if ($_meta) {
			$this->_root->Name($xml[0][1]);

			if ($xml[0][2] != '')
				$this->_root->Attributes(self::DecodeAttributes($xml[0][2]));
		}

		foreach ($xml as $value) {
			$key = $value[1];
			$buffer = null;

			if (sizeof($value) == 8) {
				$buffer = $this->Decode($value[6] . '>', false);

				if (!$buffer)
					$buffer = $this->Decode($value[7], false);

				if (!$buffer) $buffer = $value[7];
			}

			$attributes = self::DecodeAttributes($value[2]);

			if ($attributes !== null) {
				$buffer = new QuarkXMLNode($key, $buffer, $attributes);
				$buffer->Single(!isset($value[7]));
			}

			if (isset($out[$key])) {
				$item = $key;
				
				if (!isset($out[0][$key])) {
					$tmp = is_object($out[$key]) ? clone $out[$key] : $out[$key];
					unset($out[$key]);
					$out[] = $tmp;
				}

				$out[] = $buffer;
			}
			else {
				if (isset($out[0]) && $item != '' && $item == $key) $out[] = $buffer;
				else $out[$key] = $buffer;
			}
		}

		return QuarkObject::isIterative($out) ? $out : (object)$out;
	}

	/**
	 * @param string $data = ''
	 *
	 * @return object
	 */
	public static function DecodeAttributes ($data = '') {
		if (!preg_match_all(self::PATTERN_ATTRIBUTE, $data, $attributes, PREG_SET_ORDER)) return null;

		$out = array();

		foreach ($attributes as $attribute)
			$out[$attribute[1]] = $attribute[2];

		return (object)$out;
	}

	/**
	 * @param string $raw
	 *
	 * @return mixed
	 */
	public function Batch ($raw) { return $raw; }
}

/**
 * Class QuarkXMLNode
 *
 * @package Quark
 */
class QuarkXMLNode {
	/**
	 * @var string $_name = ''
	 */
	private $_name = '';

	/**
	 * @var array $_attributes = []
	 */
	private $_attributes = array();

	/**
	 * @var bool $_single = false
	 */
	private $_single = false;
	
	/**
	 * @var $_data = null
	 */
	private $_data = null;

	/**
	 * @param $key
	 *
	 * @return mixed
	 */
	public function &__get ($key) {
		return $this->_data->$key;
	}

	/**
	 * @param $key
	 * @param $value
	 */
	public function __set ($key, $value) {
		$this->_data->$key = $value;
	}

	/**
	 * @param $key
	 *
	 * @return bool
	 */
	public function __isset ($key) {
		return isset($this->_data->$key);
	}

	/**
	 * @param $key
	 */
	public function __unset ($key) {
		unset($this->_data->$key);
	}

	/**
	 * @param string $name = ''
	 * @param $data = []
	 * @param array|object $attributes = []
	 * @param bool $single = false
	 */
	public function __construct ($name = '', $data = [], $attributes = [], $single = false) {
		$this->_name = $name;
		$this->_data = is_scalar($data) ? $data : (object)$data;
		$this->_attributes = (object)$attributes;
		$this->_single = $single;
	}

	/**
	 * @param string $name = ''
	 *
	 * @return string
	 */
	public function Name ($name = '') {
		if (func_num_args() != 0)
			$this->_name = $name;

		return $this->_name;
	}
	
	/**
	 * @param $data
	 *
	 * @return mixed
	 */
	public function Data ($data = []) {
		if (func_num_args() != 0)
			$this->_data = (object)$data;
		
		return $this->_data;
	}

	/**
	 * @param array|object $attributes
	 *
	 * @return array|object
	 */
	public function Attributes ($attributes = []) {
		if (func_num_args() != 0)
			$this->_attributes = (object)$attributes;

		return $this->_attributes;
	}
	
	/**
	 * @param string $key
	 * @param string $value = ''
	 *
	 * @return mixed
	 */
	public function Attribute ($key, $value = '') {
		if (func_num_args() == 2)
			$this->_attributes->$key = $value;
		
		return isset($this->_attributes->$key) ? $this->_attributes->$key : null;
	}

	/**
	 * @param bool $single = false
	 *
	 * @return bool
	 */
	public function Single ($single = false) {
		if (func_num_args() != 0)
			$this->_single = $single;
		
		return $this->_single;
	}

	/**
	 * @param QuarkXMLIOProcessor $processor
	 * @param string $node
	 *
	 * @return string
	 */
	public function ToXML (QuarkXMLIOProcessor $processor, $node = '') {
		$attributes = '';
		$node = $this->_name == '' ? $node : $this->_name;

		foreach ($this->_attributes as $key => $value)
			if ($value !== null || ($value === null && $processor->ForceNull()))
				$attributes .= ' ' . $key . '="'. $value . '"';

		return $this->_single
			? ('<' . $node . $attributes . ' />')
			: ('<' . $node . $attributes . '>' . $processor->Encode($this->_data) . '</' . $node . '>');
	}

	/**
	 * @param \SimpleXMLElement $xml
	 * @param array $out
	 * 
	 * @return QuarkXMLNode|object
	 */
	public static function FromXMLElement (\SimpleXMLElement $xml, $out) {
		if (sizeof($xml->attributes()) == 0) return (object)$out;
		
		$attributes = array();
			
		foreach ($xml->attributes() as $key => $value)
			$attributes[$key] = (string)$value;
		
		return new self($xml->getName(), $out, $attributes);
	}
}

/**
 * Class QuarkWDDXIOProcessor
 *
 * @package Quark
 */
class QuarkWDDXIOProcessor implements IQuarkIOProcessor {
	const MIME = 'text/xml';

	/**
	 * @return string
	 */
	public function MimeType () { return self::MIME; }

	/**
	 * @param $raw
	 *
	 * @return mixed
	 */
	public function Decode ($raw) {
		return \wddx_deserialize($raw);
	}

	/**
	 * @param $data
	 *
	 * @return string
	 */
	public function Encode ($data) {
		return \wddx_serialize_value($data);
	}

	/**
	 * @param string $raw
	 *
	 * @return mixed
	 */
	public function Batch ($raw) { return $raw; }
}

/**
 * Class QuarkINIIOProcessor
 *
 * @package Quark
 */
class QuarkINIIOProcessor implements IQuarkIOProcessor {
	const PATTERN_BLOCK = '#\[([^\n]*)\][\s\n]*(([^\n]*\s?\=\s?[^\n]*[\s\n]*)*)#is';
	const PATTERN_PAIR = '#([^\n]*)\s?\=\s?([^\n]*)\n#Ui';
	const PATTERN_COMMENT = '#\n[\;\#](.*)\n|(.*\=\s*\"(.*)\")\s*[\;\#]\s*(.*)\n|\n\[(.*)\]\s*[\;\#](.*)\n#UiS';

	const MIME = 'plain/text';

	/**
	 * @var bool $_cast = true
	 */
	private $_cast = true;

	/**
	 * @var string[] $_comments = []
	 */
	private $_comments = array();
	
	/**
	 * @param bool $cast = true
	 */
	public function __construct ($cast = true) {
		$this->Cast($cast);
	}

	/**
	 * @param bool $cast = true
	 *
	 * @return bool
	 */
	public function Cast ($cast = true) {
		if (func_num_args() != 0)
			$this->_cast = $cast;
		
		return $this->_cast;
	}

	/**
	 * @return string[]
	 */
	public function Comments () {
		return $this->_comments;
	}

	/**
	 * @return string
	 */
	public function MimeType () { return self::MIME; }
	
	/**
	 * @param $data
	 *
	 * @return string
	 */
	public function Encode ($data) {
		if (!QuarkObject::isTraversable($data)) {
			Quark::Log('[QuarkINIIOProcessor::Encode] Provided $data argument is not an object or array. Cannot encode. Data (' . gettype($data) . '): ' . $data, Quark::LOG_WARN);
			return null;
		}

		$out = '';
		
		foreach ($data as $name => $section) {
			if (!is_array($section) && !is_object($section)) {
				$out .= $name . ' = ' . QuarkObject::Stringify($section) . "\r\n";
				continue;
			}
			
			$out .= '[' . $name . ']' . "\r\n";
			
			foreach ($section as $key => $value)
				$out .= $key . ' = ' . QuarkObject::Stringify($value) . "\r\n";
			
			$out .= "\r\n";
		}
		
		return $out;
	}

	/**
	 * @param $raw
	 *
	 * @return mixed
	 */
	public function Decode ($raw) {
		if (!is_string($raw)) {
			Quark::Log('[QuarkINIIOProcessor::Decode] Provided $raw argument is not a string. Cannot decode. Raw (' . gettype($raw) . '): ' . print_r($raw, true), Quark::LOG_WARN);
			return null;
		}

		$raw = preg_replace_callback(self::PATTERN_COMMENT, function ($item) {
			$size = sizeof($item);
			$this->_comments[] = trim($item[$size - 1]);

			if ($size == 7) return '[' . $item[5] . ']';
			if ($size == 5) return $item[2];

			return '';
		}, "\n" . str_replace("\n", "\n\n", $raw) . "\n");

		if (!preg_match_all(self::PATTERN_BLOCK, $raw, $ini, PREG_SET_ORDER)) return null;

		$out = array();

		foreach ($ini as $value)
			$out[$value[1]] = self::DecodePairs($value[2], $this->_cast);

		return QuarkObject::Merge($out);
	}

	/**
	 * @param string $raw = ''
	 * @param bool $cast = true
	 *
	 * @return mixed
	 */
	public static function DecodePairs ($raw = '', $cast = true) {
		if (!preg_match_all(self::PATTERN_PAIR, $raw, $pairs, PREG_SET_ORDER)) return null;

		$out = array();

		foreach ($pairs as $pair) {
			$value = trim($pair[2]);

			if ($cast) {
				if (strtolower($value) == 'true') $value = true;
				if (strtolower($value) == 'false') $value = false;
				if (strtolower($value) == 'null') $value = null;
				if (QuarkField::Int($value)) $value = (int)$value;
				if (QuarkField::Float($value)) $value = (float)$value;
			}

			if (is_string($value))
				$value = preg_replace('#\\\(.)#Ui', '$1', preg_replace('#^\"(.*)\"$#i', '$1', $value));

			$out[trim($pair[1])] = $value;
		}
		
		return QuarkObject::Merge($out);
	}

	/**
	 * @param string $raw
	 *
	 * @return mixed
	 */
	public function Batch ($raw) { return $raw; }
}

/**
 * Interface IQuarkIOFilter
 *
 * @package Quark
 */
interface IQuarkIOFilter {
	/**
	 * @param QuarkDTO $input
	 * @param QuarkSession $session
	 *
	 * @return QuarkDTO
	 */
	public function FilterInput(QuarkDTO $input, QuarkSession $session);

	/**
	 * @param QuarkDTO $output
	 * @param QuarkSession $session
	 *
	 * @return QuarkDTO
	 */
	public function FilterOutput(QuarkDTO $output, QuarkSession $session);
}

/**
 * Class QuarkXSSFilter
 *
 * @package Quark
 */
class QuarkXSSFilter implements IQuarkIOFilter {
	/**
	 * @param QuarkDTO $input
	 * @param QuarkSession $session
	 *
	 * @return QuarkDTO
	 */
	public function FilterInput (QuarkDTO $input, QuarkSession $session) {
		$data = $input->Data();

		QuarkObject::Walk($data, function (&$key, &$value) {
			$key = strip_tags($key);
			$value = strip_tags($value);
		});

		$input->Data($data);

		return $input;
	}

	/**
	 * @param QuarkDTO $output
	 * @param QuarkSession $session
	 *
	 * @return QuarkDTO
	 */
	public function FilterOutput (QuarkDTO $output, QuarkSession $session) {
		return $output;
	}
}

/**
 * Class QuarkCertificate
 *
 * @package Quark
 */
class QuarkCertificate extends QuarkFile {
	const CONFIG_OPENSSL_CONF = 'OPENSSL_CONF';
	const CONFIG_SSLEAY_CONF = 'SSLEAY_CONF';
	
	const ALGO_SHA512 = 'sha512';
	
	const DEFAULT_BITS = 2048;
	
	/**
	 * @var string[] $_allowed
	 */
	private static $_allowed = array(
		'countryName',
		'stateOrProvinceName',
		'localityName',
		'organizationName',
		'organizationalUnitName',
		'commonName',
		'emailAddress'
	);
	
	/**
	 * @return string[]
	 */
	public static function AllowedDataKeys () {
		return self::$_allowed;
	}
	
	/**
	 * @var string $countryName = ''
	 */
	public $countryName = '';
	
	/**
	 * @var string $stateOrProvinceName = ''
	 */
	public $stateOrProvinceName = '';
	
	/**
	 * @var string $localityName = ''
	 */
	public $localityName = '';
	
	/**
	 * @var string $organizationName = ''
	 */
	public $organizationName = '';
	
	/**
	 * @var string $organizationalUnitName = ''
	 */
	public $organizationalUnitName = '';
	
	/**
	 * @var string $commonName = ''
	 */
	public $commonName = '';
	
	/**
	 * @var string $emailAddress = ''
	 */
	public $emailAddress = '';

	/**
	 * @var string $_passphrase = ''
	 */
	private $_passphrase = '';
	
	/**
	 * @var string $_locationConfig = ''
	 */
	private $_locationConfig = '';

	/**
	 * @var string $_error = ''
	 */
	private $_error = '';

	/**
	 * @param string $location
	 * @param string $passphrase
	 */
	public function __construct ($location = '', $passphrase = '') {
		parent::__construct($location);
		$this->Passphrase($passphrase);
	}

	/**
	 * @param string $passphrase
	 *
	 * @return string
	 */
	public function Passphrase ($passphrase = '') {
		if (func_num_args() == 1)
			$this->_passphrase = $passphrase;

		return $this->_passphrase;
	}
	
	/**
	 * @param string $location = ''
	 *
	 * @return string
	 */
	public function LocationConfig ($location = '') {
		if (func_num_args() != 0)
			$this->_locationConfig = $location;
		
		return $this->_locationConfig;
	}

	/**
	 * @return string
	 */
	public function Error () {
		return $this->_error;
	}

	/**
	 * http://stackoverflow.com/a/31984753/2097055
	 * http://php.net/manual/en/function.openssl-csr-new.php#93618
	 *
	 * @param int $days = QuarkDateInterval::DAYS_IN_YEAR
	 * @param string $algo = self::ALGO_SHA512
	 * @param int $bits = self::DEFAULT_BITS
	 * @param int $type = OPENSSL_KEYTYPE_RSA
	 *
	 * @return bool
	 */
	public function Generate ($days = QuarkDateInterval::DAYS_IN_YEAR, $algo = self::ALGO_SHA512, $bits = self::DEFAULT_BITS, $type = OPENSSL_KEYTYPE_RSA) {
		$data = array();
		$pem = array();
		$ok = true;
		
		foreach ($this as $key => $value)
			if (in_array($key, self::$_allowed, true)) $data[$key] = $value;
		
		$config = array(
			'config' => Quark::EnvVar(
				array(self::CONFIG_OPENSSL_CONF, self::CONFIG_SSLEAY_CONF),
				Quark::Config()->OpenSSLConfig()
			),
			'digest_alg' => $algo,
			'x509_extensions' => 'v3_ca',
			'req_extensions'   => 'v3_req',
			'private_key_bits' => (int)$bits,
			'private_key_type' => $type,
			'encrypt_key' => true
		);
		
		$key = openssl_pkey_new();
		$cert = @openssl_csr_new($data, $key, $config);
		$cert = @openssl_csr_sign($cert, null, $key, $days, $config);

		$ok &= @openssl_x509_export($cert, $pem[0]);
		$ok &= @openssl_pkey_export($key, $pem[1], $this->_passphrase, $config);

		$this->_error = openssl_error_string();
		$this->_content = implode($pem);

		return $ok;
	}
}

/**
 * Class QuarkCertificateConfiguration
 *
 * @package Quark
 */
class QuarkCertificateConfiguration implements IQuarkConfiguration {
	/**
	 * @var string $_countryName = ''
	 */
	private $_countryName = '';
	
	/**
	 * @var string $_stateOrProvinceName = ''
	 */
	private $_stateOrProvinceName = '';
	
	/**
	 * @var string $_localityName = ''
	 */
	private $_localityName = '';
	
	/**
	 * @var string $_organizationName = ''
	 */
	private $_organizationName = '';
	
	/**
	 * @var string $_organizationalUnitName = ''
	 */
	private $_organizationalUnitName = '';
	
	/**
	 * @var string $_commonName = ''
	 */
	private $_commonName = '';
	
	/**
	 * @var string $_emailAddress = ''
	 */
	private $_emailAddress = '';
	
	/**
	 * @param string $country = ''
	 *
	 * @return string
	 */
	public function CountryName ($country = '') {
		if (func_num_args() != 0)
			$this->_countryName = $country;
		
		return $this->_countryName;
	}
	
	/**
	 * @param string $state = ''
	 *
	 * @return string
	 */
	public function StateOrProvinceName ($state = '') {
		if (func_num_args() != 0)
			$this->_stateOrProvinceName = $state;
		
		return $this->_stateOrProvinceName;
	}
	
	/**
	 * @param string $locality = ''
	 *
	 * @return string
	 */
	public function LocalityName ($locality = '') {
		if (func_num_args() != 0)
			$this->_localityName = $locality;
		
		return $this->_localityName;
	}
	
	/**
	 * @param string $organization = ''
	 *
	 * @return string
	 */
	public function OrganizationName ($organization = '') {
		if (func_num_args() != 0)
			$this->_organizationName = $organization;
		
		return $this->_organizationName;
	}
	
	/**
	 * @param string $state = ''
	 *
	 * @return string
	 */
	public function OrganizationalUnitName ($state = '') {
		if (func_num_args() != 0)
			$this->_organizationalUnitName = $state;
		
		return $this->_organizationalUnitName;
	}
	
	/**
	 * @param string $name = ''
	 *
	 * @return string
	 */
	public function CommonName ($name = '') {
		if (func_num_args() != 0)
			$this->_commonName = $name;
		
		return $this->_commonName;
	}
	
	/**
	 * @param string $email = ''
	 *
	 * @return string
	 */
	public function EmailAddress ($email = '') {
		if (func_num_args() != 0)
			$this->_emailAddress = $email;
		
		return $this->_emailAddress;
	}
	
	/**
	 * @param string $key
	 * @param object $ini
	 *
	 * @return void
	 */
	public function ConfigurationReady ($key, $ini) {
		// TODO: Implement ConfigurationReady() method.
	}
	
	/**
	 * @param string $passphrase = ''
	 *
	 * @return QuarkCertificate
	 */
	public function Certificate ($passphrase = '') {
		$certificate = new QuarkCertificate('', $passphrase);
		
		$certificate->countryName = $this->_countryName;
		$certificate->stateOrProvinceName = $this->_stateOrProvinceName;
		$certificate->localityName = $this->_localityName;
		$certificate->organizationName = $this->_organizationName;
		$certificate->organizationalUnitName = $this->_organizationalUnitName;
		$certificate->commonName = $this->_commonName;
		$certificate->emailAddress = $this->_emailAddress;
		
		return $certificate;
	}
}

/**
 * Class QuarkCipher
 *
 * @package Quark
 */
class QuarkCipher {
	const HASH_MD5 = '1';
	const HASH_BLOW_FISH = '2';
	const HASH_EKS_BLOW_FISH = '2a';
	const HASH_SHA256 = '5';
	const HASH_SHA512 = '6';
	
	/**
	 * @var IQuarkEncryptionProtocol $_protocol
	 */
	private $_protocol;
	
	/**
	 * @param IQuarkEncryptionProtocol $protocol = null
	 */
	public function __construct (IQuarkEncryptionProtocol $protocol = null) {
		$this->Protocol($protocol);
	}
	
	/**
	 * @param IQuarkEncryptionProtocol $protocol = null
	 *
	 * @return IQuarkEncryptionProtocol
	 */
	public function &Protocol (IQuarkEncryptionProtocol $protocol = null) {
		if (func_num_args() != 0)
			$this->_protocol = $protocol;
		
		return $this->_protocol;
	}
	
	/**
	 * @param string $key = ''
	 * @param string $data = ''
	 *
	 * @return string
	 */
	public function Encrypt ($key = '', $data = '') {
		return $this->_protocol == null ? '' : $this->_protocol->Encrypt($key, $data);
	}
	
	/**
	 * @param string $key = ''
	 * @param string $data = ''
	 *
	 * @return string
	 */
	public function Decrypt ($key = '', $data = '') {
		return $this->_protocol == null ? '' : $this->_protocol->Decrypt($key, $data);
	}
	
	/**
	 * http://www.slashroot.in/how-are-passwords-stored-linux-understanding-hashing-shadow-utils
	 * https://ubuntuforums.org/showthread.php?t=1169551&p=7348429#post7348429
	 *
	 * @param string $password
	 * @param string $salt
	 * @param string $hash = self::HASH_SHA512
	 *
	 * @return string
	 */
	public static function UnixPassword ($password = '', $salt = '', $hash = self::HASH_SHA512) {
		return crypt($password, '$' . $hash . '$' . $salt . '$');
	}
	
	/**
	 * @param int $chars = 8
	 * @param string $alphabet = Quark::ALPHABET_PASSWORD
	 *
	 * @return string
	 */
	public static function UnixPasswordSalt ($chars = 8, $alphabet = Quark::ALPHABET_PASSWORD) {
		return Quark::GenerateByPattern('\c{' . $chars . '}', $alphabet);
	}
}

/**
 * Interface IQuarkEncryptionProtocol
 *
 * @package Quark
 */
interface IQuarkEncryptionProtocol {
	/**
	 * @param string $key
	 * @param string $data
	 *
	 * @return string
	 */
	public function Encrypt($key, $data);

	/**
	 * @param string $key
	 * @param string $data
	 *
	 * @return string
	 */
	public function Decrypt($key, $data);
}

/**
 * Class QuarkOpenSSLCipher
 *
 * @package Quark
 */
class QuarkOpenSSLCipher implements IQuarkEncryptionProtocol {
	const CIPHER_AES_256 = 'aes-256-cbc';

	/**
	 * @var string $_iv = ''
	 */
	private $_iv = '';

	/**
	 * @var string $_algorithm = self::CIPHER_AES_256
	 */
	private $_algorithm = self::CIPHER_AES_256;

	/**
	 * @param string $iv = ''
	 * @param string $algorithm = self::CIPHER_AES_256
	 */
	public function __construct ($iv = '', $algorithm = self::CIPHER_AES_256) {
		$this->InitializationVector($iv);
		$this->Algorithm($algorithm);
	}
	
	/**
	 * @param string $key
	 * @param string $data
	 *
	 * @return string
	 */
	public function Encrypt ($key, $data) {
		return base64_encode(openssl_encrypt($data, $this->_algorithm, $key, OPENSSL_RAW_DATA, $this->_iv()));
	}

	/**
	 * @param string $key
	 * @param string $data
	 *
	 * @return string
	 */
	public function Decrypt ($key, $data) {
		return openssl_decrypt(base64_decode($data), $this->_algorithm, $key, OPENSSL_RAW_DATA, $this->_iv());
	}

	/**
	 * @param string $iv = ''
	 *
	 * @return string
	 */
	public function InitializationVector ($iv = '') {
		if (func_num_args() != 0)
			$this->_iv = $iv;

		return $this->_iv;
	}

	/**
	 * @param string $algorithm = self::CIPHER_AES_256
	 *
	 * @return string
	 */
	public function Algorithm ($algorithm = self::CIPHER_AES_256) {
		if (func_num_args() != 0)
			$this->_algorithm = $algorithm;
		
		return $this->_algorithm;
	}

	/**
	 * @return string
	 */
	private function _iv () {
		return substr(hash('sha256', $this->_iv), 0, 16);
	}
}

/**
 * Class QuarkSQL
 *
 * @package Quark
 */
class QuarkSQL {
	const OPTION_AS = 'option.as';
	const OPTION_SCHEMA_GENERATE_PRINT = 'option.schema_print';
	const OPTION_QUERY_TEST = 'option.query.test';
	const OPTION_QUERY_DEBUG = 'option.query.debug';
	const OPTION_QUERY_REVIEWER = 'option.query.reviewer';
	const OPTION_FIELDS = '__sql_fields__';

	const FIELD_COUNT_ALL = 'COUNT(*)';

	const NULL = 'NULL';

	/**
	 * @var IQuarkSQLDataProvider $_provider
	 */
	private $_provider;

	/**
	 * @param $path
	 *
	 * @return string
	 */
	public static function DBName ($path) {
		return !is_string($path) || strlen($path) == 0 ? '' : ($path[0] == '/' ? substr($path, 1) : $path);
	}

	/**
	 * @param string $connection
	 *
	 * @return IQuarkSQLDataProvider
	 */
	private static function _source ($connection) {
		$source = Quark::Component($connection);

		if (!($source instanceof QuarkModelSource)) return null;

		$provider = $source->Connect()->Provider();

		return $provider instanceof IQuarkSQLDataProvider ? $provider : null;
	}

	/**
	 * @param string $connection
	 * @param string $query
	 * @param array $options = []
	 *
	 * @return bool|mixed
	 */
	public static function Command ($connection, $query = '', $options = []) {
		$provider = self::_source($connection);

		return $provider ? $provider->Query($query, $options) : false;
	}

	/**
	 * @param string $connection
	 * @param string $table
	 *
	 * @return QuarkField[]|bool
	 */
	public static function Schema ($connection, $table) {
		$provider = self::_source($connection);

		return $provider ? $provider->Schema($table) : false;
	}

	/**
	 * @param IQuarkSQLDataProvider $provider
	 */
	public function __construct (IQuarkSQLDataProvider $provider) {
		$this->_provider = $provider;
	}

	/**
	 * @param $model
	 * @param $options
	 * @param $query
	 * @param bool $test = false
	 *
	 * @return mixed
	 */
	public function Query ($model, $options, $query, $test = false) {
		$i = 1;
		$query = str_replace(
			self::Collection($model),
			$this->_provider->EscapeCollection(QuarkModel::CollectionName($model, $options)),
			$query,
			$i
		);

		if (!isset($options[self::OPTION_QUERY_TEST]))
			$options[self::OPTION_QUERY_TEST] = false;
		
		if (isset($options[self::OPTION_QUERY_REVIEWER])) {
			$reviewer = $options[self::OPTION_QUERY_REVIEWER];
			$query = is_callable($reviewer) ? $reviewer($query) : $query;
		}
		
		$out = $test || $options[self::OPTION_QUERY_TEST]
			? $query
			: $this->_provider->Query($query, $options);

		if (isset($options[self::OPTION_QUERY_DEBUG]) && $options[self::OPTION_QUERY_DEBUG])
			Quark::Log('[QuarkSQL] Query: "' . $query . '"');

		return $out;
	}

	/**
	 * @param $model
	 *
	 * @return string
	 */
	public static function Collection ($model) {
		return '{collection_' . sha1(print_r($model, true)) . '}';
	}

	/**
	 * @param IQuarkModel $model
	 * @param $key = 'id'
	 * @param $value = 0
	 *
	 * @return QuarkKeyValuePair
	 */
	public function Pk (IQuarkModel $model, $key = 'id', $value = 0) {
		return new QuarkKeyValuePair(
			$model instanceof IQuarkModelWithCustomPrimaryKey ? $model->PrimaryKey() : $key,
			$value
		);
	}

	/**
	 * @param string $field
	 *
	 * @return string
	 */
	public function Field ($field) {
		return is_string($field)
			? $this->_provider->EscapeField($field)
			: '';
	}

	/**
	 * @param $type
	 *
	 * @return string
	 */
	public function FieldTypeFromProvider ($type) {
		return $this->_provider->FieldTypeFromProvider($type);
	}

	/**
	 * @param $field
	 *
	 * @return string
	 */
	public function FieldTypeFromModel ($field) {
		return $this->_provider->FieldTypeFromModel($field);
	}

	/**
	 * @param $value
	 *
	 * @return bool|float|int|string
	 */
	public function Value ($value) {
		if ($value === null) return self::NULL;
		if (!is_scalar($value)) return null;
		if (is_bool($value))
			$value = $value ? 1 : 0;

		$output = $this->_provider->EscapeValue($value);

		return is_string($value) ? '\'' . $output . '\'' : $output;
	}

	/**
	 * @param        $condition
	 * @param string $glue
	 *
	 * @return string
	 */
	public function Condition ($condition, $glue = '') {
		if (!is_array($condition) || sizeof($condition) == 0) return '';

		$output = array();

		foreach ($condition as $key => $rule) {
			$field = $this->Field($key);
			$value = $this->Value($rule);

			if (is_array($rule))
				$value = $this->Condition($rule, ' AND ');

			switch ($field) {
				case '`$lte`': $output[] = '<=' . $value; break;
				case '`$lt`': $output[] = '<' . $value; break;
				case '`$gt`': $output[] = '>' . $value; break;
				case '`$gte`': $output[] = '>=' . $value; break;
				case '`$ne`': $output[] = ($value == self::NULL ? ' IS NOT ' : '<>') . $value; break;

				case '`$and`':
					$value = $this->Condition($rule, ' AND ');
					$output[] = ' (' . $value . ') ';
					break;

				case '`$or`':
					$value = $this->Condition($rule, ' OR ');
					$output[] = ' (' . $value . ') ';
					break;

				case '`$nor`':
					$value = $this->Condition($rule, ' NOT OR ');
					$output[] = ' (' . $value . ') ';
					break;

				default:
					$output[] = (is_string($key) ? $field : '') . (is_scalar($rule) ? '=' : ($value == self::NULL ? ' IS ' : '')) . $value;
					break;
			}
		}

		return ($glue == '' ? ' WHERE ' : '') . implode($glue == '' ? ' AND ' : $glue, $output);
	}

	/**
	 * @param $options
	 *
	 * @return string
	 */
	private function _cursor ($options) {
		$output = '';

		if (isset($options[QuarkModel::OPTION_SORT]) && is_array($options[QuarkModel::OPTION_SORT])) {
			$output .= ' ORDER BY ';

			foreach ($options[QuarkModel::OPTION_SORT] as $key => $order) {
				switch ($order) {
					case 1: $sort = 'ASC'; break;
					case -1: $sort = 'DESC'; break;
					default: $sort = ''; break;
				}

				$output .= $this->Field($key) . ' ' . $sort . ',';
			}

			$output = trim($output, ',');
		}

		if (isset($options[QuarkModel::OPTION_LIMIT]))
			$output .= ' LIMIT ' . $this->_provider->EscapeValue($options[QuarkModel::OPTION_LIMIT]);

		if (isset($options[QuarkModel::OPTION_SKIP]))
			$output .= ' OFFSET ' . $this->_provider->EscapeValue($options[QuarkModel::OPTION_SKIP]);

		return $output;
	}

	/**
	 * @param IQuarkModel $model
	 * @param $criteria
	 * @param array $options
	 *
	 * @return mixed
	 */
	public function Select (IQuarkModel $model, $criteria, $options = []) {
		if (isset($options[self::OPTION_FIELDS])) $fields = $options[self::OPTION_FIELDS];
		else {
			$fields = '*';

			if (isset($options[QuarkModel::OPTION_FIELDS]) && is_array($options[QuarkModel::OPTION_FIELDS])) {
				$fields = '';
				$count = sizeof($options[QuarkModel::OPTION_FIELDS]);
				$i = 1;

				foreach ($options[QuarkModel::OPTION_FIELDS] as $field) {
					switch ($field) {
						case self::FIELD_COUNT_ALL:
							$key = $field;
							break;

						default:
							$key = $this->Field($field);
							break;
					}

					$fields = $key . ($i == $count || !$key ? '' : ', ');
					$i++;
				}
			}
		}

		return $this->Query(
			$model,
			$options,
			'SELECT ' . $fields . (isset($options[self::OPTION_AS]) ? ' AS ' . $options[self::OPTION_AS] : '') . ' FROM ' . self::Collection($model) . $this->Condition($criteria) . $this->_cursor($options)
		);
	}

	/**
	 * @param IQuarkModel $model
	 * @param array       $options
	 *
	 * @return mixed
	 */
	public function Insert (IQuarkModel $model, $options = []) {
		$keys = array();
		$values = array();

		foreach ($model as $key => $value) {
			$keys[] = $this->Field($key);
			$values[] = $this->Value($value);
		}

		return $this->Query(
			$model,
			$options,
			/** @lang text */
			'INSERT INTO ' . self::Collection($model)
			. ' (' . implode(', ', $keys) . ') '
			. 'VALUES (' . implode(', ', $values) . ')'
		);
	}

	/**
	 * @param IQuarkModel $model
	 * @param             $criteria
	 * @param array       $options
	 *
	 * @return mixed
	 */
	public function Update (IQuarkModel $model, $criteria, $options = []) {
		$fields = array();

		foreach ($model as $key => $value)
			$fields[] = $this->Field($key) . '=' . $this->Value($value);

		return $this->Query(
			$model,
			$options,
			'UPDATE ' . self::Collection($model) . ' SET ' . implode(', ', $fields) . $this->Condition($criteria) . $this->_cursor($options)
		);
	}

	/**
	 * @param IQuarkModel $model
	 * @param             $criteria
	 * @param             $options
	 *
	 * @return mixed
	 */
	public function Delete (IQuarkModel $model, $criteria, $options) {
		return $this->Query(
			$model,
			$options,
			'DELETE FROM ' . self::Collection($model) . $this->Condition($criteria) . $this->_cursor($options)
		);
	}

	/**
	 * @param IQuarkModel $model
	 * @param             $criteria
	 * @param array       $options
	 *
	 * @return mixed
	 */
	public function Count (IQuarkModel $model, $criteria, $options = []) {
		return $this->Select($model, $criteria, array_merge($options, array(
			'fields' => array(self::FIELD_COUNT_ALL)
		)));
	}
}

/**
 * Interface IQuarkSQLDataProvider
 *
 * @package Quark
 */
interface IQuarkSQLDataProvider {
	/**
	 * @param string $query
	 * @param array $options
	 *
	 * @return mixed
	 */
	public function Query($query, $options);

	/**
	 * @param string $name
	 *
	 * @return string
	 */
	public function EscapeCollection($name);

	/**
	 * @param string $field
	 *
	 * @return string
	 */
	public function EscapeField($field);

	/**
	 * @param string $value
	 *
	 * @return string
	 */
	public function EscapeValue($value);

	/**
	 * @param $type
	 *
	 * @return string
	 */
	public function FieldTypeFromProvider($type);
	
	/**
	 * @param $field
	 *
	 * @return string
	 */
	public function FieldTypeFromModel($field);

	/**
	 * @param string $table
	 *
	 * @return QuarkField[]
	 */
	public function Schema($table);
	
	/**
	 * @param IQuarkModel $model
	 * @param array $options = []
	 *
	 * @return mixed
	 */
	public function GenerateSchema(IQuarkModel $model, $options = []);
}

/**
 * Class QuarkSource
 *
 * @package Quark
 */
class QuarkSource extends QuarkFile {
	/**
	 * @var string[] $_trim = []
	 */
	private $_trim = array();

	/**
	 * @var string[] $__trim
	 */
	private static $__trim = array(
		',',';','?',':',
		'(',')','{','}','[',']',
		'+','*','/',
		'>','<','>=','<=','!=','==',
		'=','=>','->',
		'&&', '||'
	);

	/**
	 * @param string[] $trim = []
	 *
	 * @return string[]
	 */
	public function Trim ($trim = []) {
		if (func_num_args() != 0)
			$this->_trim = $trim;

		return $this->_trim;
	}

	/**
	 * @return QuarkSource
	 */
	public function Obfuscate () {
		$this->_content = self::ObfuscateString($this->_content, $this->_trim);

		return $this;
	}

	/**
	 * @param string $source = ''
	 * @param string[] $trim = []
	 *
	 * @return string
	 */
	public static function ObfuscateString ($source = '', $trim = []) {
		$trim = func_num_args() == 3 ? $trim : self::$__trim;
		$slash = ':\\\\' . Quark::GuID() . '\\\\';

		$source = str_replace('://', $slash, $source);
		$source = preg_replace('#\/\/(.*)\\n#Uis', '', $source);
		$source = str_replace($slash, '://', $source);
		$source = preg_replace('#\/\*(.*)\*\/#Uis', '', $source);
		$source = str_replace("\r\n", '', $source);
		$source = preg_replace('/\s+/', ' ', $source);
		$source = trim(str_replace('<?phpn', '<?php n', $source));

		foreach ($trim as $rule) {
			$source = str_replace(' ' . $rule . ' ', $rule, $source);
			$source = str_replace(' ' . $rule, $rule, $source);
			$source = str_replace($rule . ' ', $rule, $source);
		}

		return $source;
	}
}

Quark::_init();