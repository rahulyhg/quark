<?php
namespace Quark\Extensions\SSLAuthority;

use Quark\IQuarkExtension;

use Quark\Quark;
use Quark\QuarkCertificate;

/**
 * Class SSLAuthority
 *
 * @package Quark\Extensions\SSLAuthority
 */
class SSLAuthority implements IQuarkExtension {
	/**
	 * @var SSLAuthorityConfig $_config
	 */
	private $_config;

	/**
	 * @param string $config
	 */
	public function __construct ($config) {
		$this->_config = Quark::Config()->Extension($config);
	}

	/**
	 * @param QuarkCertificate $certificate = null
	 *
	 * @return QuarkCertificate
	 */
	public function CertificateRequest (QuarkCertificate $certificate = null) {
		if ($certificate == null) return null;

		$out = $this->_config->Provider()->SSLAuthorityCertificateRequest($certificate);

		return $out;
	}

	/**
	 * @param string $csr
	 * @param resource $key
	 * @param string $altName
	 * @param string $passphrase = null
	 *
	 * @return QuarkCertificate
	 */
	public function CertificateRequestRaw ($csr, $key, $altName, $passphrase = null) {
		$out = $this->_config->Provider()->SSLAuthorityCertificateRequestRaw($csr, $key, $altName, $passphrase);

		return $out;
	}

	/**
	 * @param QuarkCertificate $certificate = null
	 *
	 * @return QuarkCertificate
	 */
	public function CertificateRenew (QuarkCertificate $certificate = null) {
		if ($certificate == null) return null;

		$out = $this->_config->Provider()->SSLAuthorityCertificateRenew($certificate);

		return $out;
	}
}