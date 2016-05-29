<?php
namespace Quark\Extensions\PushNotification;

/**
 * Interface IQuarkPushNotificationProvider
 */
interface IQuarkPushNotificationProvider {
	/**
	 * @return string
	 */
	public function Type();

	/**
	 * @param $config
	 */
	public function Config($config);

	/**
	 * @param Device $device
	 */
	public function Device(Device $device);

	/**
	 * @return Device[]
	 */
	public function Devices();

	/**
	 * @param object|array $payload
	 * @param array $options
	 *
	 * @return mixed
	 */
	public function Send($payload, $options = []);

	/**
	 * @return mixed
	 */
	public function Reset();
}